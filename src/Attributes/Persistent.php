<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Marks a service as persistent (survives across requests).
 *
 * Persistent services are created once during boot and reused
 * across all worker requests. Examples: database connections,
 * configuration objects, compiled route tables.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Persistent
{
    public function __construct() {}
}
