<?php

declare(strict_types=1);

namespace Aether\Container;

/**
 * Service scope — controls lifecycle in resident-memory mode.
 *
 * @package Aether\Container
 */
enum ServiceScope: string
{
    /** Created once, survives across all requests. DB pools, config, compiled routes. */
    case Persistent = 'persistent';

    /** Recreated for each request. Request data, auth context, form state. */
    case Ephemeral = 'ephemeral';

    /** New instance on every resolve call. */
    case Transient = 'transient';
}
