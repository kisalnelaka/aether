<?php

declare(strict_types=1);

namespace Aether\Database;

/**
 * Zero-reflection Entity Manager (Data Mapper pattern).
 *
 * At build time, the AOT compiler scans #[Entity] and #[Column] attributes
 * and generates flat hydration functions and SQL templates. At runtime,
 * this class just calls those generated functions.
 *
 * If AOT isn't loaded, falls back to reflection. Slower, but functional.
 * Compile your code before deploying. I'm not saying it again.
 *
 * @package Aether\Database
 */
final class EntityManager
{
    private ConnectionPool $pool;

    /** @var array<string, array{table: string, columns: array<string, array{name: string, type: string, pk: bool, auto: bool}>, hydrator: callable|null}> */
    private static array $metadata = [];

    public function __construct(ConnectionPool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * Load AOT-compiled entity metadata.
     * @param array<string, array<string, mixed>> $meta
     */
    public static function loadCompiled(array $meta): void
    {
        self::$metadata = $meta + self::$metadata;
    }

    /**
     * Find an entity by primary key.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     */
    public function find(string $class, mixed $id): ?object
    {
        $meta = $this->getMeta($class);
        $pk = $this->getPrimaryKey($meta);
        $table = $meta['table'];

        $row = $this->pool->withConnection(function ($conn) use ($table, $pk, $id) {
            return $this->queryOne($conn, "SELECT * FROM {$table} WHERE {$pk} = ?", [$id]);
        });

        return $row !== null ? $this->hydrate($class, $row, $meta) : null;
    }

    /**
     * Find all entities, optionally with conditions.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria Column => value pairs
     * @return T[]
     */
    public function findAll(string $class, array $criteria = [], ?int $limit = null, string $orderBy = ''): array
    {
        $meta = $this->getMeta($class);
        $table = $meta['table'];

        $sql = "SELECT * FROM {$table}";
        $params = [];

        if (count($criteria) > 0) {
            $wheres = [];
            foreach ($criteria as $col => $val) {
                $wheres[] = "{$col} = ?";
                $params[] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }

        if ($limit !== null) {
            $sql .= " LIMIT {$limit}";
        }

        $rows = $this->pool->withConnection(function ($conn) use ($sql, $params) {
            return $this->queryAll($conn, $sql, $params);
        });

        return array_map(fn($row) => $this->hydrate($class, $row, $meta), $rows);
    }

    /**
     * Find one entity by criteria.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     * @return T|null
     */
    public function findOne(string $class, array $criteria): ?object
    {
        $results = $this->findAll($class, $criteria, 1);
        return $results[0] ?? null;
    }

    /**
     * Persist (INSERT or UPDATE) an entity.
     */
    public function save(object $entity): void
    {
        $class = get_class($entity);
        $meta = $this->getMeta($class);
        $table = $meta['table'];
        $pk = $this->getPrimaryKey($meta);
        $pkValue = $this->extractValue($entity, $pk);

        $data = $this->dehydrate($entity, $meta);

        if ($pkValue === null || $pkValue === 0 || $pkValue === '') {
            // INSERT
            unset($data[$pk]); // let auto-increment handle it
            $cols = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_fill(0, count($data), '?'));
            $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})";

            $this->pool->withConnection(function ($conn) use ($sql, $data, $entity, $pk) {
                $this->exec($conn, $sql, array_values($data));
                // Set the auto-increment ID back on the entity
                if ($conn instanceof \mysqli) {
                    $this->setProperty($entity, $pk, $conn->insert_id);
                }
            });
        } else {
            // UPDATE
            $sets = [];
            $params = [];
            foreach ($data as $col => $val) {
                if ($col === $pk) continue;
                $sets[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = $pkValue;
            $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$pk} = ?";

            $this->pool->withConnection(function ($conn) use ($sql, $params) {
                $this->exec($conn, $sql, $params);
            });
        }
    }

    /**
     * Delete an entity by primary key.
     */
    public function delete(object $entity): void
    {
        $class = get_class($entity);
        $meta = $this->getMeta($class);
        $table = $meta['table'];
        $pk = $this->getPrimaryKey($meta);
        $pkValue = $this->extractValue($entity, $pk);

        $this->pool->withConnection(function ($conn) use ($table, $pk, $pkValue) {
            $this->exec($conn, "DELETE FROM {$table} WHERE {$pk} = ?", [$pkValue]);
        });
    }

