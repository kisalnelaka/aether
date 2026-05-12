<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Shorthand attribute for PUT routes.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Put extends Route
{
    public function __construct(
        string $path,
        string $name = '',
        array $middleware = [],
    ) {
        parent::__construct($path, 'PUT', $name, $middleware);
    }
}
