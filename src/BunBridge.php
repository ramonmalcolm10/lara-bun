<?php

namespace RamonMalcolm\LaraBun;

use RamonMalcolm\LaraBun\Rsc\CallableRegistry;
use RuntimeException;
use Socket;

class BunBridge
{
    /** @var string[] */
    private array $socketPaths;

    /** @var Socket[] */
    private array $sockets = [];

    private int $workerCount;

    private int $currentWorker;

    public function __construct()
    {
        if (! extension_loaded('sockets')) {
            throw new RuntimeException('The sockets extension is required. Enable it in php.ini.');
        }

        $basePath = config('bun.socket_path', '/tmp/bun-bridge.sock');
        $this->workerCount = max(1, (int) config('bun.workers', 1));
        $this->currentWorker = $this->workerCount > 1 ? random_int(0, $this->workerCount - 1) : 0;

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
     * @return array{body: string, rscPayload: string, clientChunks: string[]}
     */
    public function rsc(string $component, array $props = []): array
    {
        $registry = app(CallableRegistry::class);

        if ($registry->hasCallables()) {
            return $this->rscWithCallbacks($component, $props, $registry);
        }

        $response = $this->send(json_encode([
            'type' => 'rsc',
            'component' => $component,
            'props' => $props,
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
            try {
                $response = $this->sendTo($i, '{"type":"ping"}');

                if (($response['type'] ?? null) === 'pong') {
                    $anyAlive = true;
                }
            } catch (RuntimeException) {
                // Worker not responding
            }
        }

        return $anyAlive;
    }

    public function disconnect(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }

        $this->sockets = [];
    }

    /**
     * @return array{body: string, rscPayload: string, clientChunks: string[]}
     */
    private function rscWithCallbacks(string $component, array $props, CallableRegistry $registry): array
    {
        $callbackPath = '/tmp/rsc-cb-'.bin2hex(random_bytes(8)).'.sock';
        $callbackServer = null;
        $callbackClient = null;

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

            // Send RSC request with callback socket path
            $mainSocket = $this->getSocket($this->currentWorker++ % $this->workerCount);

            $this->writeFrame($mainSocket, json_encode([
                'type' => 'rsc',
                'component' => $component,
                'props' => $props,
                'callbackSocket' => $callbackPath,
            ], JSON_THROW_ON_ERROR));

            // Enter select loop monitoring main + callback sockets
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

                // Accept new callback connection
                if ($callbackServer !== null && in_array($callbackServer, $read, true)) {
                    $accepted = socket_accept($callbackServer);

                    if ($accepted !== false) {
                        socket_set_nonblock($accepted);
                        $callbackClient = $accepted;
                        // Close the listener — we only need one connection
                        socket_close($callbackServer);
                        $callbackServer = null;
                    }
                }

                // Handle callback data from Bun
                if ($callbackClient !== null && in_array($callbackClient, $read, true)) {
                    $this->handleCallbackData($callbackClient, $callbackBuffer, $registry);
                }

                // Check for final response on main socket
                if (in_array($mainSocket, $read, true)) {
                    $response = $this->readFrame($mainSocket);

                    if (isset($response['error'])) {
                        throw new RuntimeException("Bun RSC error: {$response['error']}");
                    }

                    if (! isset($response['result']) || ! is_array($response['result'])) {
                        throw new RuntimeException('Invalid RSC response from Bun');
                    }

                    return $response['result'];
                }
            }
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

    private function handleCallbackData(Socket $socket, string &$buffer, CallableRegistry $registry): void
    {
        $chunk = @socket_read($socket, 65536, PHP_BINARY_READ);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $buffer .= $chunk;

        while (strlen($buffer) >= 4) {
            $frameLength = unpack('N', substr($buffer, 0, 4))[1];

            if ($frameLength <= 0 || $frameLength > 10 * 1024 * 1024) {
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
            } catch (\Throwable $e) {
                $response = json_encode(['id' => $id, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR);
            }

            $this->writeFrame($socket, $response);
        }
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

        if ($length <= 0 || $length > 10 * 1024 * 1024) {
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
            $i = $this->currentWorker++ % $this->workerCount;

            try {
                return $this->sendTo($i, $json);
            } catch (RuntimeException $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new RuntimeException('All Bun workers are unavailable');
    }

    /**
     * @return array<string, mixed>
     */
    private function sendTo(int $index, string $json): array
    {
        $socket = $this->getSocket($index);

        $this->writeFrame($socket, $json);

        try {
            return $this->readFrame($socket);
        } catch (RuntimeException $e) {
            $this->closeSocket($index);
            throw $e;
        }
    }

    private function getSocket(int $index): Socket
    {
        if (isset($this->sockets[$index])) {
            return $this->sockets[$index];
        }

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

        $this->sockets[$index] = $socket;

        return $socket;
    }

    private function closeSocket(int $index): void
    {
        if (isset($this->sockets[$index])) {
            socket_close($this->sockets[$index]);
            unset($this->sockets[$index]);
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
