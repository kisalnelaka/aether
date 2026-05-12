<?php

declare(strict_types=1);

namespace Aether\Database;

/**
 * Minimal query builder. No ORM magic. Just SQL construction
 * that doesn't make you concatenate strings like a caveman.
 *
 * All queries are parameterized. If you're concatenating user input
 * into SQL strings, I can't help you.
 *
 * @package Aether\Database
 */
final class QueryBuilder
{
    private string $table = '';
    private string $operation = 'SELECT';
    /** @var string[] */
    private array $columns = ['*'];
    /** @var array<string, mixed> */
    private array $insertData = [];
    /** @var array<string, mixed> */
    private array $updateData = [];
    /** @var array{0: string, 1: mixed}[] */
    private array $wheres = [];
    /** @var string[] */
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;
    /** @var string[] */
    private array $joins = [];
    /** @var string[] */
    private array $groupBy = [];
    private ?string $having = null;

    private ConnectionPool $pool;

    public function __construct(ConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Start a SELECT query.
     *
     * @param string|string[] $columns
     */
    public function select(string|array $columns = '*'): self
    {
        $clone = clone $this;
        $clone->operation = 'SELECT';
        $clone->columns = is_array($columns) ? $columns : [$columns];
        return $clone;
    }

    public function from(string $table): self
    {
        $clone = clone $this;
        $clone->table = $table;
        return $clone;
    }

    public function table(string $table): self
    {
        return $this->from($table);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): self
    {
        $clone = clone $this;
        $clone->operation = 'INSERT';
        $clone->insertData = $data;
        return $clone;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(array $data): self
    {
        $clone = clone $this;
        $clone->operation = 'UPDATE';
        $clone->updateData = $data;
        return $clone;
    }

    public function delete(): self
    {
        $clone = clone $this;
        $clone->operation = 'DELETE';
        return $clone;
    }

    public function where(string $column, mixed $value, string $operator = '='): self
    {
        $clone = clone $this;
        $clone->wheres[] = ["{$column} {$operator} ?", $value];
        return $clone;
    }

    public function whereNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = ["{$column} IS NULL", null];
        return $clone;
    }

    public function whereNotNull(string $column): self
    {
        $clone = clone $this;
        $clone->wheres[] = ["{$column} IS NOT NULL", null];
        return $clone;
    }

    public function whereIn(string $column, array $values): self
    {
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $clone = clone $this;
        // Store as a special multi-value where
        foreach ($values as $v) {
            $clone->wheres[] = ['__IN__' . $column . ' IN (' . $placeholders . ')', $v];
        }
        return $clone;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $dir = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $clone = clone $this;
        $clone->orderBy[] = "{$column} {$dir}";
        return $clone;
    }

    public function limit(int $limit): self
    {
        $clone = clone $this;
        $clone->limit = $limit;
        return $clone;
    }

    public function offset(int $offset): self
    {
        $clone = clone $this;
        $clone->offset = $offset;
        return $clone;
    }

    public function join(string $table, string $on, string $type = 'INNER'): self
    {
        $clone = clone $this;
        $clone->joins[] = strtoupper($type) . " JOIN {$table} ON {$on}";
        return $clone;
    }

    public function leftJoin(string $table, string $on): self
    {
        return $this->join($table, $on, 'LEFT');
    }

    public function groupBy(string ...$columns): self
    {
        $clone = clone $this;
        $clone->groupBy = array_merge($clone->groupBy, $columns);
        return $clone;
    }

    public function having(string $condition): self
    {
        $clone = clone $this;
        $clone->having = $condition;
        return $clone;
    }

    // ── Execution ──

    /**
     * Execute and get all rows.
     * @return array<array<string, mixed>>
     */
    public function get(): array
    {
        [$sql, $params] = $this->toSql();
        return $this->pool->withConnection(function ($conn) use ($sql, $params) {
            return $this->executeQuery($conn, $sql, $params);
        });
    }

    /**
     * Execute and get the first row, or null.
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit = 1;
        $rows = $clone->get();
        return $rows[0] ?? null;
    }

    /**
     * Execute an INSERT/UPDATE/DELETE and return affected row count.
     */
    public function execute(): int
    {
        [$sql, $params] = $this->toSql();
        return $this->pool->withConnection(function ($conn) use ($sql, $params) {
            return $this->executeStatement($conn, $sql, $params);
        });
    }

    /**
     * Count rows matching the query.
     */
    public function count(): int
    {
        $clone = clone $this;
        $clone->columns = ['COUNT(*) as cnt'];
        $row = $clone->first();
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Check if any rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ── SQL Generation ──

    /**
     * Build the SQL string and parameter array.
     * @return array{0: string, 1: array<mixed>}
     */
    public function toSql(): array
    {
        $params = [];

        return match ($this->operation) {
            'SELECT' => $this->buildSelect($params),
            'INSERT' => $this->buildInsert($params),
            'UPDATE' => $this->buildUpdate($params),
            'DELETE' => $this->buildDelete($params),
            default => throw new \RuntimeException("Unknown operation: {$this->operation}"),
        };
    }

    // ── Private: SQL Builders ──

    /**
     * @param array<mixed> $params
     * @return array{0: string, 1: array<mixed>}
     */
    private function buildSelect(array &$params): array
    {
        $cols = implode(', ', $this->columns);
        $sql = "SELECT {$cols} FROM {$this->table}";

        foreach ($this->joins as $join) {
            $sql .= " {$join}";
        }

        $sql .= $this->buildWhere($params);

        if (count($this->groupBy) > 0) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->having !== null) {
            $sql .= " HAVING {$this->having}";
        }

        if (count($this->orderBy) > 0) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset !== null) {
            $sql .= " OFFSET {$this->offset}";
        }

        return [$sql, $params];
    }

