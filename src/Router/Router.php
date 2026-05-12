<?php

declare(strict_types=1);

namespace Aether\Router;

/**
 * Router — High-level API for route registration and matching.
 *
 * @package Aether\Router
 */
final class Router
{
    private RadixTree $tree;
    /** @var array<string> Global middleware stack */
    private array $globalMiddleware = [];
    /** @var array<string, array<string>> Group middleware stack */
    private array $groupStack = [];
    private string $currentPrefix = '';

    public function __construct(?RadixTree $tree = null)
    {
        $this->tree = $tree ?? new RadixTree();
    }

    public function get(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('GET', $path, $handler, $name, $middleware);
    }

    public function post(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('POST', $path, $handler, $name, $middleware);
    }

    public function put(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('PUT', $path, $handler, $name, $middleware);
    }

    public function delete(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('DELETE', $path, $handler, $name, $middleware);
    }

    public function patch(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('PATCH', $path, $handler, $name, $middleware);
    }

    public function options(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('OPTIONS', $path, $handler, $name, $middleware);
    }

    public function head(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        return $this->addRoute('HEAD', $path, $handler, $name, $middleware);
    }

    public function any(string $path, string $handler, string $name = '', array $middleware = []): self
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'] as $m) {
            $this->addRoute($m, $path, $handler, $name, $middleware);
        }
        return $this;
    }

    /**
     * Group routes under a common prefix and middleware.
     *
     * @param string $prefix
     * @param callable $callback Receives this Router instance
     * @param array<string> $middleware
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $prevPrefix = $this->currentPrefix;
        $this->currentPrefix .= '/' . trim($prefix, '/');
        $prevMiddleware = $this->globalMiddleware;
        $this->globalMiddleware = array_merge($this->globalMiddleware, $middleware);
        $callback($this);
        $this->currentPrefix = $prevPrefix;
        $this->globalMiddleware = $prevMiddleware;
        return $this;
    }

    public function addGlobalMiddleware(string $class): self
    {
        $this->globalMiddleware[] = $class;
        return $this;
    }

    public function dispatch(string $method, string $uri): ?MatchResult
    {
        return $this->tree->match($method, $uri);
    }

    /** @return array<string> */
    public function getAllowedMethods(string $uri): array
    {
        return $this->tree->matchAnyMethod($uri);
    }

    public function url(string $name, array $params = []): string
    {
        return $this->tree->generate($name, $params);
    }

    public function getTree(): RadixTree
    {
        return $this->tree;
    }

    public function getRouteCount(): int
    {
        return $this->tree->getRouteCount();
    }

    private function addRoute(string $method, string $path, string $handler, string $name, array $middleware): self
    {
        $fullPath = $this->currentPrefix . '/' . trim($path, '/');
        $allMiddleware = array_merge($this->globalMiddleware, $middleware);
        $parts = $this->parseHandler($handler);
        $this->tree->insert($method, $fullPath, $handler, $parts[0], $parts[1], $allMiddleware, $name);
        return $this;
    }

    /** @return array{0: string, 1: string} */
    private function parseHandler(string $handler): array
    {
        $pos = strpos($handler, '@');
        if ($pos !== false) {
            return [substr($handler, 0, $pos), substr($handler, $pos + 1)];
        }
        $pos = strpos($handler, '::');
        if ($pos !== false) {
            return [substr($handler, 0, $pos), substr($handler, $pos + 2)];
        }
        return [$handler, '__invoke'];
    }
}
