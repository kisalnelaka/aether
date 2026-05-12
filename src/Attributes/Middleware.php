<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Assigns middleware to a controller method or class.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @param string $class  Fully qualified middleware class name
     * @param int    $priority Execution priority (lower = earlier). Default 100.
     */
    public function __construct(
        public readonly string $class,
        public readonly int $priority = 100,
    ) {}
}
