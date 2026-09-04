<?php

namespace Tests\Support;

use RuntimeException;

final class ReverbWebSocketClient
{
    /** @var resource */
    private $socket;

    private function __construct($socket, private readonly string $socketId)
    {
        $this->socket = $socket;
    }

    public static function connect(): self
    {
        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');
        $key = (string) config('broadcasting.connections.reverb.key');
        $socket = stream_socket_client("tcp://{$host}:{$port}", $errorCode, $errorMessage, 5);

        if ($socket === false) {
            throw new RuntimeException("No fue posible conectar con Reverb: {$errorCode} {$errorMessage}");
        }

        stream_set_timeout($socket, 5);
        $webSocketKey = base64_encode(random_bytes(16));
        $path = '/app/'.rawurlencode($key).'?protocol=7&client=php-regression&version=1.0&flash=false';
        $request = "GET {$path} HTTP/1.1\r\n"
            ."Host: {$host}:{$port}\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: {$webSocketKey}\r\n"
            ."Sec-WebSocket-Version: 13\r\n\r\n";
        fwrite($socket, $request);

        $headers = '';
        while (! str_contains($headers, "\r\n\r\n") && ! feof($socket)) {
            $headers .= (string) fgets($socket);
        }

        if (! str_starts_with($headers, 'HTTP/1.1 101')) {
            fclose($socket);

            throw new RuntimeException('Reverb rechazó el handshake WebSocket: '.$headers);
        }

        $client = new self($socket, 'pending');
        $connected = $client->receiveEvent('pusher:connection_established', 5);
        $data = $connected['data'] ?? null;

        if (! is_array($data) || ! is_string($data['socket_id'] ?? null)) {
            fclose($socket);

            throw new RuntimeException('Reverb no entregó un socket_id válido.');
        }

        return new self($socket, $data['socket_id']);
    }

    public function subscribe(string $channel): void
    {
        $privateChannel = 'private-'.$channel;
        $signature = hash_hmac(
            'sha256',
            $this->socketId.':'.$privateChannel,
            (string) config('broadcasting.connections.reverb.secret'),
        );

        $this->send([
            'event' => 'pusher:subscribe',
            'data' => [
                'auth' => config('broadcasting.connections.reverb.key').':'.$signature,
                'channel' => $privateChannel,
            ],
        ]);

        $subscribed = $this->receiveEvent('pusher_internal:subscription_succeeded', 5);

        if ($subscribed === null) {
            throw new RuntimeException('Reverb no confirmó la suscripción privada.');
        }
    }

    /** @return array<string, mixed>|null */
    public function receiveEvent(string $eventName, float $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (($remaining = $deadline - microtime(true)) > 0) {
            $payload = $this->receiveFrame($remaining);

            if ($payload === null) {
                continue;
            }

            $message = json_decode($payload, true);

            if (! is_array($message)) {
                continue;
            }

            if (($message['event'] ?? null) === 'pusher:ping') {
                $this->send(['event' => 'pusher:pong', 'data' => new \stdClass]);

                continue;
            }

            if (($message['event'] ?? null) !== $eventName) {
                continue;
            }

            if (is_string($message['data'] ?? null)) {
                $decoded = json_decode($message['data'], true);
                $message['data'] = is_array($decoded) ? $decoded : $message['data'];
            }

            return $message;
        }

        return null;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /** @param array<string, mixed> $message */
    private function send(array $message): void
    {
        $payload = json_encode($message, JSON_THROW_ON_ERROR);
        $length = strlen($payload);
        $header = chr(0x81);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header .= chr(0x80 | 126).pack('n', $length);
        } else {
            throw new RuntimeException('El frame de prueba supera el tamaño admitido.');
        }

        $mask = random_bytes(4);
        $masked = '';

        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        fwrite($this->socket, $header.$mask.$masked);
    }

    private function receiveFrame(float $timeoutSeconds): ?string
    {
        $read = [$this->socket];
        $write = null;
        $except = null;
        $seconds = (int) floor($timeoutSeconds);
        $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);

        if (stream_select($read, $write, $except, $seconds, $microseconds) !== 1) {
            return null;
        }

        $header = $this->readBytes(2);

        if ($header === null) {
            return null;
        }

        $first = ord($header[0]);
        $second = ord($header[1]);
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;

        if ($length === 126) {
            $extended = $this->readBytes(2);
            $length = $extended === null ? 0 : (int) unpack('nlength', $extended)['length'];
        } elseif ($length === 127) {
            $extended = $this->readBytes(8);
            $parts = $extended === null ? null : unpack('Nhigh/Nlow', $extended);
            $length = $parts === null ? 0 : ((int) $parts['high'] * 4_294_967_296) + (int) $parts['low'];
        }

        $mask = $masked ? $this->readBytes(4) : null;
        $payload = $this->readBytes($length) ?? '';

        if ($masked && $mask !== null) {
            for ($index = 0; $index < $length; $index++) {
                $payload[$index] = $payload[$index] ^ $mask[$index % 4];
            }
        }

        if ($opcode === 0x8) {
            return null;
        }

        return $opcode === 0x1 ? $payload : null;
    }

    private function readBytes(int $length): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length && ! feof($this->socket)) {
            $chunk = fread($this->socket, $length - strlen($buffer));

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $buffer .= $chunk;
        }

        return strlen($buffer) === $length ? $buffer : null;
    }
}
