<?php

declare(strict_types=1);

namespace Aether\Router;

/**
 * Radix Tree Node — A single node in the prefix tree.
 *
 * Each node represents a URI segment or a shared prefix.
 * Dynamic parameters are stored as special children with
 * a "{" prefix marker. No regular expressions are used.
 *
 * @package Aether\Router
 */
final class RadixNode
{
    /** @var string The path segment this node represents */
    public string $segment;

    /** @var array<string, RadixNode> Static children keyed by first character */
    public array $children = [];

    /** @var RadixNode|null Dynamic parameter child (e.g. {id}) */
    public ?RadixNode $paramChild = null;

    /** @var string|null Parameter name if this is a dynamic node */
    public ?string $paramName = null;

    /** @var RadixNode|null Wildcard/catch-all child (e.g. {path*}) */
    public ?RadixNode $wildcardChild = null;

    /** @var string|null Wildcard parameter name */
    public ?string $wildcardName = null;

    /** @var array<string, RouteEntry> HTTP method => RouteEntry mapping */
    public array $handlers = [];

    /** @var bool Whether this node is a terminal route */
    public bool $isLeaf = false;

    public function __construct(string $segment = '')
    {
        $this->segment = $segment;
    }

    /**
     * Check if this node has a handler for the given HTTP method.
     */
    public function hasMethod(string $method): bool
    {
        return isset($this->handlers[$method]);
    }

    /**
     * Get all registered HTTP methods for this node.
     *
     * @return array<string>
     */
    public function getAllowedMethods(): array
    {
        return array_keys($this->handlers);
    }
}
