<?php

declare(strict_types=1);

namespace Aether\WebSocket;

/**
 * WebSocket Frame parser and builder.
 *
 * Handles the RFC 6455 wire format. No external libraries.
 * I wrote a frame parser so you don't have to read the RFC.
 * You're welcome.
 *
 * @package Aether\WebSocket
 */
final class Frame
{
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;

    public function __construct(
        public readonly int $opcode,
        public readonly string $payload,
        public readonly bool $fin = true,
    ) {}

    /**
     * Encode a frame for sending to client.
     * Server frames are never masked (RFC 6455 section 5.1).
     */
    public function encode(): string
    {
        $len = strlen($this->payload);
        $head = chr(($this->fin ? 0x80 : 0x00) | $this->opcode);

        if ($len < 126) {
            $head .= chr($len);
        } elseif ($len < 65536) {
            $head .= chr(126) . pack('n', $len);
        } else {
            $head .= chr(127) . pack('J', $len);
        }

        return $head . $this->payload;
    }

    /**
     * Decode a frame from raw bytes received from client.
     * Client frames are always masked (RFC 6455 section 5.3).
     *
     * Returns null if the buffer doesn't contain a complete frame yet.
     *
     * @return array{frame: self, consumed: int}|null
     */
    public static function decode(string $buffer): ?array
    {
        $bufLen = strlen($buffer);
        if ($bufLen < 2) {
            return null;
        }

        $byte0 = ord($buffer[0]);
        $byte1 = ord($buffer[1]);

        $fin = ($byte0 & 0x80) !== 0;
        $opcode = $byte0 & 0x0F;
        $masked = ($byte1 & 0x80) !== 0;
        $payloadLen = $byte1 & 0x7F;

        $offset = 2;

        if ($payloadLen === 126) {
            if ($bufLen < 4) return null;
            $payloadLen = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLen === 127) {
            if ($bufLen < 10) return null;
            $payloadLen = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        $maskKey = '';
        if ($masked) {
            if ($bufLen < $offset + 4) return null;
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($bufLen < $offset + $payloadLen) {
            return null;
        }

        $payload = substr($buffer, $offset, $payloadLen);

        // Unmask client data
        if ($masked && $maskKey !== '') {
            for ($i = 0; $i < $payloadLen; $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
        }

        $frame = new self($opcode, $payload, $fin);
        return ['frame' => $frame, 'consumed' => $offset + $payloadLen];
    }

    /**
     * Build the HTTP 101 Switching Protocols response for the handshake.
     * This is the only HTTP response in the WebSocket lifecycle.
     */
    public static function handshakeResponse(string $secWebSocketKey): string
    {
        $magic = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
        $accept = base64_encode(sha1($secWebSocketKey . $magic, true));

        return "HTTP/1.1 101 Switching Protocols\r\n"
             . "Upgrade: websocket\r\n"
             . "Connection: Upgrade\r\n"
             . "Sec-WebSocket-Accept: {$accept}\r\n"
             . "\r\n";
    }
}
