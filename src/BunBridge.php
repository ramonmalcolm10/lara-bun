<?php

namespace LaraBun;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use LaraBun\Rsc\CallableRegistry;
use LaraBun\Rsc\RscRedirectException;
use RuntimeException;
use Socket;

class BunBridge
{
    /** @var string[] */
    private array $socketPaths;

    /**
     * Pool of available (idle) sockets per worker index.
     *
     * @var array<int, Socket[]>
     */
    private array $pool = [];

    private int $workerCount;

    private int $currentWorker;

    private int $maxFrameSize;

    public function __construct()
    {
        if (! extension_loaded('sockets')) {
            throw new RuntimeException('The sockets extension is required. Enable it in php.ini.');
        }

        $basePath = config('bun.socket_path', '/tmp/bun-bridge.sock');
        $this->workerCount = max(1, (int) config('bun.workers', 1));
        $this->currentWorker = $this->workerCount > 1 ? random_int(0, $this->workerCount - 1) : 0;
        $this->maxFrameSize = self::parseSize(config('bun.rsc.body_size_limit', '1mb'));

        if ($this->workerCount === 1) {
            $this->socketPaths = [$basePath];
        } else {
            $base = preg_replace('/\.sock$/', '', $basePath);

            for ($i = 0; $i < $this->workerCount; $i++) {
                $this->socketPaths[] = "{$base}-{$i}.sock";
            }
        }
    }

