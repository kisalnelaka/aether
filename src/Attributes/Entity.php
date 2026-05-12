<?php

declare(strict_types=1);

namespace Aether\Attributes;

/**
 * Marks a class as a database entity.
 * The AOT compiler will generate SQL templates and hydrators for it.
 * If you don't specify a table name, it lowercases the class name and adds an 's'.
 * Because I'm not writing an inflector for you.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Entity
{
    public function __construct(
        public readonly string $table = '',
        public readonly string $connection = 'default',
    ) {}
}
