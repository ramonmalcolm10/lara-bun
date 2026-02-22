<?php

namespace RamonMalcolm\LaraBun;

use RuntimeException;

class BunBridge
{
    private string $socketPath;

    /** @var resource|null */
    private $socket = null;

    public function __construct()
    {
        $this->socketPath = config('bun.socket_path', '/tmp/bun-bridge.sock');
    }

    public function call(string $function, array $args = []): mixed
    {
        $requestId = 'call_' . bin2hex(random_bytes(8));

        $response = $this->send([
            'type' => 'call',
            'function' => $function,
            'args' => $args,
            'requestId' => $requestId,
        ]);

        if (isset($response['error'])) {
            throw new RuntimeException("Bun error: {$response['error']}");
        }

        return $response['result'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function list(): array
    {
        $requestId = 'list_' . bin2hex(random_bytes(8));

        $response = $this->send([
            'type' => 'list',
            'requestId' => $requestId,
        ]);

        return $response['result'] ?? [];
    }

    public function ping(): bool
    {
        try {
            $requestId = 'ping_' . bin2hex(random_bytes(8));

            $response = $this->send([
                'type' => 'ping',
                'requestId' => $requestId,
            ]);

            return ($response['type'] ?? null) === 'pong';
        } catch (RuntimeException) {
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->socket !== null && is_resource($this->socket)) {
            fclose($this->socket);
        }

        $this->socket = null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function send(array $message): array
    {
        $socket = $this->getConnection();

        $json = json_encode($message, JSON_THROW_ON_ERROR);
        $written = fwrite($socket, $json . "\n");

        if ($written === false) {
            $this->disconnect();
            throw new RuntimeException('Failed to write to Bun socket');
        }

        fflush($socket);

        $response = $this->readLine($socket, 10.0);

        if ($response === null) {
            $this->disconnect();
            throw new RuntimeException('Timed out waiting for response from Bun socket');
        }

        $data = json_decode($response, true);

        if (! is_array($data)) {
            $this->disconnect();
            throw new RuntimeException('Invalid JSON response from Bun socket');
        }

        return $data;
    }

    /**
     * @return resource
     */
    private function getConnection()
    {
        if ($this->socket !== null && is_resource($this->socket) && ! feof($this->socket)) {
            return $this->socket;
        }

        $this->disconnect();

        if (! file_exists($this->socketPath)) {
            throw new RuntimeException(
                "Bun socket not found at {$this->socketPath}. Run: php artisan bun:serve"
            );
        }

        $socket = stream_socket_client(
            "unix://{$this->socketPath}",
            $errorCode,
            $errorMessage,
            5.0,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Failed to connect to Bun socket: [{$errorCode}] {$errorMessage}. Run: php artisan bun:serve"
            );
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;

        return $this->socket;
    }

    private function readLine(mixed $socket, float $timeout): ?string
    {
        $read = [$socket];
        $write = null;
        $except = null;
        $seconds = (int) $timeout;
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);

        $ready = stream_select($read, $write, $except, $seconds, $microseconds);

        if ($ready > 0) {
            $line = fgets($socket);

            if ($line !== false) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
