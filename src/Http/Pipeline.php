<?php

declare(strict_types=1);

namespace Aether\Http;

/**
 * Middleware Pipeline — executes middleware in FIFO order.
 *
 * @package Aether\Http
 */
final class Pipeline
{
    /** @var array<MiddlewareInterface> */
    private array $middleware = [];

    /** @param callable(Request): Response $coreHandler */
    private $coreHandler;

    /**
     * @param callable(Request): Response $coreHandler The final handler if all middleware pass
     */
    public function __construct(callable $coreHandler)
    {
        $this->coreHandler = $coreHandler;
    }

    /**
     * Add middleware to the pipeline.
     */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Add multiple middleware instances.
     *
     * @param array<MiddlewareInterface> $middlewares
     */
    public function pipeAll(array $middlewares): self
    {
        foreach ($middlewares as $m) {
            $this->middleware[] = $m;
        }
        return $this;
    }

    /**
     * Execute the pipeline with the given request.
     */
    public function run(Request $request): Response
    {
        $runner = $this->buildChain(0);
        return $runner($request);
    }

    /**
     * Build the chain recursively. Each middleware wraps the next.
     *
     * @return callable(Request): Response
     */
    private function buildChain(int $index): callable
    {
        if ($index >= count($this->middleware)) {
            return $this->coreHandler;
        }

        $current = $this->middleware[$index];
        $next = $this->buildChain($index + 1);

        return static function (Request $request) use ($current, $next): Response {
            return $current->handle($request, $next);
        };
    }
}
