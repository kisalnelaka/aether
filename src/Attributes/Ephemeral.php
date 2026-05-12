<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Marks a service as ephemeral (recreated per-request).
 *
 * Ephemeral services are destroyed at the end of each request
 * cycle to prevent state leakage. Examples: request data,
 * authenticated user context, form validators.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Ephemeral
{
    public function __construct() {}
}