    /**
     * Count entities matching criteria.
     */
    public function count(string $class, array $criteria = []): int
    {
        $meta = $this->getMeta($class);
        $table = $meta['table'];
        $sql = "SELECT COUNT(*) as cnt FROM {$table}";
        $params = [];

        if (count($criteria) > 0) {
            $wheres = [];
            foreach ($criteria as $col => $val) {
                $wheres[] = "{$col} = ?";
                $params[] = $val;
            }
            $sql .= ' WHERE ' . implode(' AND ', $wheres);
        }

        $row = $this->pool->withConnection(function ($conn) use ($sql, $params) {
            return $this->queryOne($conn, $sql, $params);
        });

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Get the QueryBuilder for complex queries.
     */
    public function query(): QueryBuilder
    {
        return new QueryBuilder($this->pool);
    }

    // ── Private: Metadata ──

    /**
     * @return array{table: string, columns: array<string, array{name: string, type: string, pk: bool, auto: bool}>}
     */
    private function getMeta(string $class): array
    {
        if (isset(self::$metadata[$class])) {
            return self::$metadata[$class];
        }

        // Runtime fallback: scan with reflection
        return $this->buildMetaFromReflection($class);
    }

    private function buildMetaFromReflection(string $class): array
    {
        $ref = new \ReflectionClass($class);

        // Get table name from #[Entity] attribute
        $entityAttrs = $ref->getAttributes(\Aether\Attributes\Entity::class);
        $table = '';
        if (count($entityAttrs) > 0) {
            $entityAttr = $entityAttrs[0]->newInstance();
            $table = $entityAttr->table;
        }
        if ($table === '') {
            // Default: lowercase class name + 's'
            $short = $ref->getShortName();
            $table = strtolower($short) . 's';
        }

        $columns = [];
        foreach ($ref->getProperties() as $prop) {
            $colAttrs = $prop->getAttributes(\Aether\Attributes\Column::class);
            if (count($colAttrs) === 0) continue;

            $col = $colAttrs[0]->newInstance();
            $colName = $col->name !== '' ? $col->name : $prop->getName();
            $columns[$prop->getName()] = [
                'name' => $colName,
                'type' => $col->type,
                'pk' => $col->primaryKey,
                'auto' => $col->autoIncrement,
            ];
        }

        $meta = ['table' => $table, 'columns' => $columns, 'hydrator' => null];
        self::$metadata[$class] = $meta;
        return $meta;
    }

    private function getPrimaryKey(array $meta): string
    {
        foreach ($meta['columns'] as $prop => $col) {
            if ($col['pk']) return $col['name'];
        }
        return 'id'; // fallback. If you don't have an ID column, that's on you.
    }

    // ── Private: Hydration ──

    private function hydrate(string $class, array $row, array $meta): object
    {
        // AOT hydrator path
        if (isset($meta['hydrator']) && is_callable($meta['hydrator'])) {
            return ($meta['hydrator'])($row);
        }

        // Reflection fallback
        $entity = new $class();
        foreach ($meta['columns'] as $prop => $col) {
            $value = $row[$col['name']] ?? null;
            if ($value !== null) {
                $this->setProperty($entity, $prop, $this->castFromDb($value, $col['type']));
            }
        }
        return $entity;
    }

    private function dehydrate(object $entity, array $meta): array
    {
        $data = [];
        foreach ($meta['columns'] as $prop => $col) {
            $data[$col['name']] = $this->extractValue($entity, $prop);
        }
        return $data;
    }

    private function extractValue(object $entity, string $property): mixed
    {
        if (property_exists($entity, $property)) {
            return $entity->{$property};
        }
        return null;
    }

    private function setProperty(object $entity, string $property, mixed $value): void
    {
        if (property_exists($entity, $property)) {
            $entity->{$property} = $value;
        }
    }

    private function castFromDb(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'string' => (string)$value,
            default => $value,
        };
    }

    // ── Private: Query execution ──

    private function queryOne(\mysqli|\PgSql\Connection $conn, string $sql, array $params): ?array
    {
        $rows = $this->queryAll($conn, $sql, $params);
        return $rows[0] ?? null;
    }

    private function queryAll(\mysqli|\PgSql\Connection $conn, string $sql, array $params): array
    {
        if ($conn instanceof \mysqli) {
            if (count($params) > 0) {
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new \RuntimeException("Prepare failed: " . $conn->error);
                $types = '';
                foreach ($params as $p) {
                    if (is_int($p)) $types .= 'i';
                    elseif (is_float($p)) $types .= 'd';
                    else $types .= 's';
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                $stmt->close();
                return $rows;
            }
            $result = $conn->query($sql);
            return ($result instanceof \mysqli_result) ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }

        // PostgreSQL
        $result = count($params) > 0 ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
        if (!$result) throw new \RuntimeException("Query failed: " . pg_last_error($conn));
        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    private function exec(\mysqli|\PgSql\Connection $conn, string $sql, array $params): int
    {
        if ($conn instanceof \mysqli) {
            if (count($params) > 0) {
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new \RuntimeException("Prepare failed: " . $conn->error);
                $types = '';
                foreach ($params as $p) {
                    if (is_int($p)) $types .= 'i';
                    elseif (is_float($p)) $types .= 'd';
                    else $types .= 's';
                }
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $a = $stmt->affected_rows;
                $stmt->close();
                return $a;
            }
            $conn->query($sql);
            return $conn->affected_rows;
        }

        $result = count($params) > 0 ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
        if (!$result) throw new \RuntimeException("Execute failed: " . pg_last_error($conn));
        $a = pg_affected_rows($result);
        pg_free_result($result);
        return $a;
    }
}
