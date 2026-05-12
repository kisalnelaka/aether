<?php

declare(strict_types=1);

namespace Aether\Fiber;

/**
 * Fiber-aware async database abstraction.
 *
 * When a query is executed, the current Fiber is suspended and
 * control returns to the event loop. Other requests can process
 * while this one waits for the DB socket to become readable.
 *
 * Supports MySQL (via mysqli) and PostgreSQL (via pg_*) with
 * non-blocking socket I/O.
 *
 * @package Aether\Fiber
 */
final class AsyncDatabase
{
    private Scheduler $scheduler;
    private string $driver;
    private string $host;
    private int $port;
    private string $database;
    private string $username;
    private string $password;
    /** @var \mysqli|\PgSql\Connection|null */
    private mixed $connection = null;
    private bool $connected = false;

    /**
     * @param array<string, mixed> $config Database config
     */
    public function __construct(Scheduler $scheduler, array $config)
    {
        $this->scheduler = $scheduler;
        $this->driver = (string)($config['driver'] ?? 'mysql');
        $this->host = (string)($config['host'] ?? '127.0.0.1');
        $this->port = (int)($config['port'] ?? ($this->driver === 'pgsql' ? 5432 : 3306));
        $this->database = (string)($config['database'] ?? '');
        $this->username = (string)($config['username'] ?? 'root');
        $this->password = (string)($config['password'] ?? '');
    }

    /**
     * Establish the database connection.
     */
    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        if ($this->driver === 'pgsql') {
            $connStr = "host={$this->host} port={$this->port} dbname={$this->database} "
                     . "user={$this->username} password={$this->password}";
            $conn = pg_connect($connStr, PGSQL_CONNECT_ASYNC);
            if ($conn === false) {
                throw new \RuntimeException("PostgreSQL async connect failed");
            }
            $this->connection = $conn;
        } else {
            $conn = new \mysqli();
            $conn->real_connect($this->host, $this->username, $this->password, $this->database, $this->port);
            if ($conn->connect_error) {
                throw new \RuntimeException("MySQL connect failed: " . $conn->connect_error);
            }
            $this->connection = $conn;
        }

        $this->connected = true;
    }

    /**
     * Execute a query asynchronously via Fibers.
     *
     * @param string $sql SQL query
     * @param array<mixed> $params Bound parameters
     * @return array<array<string, mixed>> Result rows
     */
    public function query(string $sql, array $params = []): array
    {
        $this->connect();

        if ($this->driver === 'pgsql') {
            return $this->queryPgsql($sql, $params);
        }

        return $this->queryMysql($sql, $params);
    }

    /**
     * Execute an INSERT/UPDATE/DELETE asynchronously.
     *
     * @return int Affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->connect();

        if ($this->driver === 'pgsql') {
            return $this->executePgsql($sql, $params);
        }

        return $this->executeMysql($sql, $params);
    }

    /**
     * Close the connection.
     */
    public function close(): void
    {
        if (!$this->connected) {
            return;
        }

        if ($this->driver === 'pgsql' && $this->connection instanceof \PgSql\Connection) {
            pg_close($this->connection);
        } elseif ($this->connection instanceof \mysqli) {
            $this->connection->close();
        }

        $this->connection = null;
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ── Private: MySQL ──

    /** @return array<array<string, mixed>> */
    private function queryMysql(string $sql, array $params): array
    {
        /** @var \mysqli $conn */
        $conn = $this->connection;

        if (count($params) > 0) {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException("MySQL prepare failed: " . $conn->error);
            }
            $types = $this->mysqlParamTypes($params);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $rows;
        }

        // Non-blocking query via Fiber suspension
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            $conn->query($sql, MYSQLI_ASYNC);
            // Suspend — the scheduler will resume us when the socket is ready
            \Fiber::suspend();
        }

        $result = $conn->reap_async_query();
        if ($result === false) {
            if ($conn->error !== '') {
                throw new \RuntimeException("MySQL query failed: " . $conn->error);
            }
            return [];
        }

        if ($result instanceof \mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return $rows;
        }

        return [];
    }

    private function executeMysql(string $sql, array $params): int
    {
        /** @var \mysqli $conn */
        $conn = $this->connection;

        if (count($params) > 0) {
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                throw new \RuntimeException("MySQL prepare failed: " . $conn->error);
            }
            $types = $this->mysqlParamTypes($params);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            return $affected;
        }

        $conn->query($sql);
        return $conn->affected_rows;
    }

    private function mysqlParamTypes(array $params): string
    {
        $types = '';
        foreach ($params as $p) {
            if (is_int($p)) { $types .= 'i'; }
            elseif (is_float($p)) { $types .= 'd'; }
            else { $types .= 's'; }
        }
        return $types;
    }

    // ── Private: PostgreSQL ──

    /** @return array<array<string, mixed>> */
    private function queryPgsql(string $sql, array $params): array
    {
        /** @var \PgSql\Connection $conn */
        $conn = $this->connection;

        if (count($params) > 0) {
            $sent = pg_send_query_params($conn, $sql, $params);
        } else {
            $sent = pg_send_query($conn, $sql);
        }

        if (!$sent) {
            throw new \RuntimeException("PostgreSQL send query failed");
        }

        // Suspend the fiber while waiting for the result
        $fiber = \Fiber::getCurrent();
        if ($fiber !== null) {
            $socket = pg_socket($conn);
            if ($socket !== false) {
                $this->scheduler->watchSocket($socket, 'read');
            }
            \Fiber::suspend();
        }

        // Consume all results
        $rows = [];
        while ($result = pg_get_result($conn)) {
            $status = pg_result_status($result);
            if ($status === PGSQL_TUPLES_OK) {
                $rows = array_merge($rows, pg_fetch_all($result) ?: []);
            } elseif ($status === PGSQL_FATAL_ERROR) {
                throw new \RuntimeException("PostgreSQL error: " . pg_result_error($result));
            }
            pg_free_result($result);
        }

        return $rows;
    }

    private function executePgsql(string $sql, array $params): int
    {
        /** @var \PgSql\Connection $conn */
        $conn = $this->connection;

        if (count($params) > 0) {
            $result = pg_query_params($conn, $sql, $params);
        } else {
            $result = pg_query($conn, $sql);
        }

        if ($result === false) {
            throw new \RuntimeException("PostgreSQL execute failed: " . pg_last_error($conn));
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);
        return $affected;
    }
}
