<?php

declare(strict_types=1);

namespace Aether\Database;

use Aether\Fiber\Scheduler;

/**
 * Fiber-aware Connection Pool.
 *
 * Maintains a fixed pool of DB connections in memory. When a Fiber needs
 * a connection, it checks one out. If the pool is empty, the Fiber suspends
 * until a connection is returned. This is the only sane way to do DB in a
 * persistent-memory server. Opening a connection per request is stupid.
 *
 * Supports MySQL (mysqli) and PostgreSQL (pg_*).
 *
 * @package Aether\Database
 */
final class ConnectionPool
{
    /** @var \SplQueue<\mysqli|\PgSql\Connection> Available connections */
    private \SplQueue $available;

    /** @var array<int, \mysqli|\PgSql\Connection> All connections (for cleanup) */
    private array $all = [];

    /** @var \SplQueue<\Fiber> Fibers waiting for a connection */
    private \SplQueue $waiting;

    private int $size;
    private int $created = 0;
    private string $driver;

    /** @var array<string, mixed> */
    private array $config;

    private Scheduler $scheduler;

    // stats. because you'll ask for them eventually.
    private int $checkouts = 0;
    private int $returns = 0;

    /**
     * @param array<string, mixed> $config  DB config (driver, host, port, database, username, password)
     * @param int $size                     Pool size. Don't set this to 500.
     */
    public function __construct(Scheduler $scheduler, array $config, int $size = 10)
    {
        $this->scheduler = $scheduler;
        $this->config = $config;
        $this->size = $size;
        $this->driver = (string)($config['driver'] ?? 'mysql');
        $this->available = new \SplQueue();
        $this->waiting = new \SplQueue();
    }

    /**
     * Get a connection from the pool.
     *
     * If the pool is empty and we haven't hit the limit, create a new one.
     * If we're at the limit, the current Fiber suspends until someone
     * returns a connection. Don't hold connections forever.
     */
    public function acquire(): \mysqli|\PgSql\Connection
    {
        // Fast path: connection available
        if (!$this->available->isEmpty()) {
            $this->checkouts++;
            return $this->available->dequeue();
        }

        // We can still create more
        if ($this->created < $this->size) {
            $conn = $this->createConnection();
            $this->checkouts++;
            return $conn;
        }

        // Pool exhausted. Suspend the fiber until a connection comes back.
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            $this->waiting->enqueue($fiber);
            \Fiber::suspend();

            // When we resume, there should be a connection waiting
            if (!$this->available->isEmpty()) {
                $this->checkouts++;
                return $this->available->dequeue();
            }
        }

        // Fallback: synchronous wait (shouldn't happen in fiber context)
        // If you hit this, your pool is too small. Make it bigger.
        throw new \RuntimeException(
            "Connection pool exhausted ({$this->size} connections). "
            . "Increase pool size or stop holding connections."
        );
    }

    /**
     * Return a connection to the pool.
     * If fibers are waiting, wake one up immediately.
     */
    public function release(\mysqli|\PgSql\Connection $conn): void
    {
        $this->returns++;

        // If someone is waiting, give them this connection directly
        if (!$this->waiting->isEmpty()) {
            $this->available->enqueue($conn);
            $fiber = $this->waiting->dequeue();
            if ($fiber->isSuspended()) {
                $this->scheduler->schedule($fiber);
            }
            return;
        }

        $this->available->enqueue($conn);
    }

    /**
     * Close all connections. Call this when shutting down.
     */
    public function drain(): void
    {
        while (!$this->available->isEmpty()) {
            $conn = $this->available->dequeue();
            $this->closeConnection($conn);
        }

        foreach ($this->all as $conn) {
            $this->closeConnection($conn);
        }

        $this->all = [];
        $this->created = 0;
    }

    /**
     * Run a callback with a pooled connection. Automatically releases it.
     * Use this. Stop manually acquiring and forgetting to release.
     *
     * @template T
     * @param callable(\mysqli|\PgSql\Connection): T $callback
     * @return T
     */
    public function withConnection(callable $callback): mixed
    {
        $conn = $this->acquire();
        try {
            return $callback($conn);
        } finally {
            $this->release($conn);
        }
    }

    /**
     * Pool diagnostics. For when you inevitably blame the framework
     * for your connection leaks.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'pool_size' => $this->size,
            'created' => $this->created,
            'available' => $this->available->count(),
            'waiting_fibers' => $this->waiting->count(),
            'total_checkouts' => $this->checkouts,
            'total_returns' => $this->returns,
            'leaked' => $this->checkouts - $this->returns,
        ];
    }

    // ── Private ──

    private function createConnection(): \mysqli|\PgSql\Connection
    {
        $this->created++;

        $host = (string)($this->config['host'] ?? '127.0.0.1');
        $port = (int)($this->config['port'] ?? ($this->driver === 'pgsql' ? 5432 : 3306));
        $db = (string)($this->config['database'] ?? '');
        $user = (string)($this->config['username'] ?? 'root');
        $pass = (string)($this->config['password'] ?? '');

        if ($this->driver === 'pgsql') {
            $connStr = "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
            $conn = pg_connect($connStr);
            if ($conn === false) {
                $this->created--;
                throw new \RuntimeException("Failed to create PostgreSQL connection");
            }
            $this->all[spl_object_id((object)$conn)] = $conn;
            return $conn;
        }

        $conn = new \mysqli($host, $user, $pass, $db, $port);
        if ($conn->connect_error) {
            $this->created--;
            throw new \RuntimeException("Failed to create MySQL connection: " . $conn->connect_error);
        }
        $this->all[spl_object_id($conn)] = $conn;
        return $conn;
    }

    private function closeConnection(\mysqli|\PgSql\Connection $conn): void
    {
        if ($conn instanceof \mysqli) {
            @$conn->close();
        } else {
            @pg_close($conn);
        }
    }
}