    public function call(string $function, array $args = []): mixed
    {
        $response = $this->send(json_encode([
            'type' => 'call',
            'function' => $function,
            'args' => $args,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun error: {$response['error']}");
        }

        return $response['result'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{head: array<int, string>, body: string}
     */
    public function ssr(array $page): array
    {
        $response = $this->send(json_encode([
            'type' => 'ssr',
            'page' => $page,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun SSR error: {$response['error']}");
        }

        if (! isset($response['result']) || ! is_array($response['result'])) {
            throw new RuntimeException('Invalid SSR response from Bun');
        }

        return $response['result'];
    }

    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{body: string, rscPayload: string, clientChunks: string[], usedDynamicApis?: bool}
     */
    public function rsc(string $component, array $props = [], array $layouts = []): array
    {
        $registry = app(CallableRegistry::class);

        if ($registry->hasCallables()) {
            return $this->rscWithCallbacks($component, $props, $registry, $layouts);
        }

        $response = $this->send(json_encode([
            'type' => 'rsc',
            'component' => $component,
            'props' => $props,
            'layouts' => $layouts,
        ], JSON_THROW_ON_ERROR));

        if (isset($response['error'])) {
            throw new RuntimeException("Bun RSC error: {$response['error']}");
        }

        if (! isset($response['result']) || ! is_array($response['result'])) {
            throw new RuntimeException('Invalid RSC response from Bun');
        }

        return $response['result'];
    }

    /**
     * Stream the raw Flight payload for SPA navigation.
     *
     * Uses a dedicated reverse-connection socket so Bun writes via
     * Bun.connect() which flushes immediately, unlike Bun.listen
     * handler sockets which buffer writes internally.
     *
     * The first yielded value is always an array of browser chunk paths
     * (clientChunks). All subsequent yields are Flight payload strings.
     *
     * @return \Generator<int, string[]|string, void, void>
     */
    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     */
    public function rscStream(string $component, array $props = [], array $layouts = []): \Generator
    {
        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();

        $callbackPath = $hasCallbacks ? '/tmp/rsc-cb-'.bin2hex(random_bytes(8)).'.sock' : null;
        $callbackServer = null;
        $callbackClient = null;

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            if ($hasCallbacks) {
                $callbackServer = $this->createUnixServer($callbackPath);
            }

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-stream',
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts,
                'callbackSocket' => $callbackPath,
            ], JSON_THROW_ON_ERROR));

            $callbackTimeout = (int) config('bun.rsc.callback_timeout', 30);
            $callbackBuffer = '';

            // Accept callback connection if needed
            if ($callbackServer !== null) {
                $read = [$callbackServer];
                $write = [];
                $except = [];

                if (socket_select($read, $write, $except, $callbackTimeout) > 0) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }
            }

            while (true) {
                $read = [$mainSocket];

                if ($callbackServer !== null) {
                    $read[] = $callbackServer;
                }

                if ($callbackClient !== null) {
                    $read[] = $callbackClient;
                }

                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, null);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($callbackServer !== null && in_array($callbackServer, $read, true)) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }

                if (in_array($mainSocket, $read, true)) {
                    $frame = $this->readFrame($mainSocket);

                    $this->throwIfAuthError($frame);

                    if (isset($frame['error'])) {
                        throw new RuntimeException("Bun RSC stream error: {$frame['error']}");
                    }

                    $type = $frame['type'] ?? '';

                    if ($type === 'stream-start') {
                        yield $frame['clientChunks'] ?? [];

                        continue;
                    }

                    if ($type === 'stream-chunk') {
                        yield $frame['data'] ?? '';

                        continue;
                    }

                    if ($type === 'stream-end') {
                        $this->release($index, $mainSocket);
                        $mainSocket = null;

                        break;
                    }
                }

                if ($callbackClient !== null && in_array($callbackClient, $read, true)) {
                    // Drain available stream data before executing callback
                    $streamEnded = false;

                    while (! $streamEnded) {
                        $streamCheck = [$mainSocket];
                        $w = [];
                        $e = [];

                        if (socket_select($streamCheck, $w, $e, 0, 5000) <= 0) {
                            break;
                        }

                        $frame = $this->readFrame($mainSocket);

                        $this->throwIfAuthError($frame);

                        if (isset($frame['error'])) {
                            throw new RuntimeException("Bun RSC stream error: {$frame['error']}");
                        }

                        $type = $frame['type'] ?? '';

                        if ($type === 'stream-start') {
                            yield $frame['clientChunks'] ?? [];
                        } elseif ($type === 'stream-chunk') {
                            yield $frame['data'] ?? '';
                        } elseif ($type === 'stream-end') {
                            $this->release($index, $mainSocket);
                            $mainSocket = null;
                            $streamEnded = true;
                        }
                    }

                    if ($streamEnded) {
                        break;
                    }

                    $this->handleCallbackData($callbackClient, $callbackBuffer, $registry);
                }
            }
        } finally {
            if ($mainSocket !== null) {
                socket_close($mainSocket);
            }

            if ($callbackClient !== null) {
                socket_close($callbackClient);
            }

            if ($callbackServer !== null) {
                socket_close($callbackServer);
            }

            if ($callbackPath !== null && file_exists($callbackPath)) {
                @unlink($callbackPath);
            }
        }
    }

    /**
     * Stream HTML for initial page loads with Suspense support.
     *
     * React renders the shell (with Suspense fallbacks) immediately, then
     * streams completion scripts as async content resolves. The Flight
     * payload for hydration is sent as the final yield.
     *
     * Yields:
     *  1st: array{clientChunks: string[]}
     *  middle: string (HTML chunks)
     *  last: array{rscPayload: string}
     *
     * @return \Generator<int, array{clientChunks?: string[], rscPayload?: string}|string, void, void>
     */
    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     */
    public function rscHtmlStream(string $component, array $props = [], array $layouts = []): \Generator
    {
        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();

        $callbackPath = $hasCallbacks ? '/tmp/rsc-cb-'.bin2hex(random_bytes(8)).'.sock' : null;
        $callbackServer = null;
        $callbackClient = null;

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            if ($hasCallbacks) {
                $callbackServer = $this->createUnixServer($callbackPath);
            }

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-html-stream',
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts,
                'callbackSocket' => $callbackPath,
            ], JSON_THROW_ON_ERROR));

            $callbackTimeout = (int) config('bun.rsc.callback_timeout', 30);
            $callbackBuffer = '';

            // Accept callback connection if needed
            if ($callbackServer !== null) {
                $read = [$callbackServer];
                $write = [];
                $except = [];

                if (socket_select($read, $write, $except, $callbackTimeout) > 0) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }
            }

            while (true) {
                $read = [$mainSocket];

                if ($callbackServer !== null) {
                    $read[] = $callbackServer;
                }

                if ($callbackClient !== null) {
                    $read[] = $callbackClient;
                }

                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, null);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($callbackServer !== null && in_array($callbackServer, $read, true)) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }

                if (in_array($mainSocket, $read, true)) {
                    $frame = $this->readFrame($mainSocket);

                    $this->throwIfAuthError($frame);

                    if (isset($frame['error'])) {
                        throw new RuntimeException("Bun RSC HTML stream error: {$frame['error']}");
                    }

                    $type = $frame['type'] ?? '';

                    if ($type === 'html-start') {
                        yield ['clientChunks' => $frame['clientChunks'] ?? []];

                        continue;
                    }

                    if ($type === 'html-chunk') {
                        yield $frame['data'] ?? '';

                        continue;
                    }

                    if ($type === 'html-end') {
                        yield ['rscPayload' => $frame['rscPayload'] ?? ''];
                        $this->release($index, $mainSocket);
                        $mainSocket = null;

                        break;
                    }
                }

                if ($callbackClient !== null && in_array($callbackClient, $read, true)) {
                    // Drain available stream data before executing callback
                    while (true) {
                        $streamCheck = [$mainSocket];
                        $w = [];
                        $e = [];

                        if (socket_select($streamCheck, $w, $e, 0, 5000) <= 0) {
                            break;
                        }

                        $frame = $this->readFrame($mainSocket);

                        $this->throwIfAuthError($frame);

                        if (isset($frame['error'])) {
                            throw new RuntimeException("Bun RSC HTML stream error: {$frame['error']}");
                        }

                        $type = $frame['type'] ?? '';

                        if ($type === 'html-start') {
                            yield ['clientChunks' => $frame['clientChunks'] ?? []];
                        } elseif ($type === 'html-chunk') {
                            yield $frame['data'] ?? '';
                        } elseif ($type === 'html-end') {
                            yield ['rscPayload' => $frame['rscPayload'] ?? ''];
                            $this->release($index, $mainSocket);
                            $mainSocket = null;

                            break 2;
                        }
                    }

                    $this->handleCallbackData($callbackClient, $callbackBuffer, $registry);
                }
            }
        } finally {
            if ($mainSocket !== null) {
                socket_close($mainSocket);
            }

            if ($callbackClient !== null) {
                socket_close($callbackClient);
            }

            if ($callbackServer !== null) {
                socket_close($callbackServer);
            }

            if ($callbackPath !== null && file_exists($callbackPath)) {
                @unlink($callbackPath);
            }
        }
    }

    /**
     * Execute a server action and stream the Flight result.
     *
     * Same streaming pattern as rscStream() but sends type "rsc-action"
     * with the action ID and encoded arguments body.
     *
     * Yields Flight payload strings (no metadata prefix — action responses
     * don't need clientChunks).
     *
     * @return \Generator<int, string, void, void>
     */
    public function rscAction(string $actionId, string $body, string $contentType = 'text/plain'): \Generator
    {
        $registry = app(CallableRegistry::class);
        $hasCallbacks = $registry->hasCallables();

        $callbackPath = $hasCallbacks ? '/tmp/rsc-cb-'.bin2hex(random_bytes(8)).'.sock' : null;
        $callbackServer = null;
        $callbackClient = null;

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            if ($hasCallbacks) {
                $callbackServer = $this->createUnixServer($callbackPath);
            }

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc-action',
                'actionId' => $actionId,
                'body' => $body,
                'contentType' => $contentType,
                'callbackSocket' => $callbackPath,
            ], JSON_THROW_ON_ERROR));

            $callbackTimeout = (int) config('bun.rsc.callback_timeout', 30);
            $callbackBuffer = '';

            // Accept callback connection if needed
            if ($callbackServer !== null) {
                $read = [$callbackServer];
                $write = [];
                $except = [];

                if (socket_select($read, $write, $except, $callbackTimeout) > 0) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }
            }

            while (true) {
                $read = [$mainSocket];

                if ($callbackServer !== null) {
                    $read[] = $callbackServer;
                }

                if ($callbackClient !== null) {
                    $read[] = $callbackClient;
                }

                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, null);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($callbackServer !== null && in_array($callbackServer, $read, true)) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }

                if (in_array($mainSocket, $read, true)) {
                    $frame = $this->readFrame($mainSocket);

                    if (isset($frame['redirect'])) {
                        throw new RscRedirectException($frame['redirect']);
                    }

                    $this->throwIfAuthError($frame);
                    $this->throwIfValidationError($frame);

                    if (isset($frame['error'])) {
                        throw new RuntimeException("Bun RSC action error: {$frame['error']}");
                    }

                    $type = $frame['type'] ?? '';

                    if ($type === 'action-start') {
                        continue;
                    }

                    if ($type === 'action-chunk') {
                        yield $frame['data'] ?? '';

                        continue;
                    }

                    if ($type === 'action-end') {
                        $this->release($index, $mainSocket);
                        $mainSocket = null;

                        break;
                    }
                }

                if ($callbackClient !== null && in_array($callbackClient, $read, true)) {
                    // Drain available stream data before executing callback
                    $streamEnded = false;

                    while (! $streamEnded) {
                        $streamCheck = [$mainSocket];
                        $w = [];
                        $e = [];

                        if (socket_select($streamCheck, $w, $e, 0, 5000) <= 0) {
                            break;
                        }

                        $frame = $this->readFrame($mainSocket);

                        if (isset($frame['redirect'])) {
                            throw new RscRedirectException($frame['redirect']);
                        }

                        $this->throwIfAuthError($frame);
                        $this->throwIfValidationError($frame);

                        if (isset($frame['error'])) {
                            throw new RuntimeException("Bun RSC action error: {$frame['error']}");
                        }

                        $type = $frame['type'] ?? '';

                        if ($type === 'action-start') {
                            // No-op, already started
                        } elseif ($type === 'action-chunk') {
                            yield $frame['data'] ?? '';
                        } elseif ($type === 'action-end') {
                            $this->release($index, $mainSocket);
                            $mainSocket = null;
                            $streamEnded = true;
                        }
                    }

                    if ($streamEnded) {
                        break;
                    }

                    $this->handleCallbackData($callbackClient, $callbackBuffer, $registry);
                }
            }
        } finally {
            if ($mainSocket !== null) {
                socket_close($mainSocket);
            }

            if ($callbackClient !== null) {
                socket_close($callbackClient);
            }

            if ($callbackServer !== null) {
                socket_close($callbackServer);
            }

            if ($callbackPath !== null && file_exists($callbackPath)) {
                @unlink($callbackPath);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function list(): array
    {
        return $this->send('{"type":"list"}')['result'] ?? [];
    }

    public function ping(): bool
    {
        $anyAlive = false;

        foreach ($this->socketPaths as $i => $path) {
            $socket = $this->checkout($i);

            try {
                $this->writeFrame($socket, '{"type":"ping"}');
                $response = $this->readFrame($socket);
                $this->release($i, $socket);

                if (($response['type'] ?? null) === 'pong') {
                    $anyAlive = true;
                }
            } catch (RuntimeException) {
                socket_close($socket);
            }
        }

        return $anyAlive;
    }

    public function disconnect(): void
    {
        foreach ($this->pool as $index => $sockets) {
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
        }

        $this->pool = [];
    }

    /**
     * Parse a human-readable size string (e.g. '25mb', '512kb') into bytes.
     *
     * Falls back to 1MB if the value is invalid.
     */
    public static function parseSize(string $size): int
    {
        $size = trim($size);

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(kb|mb|gb|b)?$/i', $size, $matches)) {
            $value = (float) $matches[1];
            $unit = strtolower($matches[2] ?? 'b');

            return (int) match ($unit) {
                'kb' => $value * 1024,
                'mb' => $value * 1024 * 1024,
                'gb' => $value * 1024 * 1024 * 1024,
                default => $value,
            };
        }

        return 1024 * 1024;
    }

    /**
     * @param  list<array{component: string, props: array<string, mixed>}>  $layouts
     * @return array{body: string, rscPayload: string, clientChunks: string[], usedDynamicApis?: bool}
     */
    private function rscWithCallbacks(string $component, array $props, CallableRegistry $registry, array $layouts = []): array
    {
        $callbackPath = '/tmp/rsc-cb-'.bin2hex(random_bytes(8)).'.sock';
        $callbackServer = null;
        $callbackClient = null;

        $index = $this->currentWorker++ % $this->workerCount;
        $mainSocket = $this->checkout($index);

        try {
            $callbackServer = socket_create(AF_UNIX, SOCK_STREAM, 0);

            if ($callbackServer === false) {
                throw new RuntimeException('Failed to create callback socket: '.socket_strerror(socket_last_error()));
            }

            if (! socket_bind($callbackServer, $callbackPath)) {
                throw new RuntimeException('Failed to bind callback socket: '.socket_strerror(socket_last_error($callbackServer)));
            }

            if (! socket_listen($callbackServer, 1)) {
                throw new RuntimeException('Failed to listen on callback socket: '.socket_strerror(socket_last_error($callbackServer)));
            }

            socket_set_nonblock($callbackServer);

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc',
                'component' => $component,
                'props' => $props,
                'layouts' => $layouts,
                'callbackSocket' => $callbackPath,
            ], JSON_THROW_ON_ERROR));

            $timeout = (int) config('bun.rsc.callback_timeout', 5);
            $callbackBuffer = '';

            while (true) {
                $read = [$mainSocket];

                if ($callbackServer !== null) {
                    $read[] = $callbackServer;
                }

                if ($callbackClient !== null) {
                    $read[] = $callbackClient;
                }

                $write = [];
                $except = [];
                $changed = socket_select($read, $write, $except, $timeout);

                if ($changed === false) {
                    throw new RuntimeException('socket_select() failed: '.socket_strerror(socket_last_error()));
                }

                if ($changed === 0) {
                    throw new RuntimeException("RSC callback timed out after {$timeout} seconds");
                }

                if ($callbackServer !== null && in_array($callbackServer, $read, true)) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }

                if ($callbackClient !== null && in_array($callbackClient, $read, true)) {
                    $this->handleCallbackData($callbackClient, $callbackBuffer, $registry);
                }

                if (in_array($mainSocket, $read, true)) {
                    $response = $this->readFrame($mainSocket);
                    $this->release($index, $mainSocket);
                    $mainSocket = null;

                    if (isset($response['error'])) {
                        throw new RuntimeException("Bun RSC error: {$response['error']}");
                    }

                    if (! isset($response['result']) || ! is_array($response['result'])) {
                        throw new RuntimeException('Invalid RSC response from Bun');
                    }

                    return $response['result'];
                }
            }
        } catch (\Throwable $e) {
            if ($mainSocket !== null) {
                socket_close($mainSocket);
            }

            throw $e;
        } finally {
            if ($callbackClient !== null) {
                socket_close($callbackClient);
            }

            if ($callbackServer !== null) {
                socket_close($callbackServer);
            }

            if (file_exists($callbackPath)) {
                @unlink($callbackPath);
            }
        }
    }

    private function createUnixServer(string $path): Socket
    {
        $server = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($server === false) {
            throw new RuntimeException('Failed to create socket: '.socket_strerror(socket_last_error()));
        }

        if (! socket_bind($server, $path)) {
            throw new RuntimeException('Failed to bind socket: '.socket_strerror(socket_last_error($server)));
        }

        if (! socket_listen($server, 1)) {
            throw new RuntimeException('Failed to listen on socket: '.socket_strerror(socket_last_error($server)));
        }

        socket_set_nonblock($server);

        return $server;
    }

    private function handleCallbackData(Socket $socket, string &$buffer, CallableRegistry $registry): void
    {
        $chunk = @socket_read($socket, 65536, PHP_BINARY_READ);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $buffer .= $chunk;

        while (strlen($buffer) >= 4) {
            $frameLength = unpack('N', substr($buffer, 0, 4))[1];

            if ($frameLength <= 0 || $frameLength > $this->maxFrameSize) {
                $buffer = '';

                return;
            }

            if (strlen($buffer) < 4 + $frameLength) {
                return;
            }

            $json = substr($buffer, 4, $frameLength);
            $buffer = substr($buffer, 4 + $frameLength);

            $request = json_decode($json, true);

            if (! is_array($request) || ($request['type'] ?? '') !== 'callback') {
                continue;
            }

            $id = $request['id'] ?? '';
            $function = $request['function'] ?? '';
            $args = $request['args'] ?? [];

            try {
                $result = $registry->execute($function, $args);
                $response = json_encode(['id' => $id, 'result' => $result], JSON_THROW_ON_ERROR);
            } catch (AuthenticationException $e) {
                $response = json_encode([
                    'id' => $id,
                    'unauthenticated' => true,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            } catch (AuthorizationException $e) {
                $response = json_encode([
                    'id' => $id,
                    'unauthorized' => true,
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            } catch (ValidationException $e) {
                $response = json_encode([
                    'id' => $id,
                    'validation_errors' => $e->errors(),
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR);
            } catch (RscRedirectException $e) {
                $response = json_encode([
                    'id' => $id,
                    'redirect' => $e->getLocation(),
                ], JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                $response = json_encode(['id' => $id, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
            }

            $this->writeFrame($socket, $response);
        }
    }

    /**
     * @param  array<string, mixed>  $frame
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function throwIfAuthError(array $frame): void
    {
        if (isset($frame['unauthenticated'])) {
            throw new AuthenticationException($frame['error'] ?? 'Unauthenticated.');
        }

        if (isset($frame['unauthorized'])) {
            throw new AuthorizationException($frame['error'] ?? 'This action is unauthorized.');
        }
    }

    /**
     * @param  array<string, mixed>  $frame
     */
    private function throwIfValidationError(array $frame): void
    {
        if (! isset($frame['validation_errors'])) {
            return;
        }

        throw ValidationException::withMessages($frame['validation_errors']);
    }

    private function writeFrame(Socket $socket, string $json): void
    {
        $frame = pack('N', strlen($json)).$json;
        $frameLen = strlen($frame);
        $written = socket_write($socket, $frame, $frameLen);

        if ($written === false || $written === 0) {
            throw new RuntimeException('Failed to write to socket');
        }

        if ($written < $frameLen) {
            $offset = $written;
            $remaining = $frameLen - $written;

            while ($remaining > 0) {
                $written = socket_write($socket, substr($frame, $offset), $remaining);

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Failed to write to socket');
                }

                $offset += $written;
                $remaining -= $written;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readFrame(Socket $socket): array
    {
        $header = socket_read($socket, 4, PHP_BINARY_READ);

        if ($header === false || strlen($header) < 4) {
            throw new RuntimeException('Failed to read from socket');
        }

        $length = unpack('N', $header)[1];

        if ($length <= 0 || $length > $this->maxFrameSize) {
            throw new RuntimeException('Invalid frame length from socket');
        }

        $body = socket_read($socket, $length, PHP_BINARY_READ);

        if ($body === false || $body === '') {
            throw new RuntimeException('Failed to read from socket');
        }

        while (strlen($body) < $length) {
            $chunk = socket_read($socket, $length - strlen($body), PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Failed to read from socket');
            }

            $body .= $chunk;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new RuntimeException('Invalid JSON response from socket');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function send(string $json): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt < $this->workerCount; $attempt++) {
            $index = $this->currentWorker++ % $this->workerCount;
            $socket = $this->checkout($index);

            try {
                $this->writeFrame($socket, $json);
                $response = $this->readFrame($socket);
                $this->release($index, $socket);

                return $response;
            } catch (RuntimeException $e) {
                socket_close($socket);
                $lastException = $e;
            }
        }

        throw $lastException ?? new RuntimeException('All Bun workers are unavailable');
    }

    /**
     * Check out a socket from the pool for exclusive use.
     * Creates a new connection if no idle sockets are available.
     */
    private function checkout(int $index): Socket
    {
        if (! empty($this->pool[$index])) {
            return array_pop($this->pool[$index]);
        }

        return $this->createSocket($index);
    }

    /**
     * Return a socket to the pool after use.
     */
    private function release(int $index, Socket $socket): void
    {
        $this->pool[$index][] = $socket;
    }

    private function createSocket(int $index): Socket
    {
        $path = $this->socketPaths[$index];

        if (! file_exists($path)) {
            throw new RuntimeException(
                "Bun socket not found at {$path}. Run: php artisan bun:serve"
            );
        }

        $socket = socket_create(AF_UNIX, SOCK_STREAM, 0);

        if ($socket === false) {
            throw new RuntimeException(
                'Failed to create socket: '.socket_strerror(socket_last_error())
            );
        }

        if (! socket_connect($socket, $path)) {
            $error = socket_strerror(socket_last_error($socket));
            socket_close($socket);

            throw new RuntimeException(
                "Failed to connect to Bun socket: {$error}. Run: php artisan bun:serve"
            );
        }

        $timeout = ['sec' => 10, 'usec' => 0];
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);

        return $socket;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
