<?php

namespace RamonMalcolm\LaraBun;

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

        // Write length-prefixed frame
        $frame = pack('N', strlen($json)).$json;
        $frameLen = strlen($frame);
        $written = socket_write($socket, $frame, $frameLen);

        if ($written === false || $written === 0) {
            $this->closeSocket($index);
            throw new RuntimeException('Failed to write to Bun socket');
        }

        if ($written < $frameLen) {
            $offset = $written;
            $remaining = $frameLen - $written;

            while ($remaining > 0) {
                $written = socket_write($socket, substr($frame, $offset), $remaining);

                if ($written === false || $written === 0) {
                    $this->closeSocket($index);
                    throw new RuntimeException('Failed to write to Bun socket');
                }

                $offset += $written;
                $remaining -= $written;
            }
        }

        // Read 4-byte length header
        $header = socket_read($socket, 4, PHP_BINARY_READ);

        if ($header === false || strlen($header) < 4) {
            $this->closeSocket($index);
            throw new RuntimeException('Failed to read from Bun socket');
        }

        $length = unpack('N', $header)[1];

        if ($length <= 0 || $length > 10 * 1024 * 1024) {
            $this->closeSocket($index);
            throw new RuntimeException('Invalid frame length from Bun socket');
        }

        // Read response body
        $body = socket_read($socket, $length, PHP_BINARY_READ);

        if ($body === false || $body === '') {
            $this->closeSocket($index);
            throw new RuntimeException('Failed to read from Bun socket');
        }

        // Handle partial read for large responses
        while (strlen($body) < $length) {
            $chunk = socket_read($socket, $length - strlen($body), PHP_BINARY_READ);

            if ($chunk === false || $chunk === '') {
                $this->closeSocket($index);
                throw new RuntimeException('Failed to read from Bun socket');
            }

            $body .= $chunk;
        }

        $data = json_decode($body, true);

        if (! is_array($data)) {
            $this->closeSocket($index);
            throw new RuntimeException('Invalid JSON response from Bun socket');
        }

        return $data;
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