    /**
     * @param array<mixed> $params
     * @return array{0: string, 1: array<mixed>}
     */
    private function buildInsert(array &$params): array
    {
        $cols = implode(', ', array_keys($this->insertData));
        $placeholders = implode(', ', array_fill(0, count($this->insertData), '?'));
        $params = array_values($this->insertData);
        return ["INSERT INTO {$this->table} ({$cols}) VALUES ({$placeholders})", $params];
    }

    /**
     * @param array<mixed> $params
     * @return array{0: string, 1: array<mixed>}
     */
    private function buildUpdate(array &$params): array
    {
        $sets = [];
        foreach ($this->updateData as $col => $val) {
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql = "UPDATE {$this->table} SET " . implode(', ', $sets);
        $sql .= $this->buildWhere($params);
        return [$sql, $params];
    }

    /**
     * @param array<mixed> $params
     * @return array{0: string, 1: array<mixed>}
     */
    private function buildDelete(array &$params): array
    {
        $sql = "DELETE FROM {$this->table}";
        $sql .= $this->buildWhere($params);
        return [$sql, $params];
    }

    /**
     * @param array<mixed> $params
     */
    private function buildWhere(array &$params): string
    {
        if (count($this->wheres) === 0) {
            return '';
        }

        $clauses = [];
        foreach ($this->wheres as [$clause, $value]) {
            // Skip the __IN__ hack entries (they share the clause)
            if (str_starts_with($clause, '__IN__')) {
                continue;
            }
            $clauses[] = $clause;
            if ($value !== null) {
                $params[] = $value;
            }
        }

        return count($clauses) > 0 ? ' WHERE ' . implode(' AND ', $clauses) : '';
    }

    // ── Private: Execution ──

    /**
     * @return array<array<string, mixed>>
     */
    private function executeQuery(\mysqli|\PgSql\Connection $conn, string $sql, array $params): array
    {
        if ($conn instanceof \mysqli) {
            if (count($params) > 0) {
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException("MySQL prepare failed: " . $conn->error);
                }
                $types = '';
                foreach ($params as $p) {
                    if (is_int($p)) { $types .= 'i'; }
                    elseif (is_float($p)) { $types .= 'd'; }
                    else { $types .= 's'; }
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                $stmt->close();
                return $rows;
            }

            $result = $conn->query($sql);
            if ($result instanceof \mysqli_result) {
                $rows = $result->fetch_all(MYSQLI_ASSOC);
                $result->free();
                return $rows;
            }
            return [];
        }

        // PostgreSQL
        if (count($params) > 0) {
            $result = pg_query_params($conn, $sql, $params);
        } else {
            $result = pg_query($conn, $sql);
        }

        if ($result === false) {
            throw new \RuntimeException("PostgreSQL query failed: " . pg_last_error($conn));
        }

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    private function executeStatement(\mysqli|\PgSql\Connection $conn, string $sql, array $params): int
    {
        if ($conn instanceof \mysqli) {
            if (count($params) > 0) {
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException("MySQL prepare failed: " . $conn->error);
                }
                $types = '';
                foreach ($params as $p) {
                    if (is_int($p)) { $types .= 'i'; }
                    elseif (is_float($p)) { $types .= 'd'; }
                    else { $types .= 's'; }
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $affected = $stmt->affected_rows;
                $stmt->close();
                return $affected;
            }

            $conn->query($sql);
            return $conn->affected_rows;
        }

        // PostgreSQL
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
