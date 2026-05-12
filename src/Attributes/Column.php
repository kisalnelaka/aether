<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Maps a property to a database column.
 * The AOT compiler reads this and generates direct assignment hydrators.
 * No reflection at runtime. Just $entity->name = $row['name'].
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Column
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $type = 'string',
        public readonly bool $nullable = false,
        public readonly bool $primaryKey = false,
        public readonly bool $autoIncrement = false,
    ) {}
}
