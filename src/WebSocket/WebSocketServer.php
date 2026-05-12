<?php

declare(strict_types=1);

namespace Aether\WebSocket;

use Aether\Fiber\Scheduler;

/**
 * WebSocket Server. Manages persistent connections using Fibers.
 *
 * Each WebSocket connection gets its own Fiber. When the client isn't
 * sending data, the Fiber suspends and the scheduler handles other work.
 * This means you can have thousands of open connections on a single thread
 * without burning CPU.
 *
 * Usage:
 *   $ws = new WebSocketServer($scheduler, '0.0.0.0', 9001);
 *   $ws->onMessage(function(Connection $conn, string $msg) { ... });
 *   $ws->start();
 *
 * @package Aether\WebSocket
 */
final class WebSocketServer
{
    /** @var resource|null */
    private $serverSocket = null;

    /** @var array<int, Connection> Active connections by ID */
    private array $connections = [];

    private int $nextId = 1;
    private bool $running = false;

    /** @var callable|null */
    private $onMessageHandler = null;
    /** @var callable|null */
    private $onConnectHandler = null;
    /** @var callable|null */
    private $onDisconnectHandler = null;

    private Scheduler $scheduler;
    private string $host;
    private int $port;

    public function __construct(Scheduler $scheduler, string $host = '0.0.0.0', int $port = 9001)
    {
        $this->scheduler = $scheduler;
        $this->host = $host;
        $this->port = $port;
    }

    public function onMessage(callable $handler): self
    {
        $this->onMessageHandler = $handler;
        return $this;
    }

    public function onConnect(callable $handler): self
    {
        $this->onConnectHandler = $handler;
        return $this;
    }

    public function onDisconnect(callable $handler): self
    {
        $this->onDisconnectHandler = $handler;
        return $this;
    }

    /**
     * Start accepting WebSocket connections.
     * This runs inside a Fiber so it doesn't block.
     */
    public function start(): void
    {
        $ctx = stream_context_create(['socket' => ['backlog' => 128]]);
        $this->serverSocket = stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx
        );

        if ($this->serverSocket === false) {
            throw new \RuntimeException("WebSocket server failed to bind: [{$errno}] {$errstr}");
        }

        stream_set_blocking($this->serverSocket, false);
        $this->running = true;

        // Accept loop runs in its own fiber
        $this->scheduler->defer(function () {
            while ($this->running) {
                $client = @stream_socket_accept($this->serverSocket, 0);

                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $this->handleNewConnection($client);
                }

                // Yield so we don't spin
                if (\Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
            }
        });
    }

    /**
     * Broadcast a message to all connected clients.
     */
    public function broadcast(string $message, ?int $excludeId = null): void
    {
        $frame = new Frame(Frame::OPCODE_TEXT, $message);
        $encoded = $frame->encode();

        foreach ($this->connections as $id => $conn) {
            if ($id === $excludeId) continue;
            $conn->sendRaw($encoded);
        }
    }

    /**
     * Send to a specific connection by ID.
     */
    public function sendTo(int $connectionId, string $message): void
    {
        if (isset($this->connections[$connectionId])) {
            $this->connections[$connectionId]->send($message);
        }
    }

    /**
     * Close the server and all connections.
     */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->connections as $conn) {
            $conn->close();
        }
        $this->connections = [];

        if ($this->serverSocket !== null) {
            fclose($this->serverSocket);
            $this->serverSocket = null;
        }
    }

    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'connections' => count($this->connections),
            'port' => $this->port,
            'running' => $this->running ? 1 : 0,
        ];
    }

    // ── Private ──

    private function handleNewConnection($socket): void
    {
        $id = $this->nextId++;

        // Each connection gets its own Fiber
        $this->scheduler->defer(function () use ($socket, $id) {
            // Step 1: HTTP upgrade handshake
            $request = '';
            $deadline = microtime(true) + 5.0; // 5s timeout for handshake

            while (microtime(true) < $deadline) {
                $chunk = @fread($socket, 4096);
                if ($chunk !== false && $chunk !== '') {
                    $request .= $chunk;
                    if (str_contains($request, "\r\n\r\n")) {
                        break;
                    }
                }
                if (\Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
            }

            // Extract Sec-WebSocket-Key without regex
            $key = $this->extractHeader($request, 'Sec-WebSocket-Key');
            if ($key === '') {
                @fclose($socket);
                return;
            }

            // Send handshake response
            $response = Frame::handshakeResponse($key);
            @fwrite($socket, $response);

            // Create connection object
            $conn = new Connection($id, $socket);
            $this->connections[$id] = $conn;

            if ($this->onConnectHandler !== null) {
                ($this->onConnectHandler)($conn);
            }

            // Step 2: Message loop
            $buffer = '';
            while ($this->running && $conn->isAlive()) {
                $data = @fread($socket, 65536);

                if ($data === false || $data === '') {
                    if (feof($socket)) {
                        break;
                    }
                    // No data ready, suspend the fiber
                    if (\Fiber::getCurrent() !== null) {
                        \Fiber::suspend();
                    }
                    continue;
                }

                $buffer .= $data;

                // Parse all complete frames in the buffer
                while (true) {
                    $result = Frame::decode($buffer);
                    if ($result === null) break;

                    $frame = $result['frame'];
                    $buffer = substr($buffer, $result['consumed']);

                    match ($frame->opcode) {
                        Frame::OPCODE_TEXT, Frame::OPCODE_BINARY => $this->handleMessage($conn, $frame->payload),
                        Frame::OPCODE_PING => $conn->sendRaw((new Frame(Frame::OPCODE_PONG, $frame->payload))->encode()),
                        Frame::OPCODE_CLOSE => $conn->close(),
                        default => null,
                    };
                }
            }

            // Cleanup
            unset($this->connections[$id]);
            @fclose($socket);

            if ($this->onDisconnectHandler !== null) {
                ($this->onDisconnectHandler)($conn);
            }
        });
    }

    private function handleMessage(Connection $conn, string $message): void
    {
        if ($this->onMessageHandler !== null) {
            ($this->onMessageHandler)($conn, $message);
        }
    }

    /**
     * Extract an HTTP header value without regex. Byte-level parsing.
     */
    private function extractHeader(string $request, string $headerName): string
    {
        $needle = $headerName . ': ';
        $pos = strpos($request, $needle);
        if ($pos === false) {
            // Try lowercase
            $pos = strpos(strtolower($request), strtolower($needle));
            if ($pos === false) return '';
        }

        $start = $pos + strlen($needle);
        $end = strpos($request, "\r\n", $start);
        if ($end === false) $end = strlen($request);

        return trim(substr($request, $start, $end - $start));
    }
}
