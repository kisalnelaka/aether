<?php

declare(strict_types=1);

namespace Aether\Http;

/**
 * Middleware interface — a handler in the request pipeline.
 *
 * @package Aether\Http
 */
interface MiddlewareInterface
{
    /**
     * Process an incoming request and produce a response.
     *
     * @param Request $request The HTTP request
     * @param callable(Request): Response $next The next handler in the chain
     * @return Response
     */
    public function handle(Request $request, callable $next): Response;
}
