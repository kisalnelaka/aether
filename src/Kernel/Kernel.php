<?php

declare(strict_types=1);

namespace Aether\Kernel;

use Aether\Container\Container;
use Aether\Container\ServiceScope;
use Aether\Router\Router;
use Aether\Router\RouteCompiler;
use Aether\Router\MatchResult;
use Aether\Http\Request;
use Aether\Http\Response;
use Aether\Http\Pipeline;
use Aether\Http\MiddlewareInterface;
use Aether\Fiber\Scheduler;
use Aether\Cache\SharedMemoryCache;

/**
 * AETHER Kernel — the central nervous system.
 *
 * Coordinates the request lifecycle:
 * 1. Receives a Request (from CGI, RoadRunner, or Swoole)
 * 2. Matches the route via the Radix Tree
 * 3. Resolves the controller from the DI Container
 * 4. Executes the middleware pipeline
 * 5. Returns a Response
 * 6. Resets ephemeral services for the next request
 *
 * @package Aether\Kernel
 */
final class Kernel
{
    private Container $container;
    private Router $router;
    private Scheduler $scheduler;
    private bool $booted = false;
    /** @var array<string> Global middleware stack */
    private array $middleware = [];
    /** @var callable|null Error handler */
    private $errorHandler;

    public function __construct(
        Container $container,
        Router $router,
        Scheduler $scheduler,
    ) {
        $this->container = $container;
        $this->router = $router;
        $this->scheduler = $scheduler;
    }

    /**
     * Boot the kernel (called once in persistent mode).
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        // Register core services
        $this->container->instance(self::class, $this);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(Scheduler::class, $this->scheduler);
        $this->container->instance(Container::class, $this->container);

        // Load compiled routes. If you didn't compile them, it scans attributes and wastes CPU.
        $compiledPath = (defined('AETHER_CACHE') ? AETHER_CACHE : '') . '/compiled_routes.php';
        if (is_file($compiledPath)) {
            $compiler = new RouteCompiler();
            $tree = $compiler->load($compiledPath);
            // Re-wrap the tree in the router
            $this->router = new Router($tree);
            $this->container->instance(Router::class, $this->router);
        }

        // Load compiled hydrators. Same deal. Compile your code before deploying.
        $hydratorsPath = (defined('AETHER_CACHE') ? AETHER_CACHE : '') . '/compiled_hydrators.php';
        if (is_file($hydratorsPath)) {
            $loader = require $hydratorsPath;
            if (is_callable($loader)) {
                $loader($this->container);
            }
        }

        $this->booted = true;
    }

    /**
     * Handle an HTTP request and produce a response.
     */
    public function handle(Request $request): Response
    {
        if (!$this->booted) {
            $this->boot();
        }

        try {
            // Register request as ephemeral service
            $this->container->ephemeral(Request::class);
            $this->container->instance(Request::class, $request);

            // Match route
            $match = $this->router->dispatch($request->getMethod(), $request->getPath());

            if ($match === null) {
                // Check for method not allowed
                $allowed = $this->router->getAllowedMethods($request->getPath());
                if (count($allowed) > 0) {
                    return Response::json(
                        ['error' => 'Method Not Allowed', 'allowed' => $allowed],
                        405,
                    )->withHeader('Allow', implode(', ', $allowed));
                }
                return Response::notFound();
            }

            // Inject route params into request
            $request = $request->withRouteParams($match->params);
            $this->container->instance(Request::class, $request);

            // Build middleware pipeline
            $coreHandler = fn(Request $req): Response => $this->dispatchController($match, $req);
            $pipeline = new Pipeline($coreHandler);

            // Global middleware
            foreach ($this->middleware as $mwClass) {
                $mw = $this->container->resolve($mwClass);
                if ($mw instanceof MiddlewareInterface) {
                    $pipeline->pipe($mw);
                }
            }

            // Route-specific middleware
            foreach ($match->route->middleware as $mwClass) {
                $mw = $this->container->resolve($mwClass);
                if ($mw instanceof MiddlewareInterface) {
                    $pipeline->pipe($mw);
                }
            }

            return $pipeline->run($request);

        } catch (\Throwable $e) {
            return $this->handleError($e);
        } finally {
            // Trash ephemeral services. If you put request state in a persistent service, you're leaking data.
            $this->container->resetEphemerals();
        }
    }

    /**
     * Add global middleware.
     */
    public function addMiddleware(string $class): self
    {
        $this->middleware[] = $class;
        return $this;
    }

    /**
     * Set a custom error handler.
     */
    public function setErrorHandler(callable $handler): self
    {
        $this->errorHandler = $handler;
        return $this;
    }

    public function getContainer(): Container { return $this->container; }
    public function getRouter(): Router { return $this->router; }
    public function getScheduler(): Scheduler { return $this->scheduler; }
    public function isBooted(): bool { return $this->booted; }

    // ── Private ──

    private function dispatchController(MatchResult $match, Request $request): Response
    {
        $controllerClass = $match->route->controllerClass;
        $method = $match->route->controllerMethod;

        if ($controllerClass === '' || $method === '') {
            return Response::error('Route handler not configured', 500);
        }

        $controller = $this->container->resolve($controllerClass);
        $result = $controller->{$method}($request);

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return Response::json($result);
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        return Response::noContent();
    }

    private function handleError(\Throwable $e): Response
    {
        if ($this->errorHandler !== null) {
            try {
                $result = ($this->errorHandler)($e);
                if ($result instanceof Response) {
                    return $result;
                }
            } catch (\Throwable $inner) {
                // Fall through to default
            }
        }

        $status = 500;
        $payload = [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ];

        // Dump the trace in debug mode so you can see where you screwed up
        if (getenv('AETHER_DEBUG') === 'true') {
            $payload['trace'] = $e->getTraceAsString();
            $payload['file'] = $e->getFile();
            $payload['line'] = $e->getLine();
        }

        return Response::json($payload, $status);
    }
}
