<?php

declare(strict_types=1);

namespace Aether\WebSocket;

/**
 * Represents a single WebSocket connection.
 * Each one lives inside its own Fiber.
 */
final class Connection
{
    private bool $alive = true;

    /** @var array<string, mixed> Arbitrary data you can attach */
    private array $attributes = [];

    /**
     * @param int $id Unique connection ID
     * @param resource $socket The raw TCP socket
     */
    public function __construct(
        public readonly int $id,
        private readonly mixed $socket,
    ) {}

    /**
     * Send a text message to this client.
     */
    public function send(string $message): void
    {
        $frame = new Frame(Frame::OPCODE_TEXT, $message);
        $this->sendRaw($frame->encode());
    }

    /**
     * Send a binary message.
     */
    public function sendBinary(string $data): void
    {
        $frame = new Frame(Frame::OPCODE_BINARY, $data);
        $this->sendRaw($frame->encode());
    }

    /**
     * Send pre-encoded frame bytes.
     */
    public function sendRaw(string $bytes): void
    {
        if (!$this->alive) return;
        @fwrite($this->socket, $bytes);
    }

    /**
     * Send a close frame and mark the connection as dead.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->alive) return;

        $payload = pack('n', $code) . $reason;
        $frame = new Frame(Frame::OPCODE_CLOSE, $payload);
        @fwrite($this->socket, $frame->encode());
        $this->alive = false;
    }

    public function isAlive(): bool
    {
        return $this->alive;
    }

    /**
     * Store arbitrary data on the connection.
     * Useful for auth tokens, user IDs, room assignments, etc.
     */
    public function set(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->attributes[$key]);
    }
}
