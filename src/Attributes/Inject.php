<?php

declare(strict_types=1);

namespace Aether\Attributes;

use Attribute;

/**
 * Marks a constructor parameter or property for dependency injection.
 *
 * When applied, the AOT compiler generates a static hydrator
 * that resolves the dependency without Reflection at runtime.
 *
 * @package Aether\Attributes
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
class Inject
{
    /**
     * @param string $id Optional service identifier override.
     *                    Defaults to the type-hinted class/interface name.
     */
    public function __construct(
        public readonly string $id = '',
    ) {}
}
