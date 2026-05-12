<?php

declare(strict_types=1);

namespace Aether\Kernel;

use Aether\Container\Container;
use Aether\Router\Router;
use Aether\Fiber\Scheduler;
use Aether\Events\EventBus;
use Aether\Queue\Queue;
use Aether\View\View;
use Aether\Storage\Storage;
use Aether\Storage\LocalDisk;

/**
 * Application -- the user-facing entry point for building an AETHER app.
 *
 * Provides a fluent API for configuration, route registration, and booting.
 * All the new modules (events, queue, storage, views, websocket) are wired
 * here as lazy-loaded singletons. They only initialize when you use them.
 *
 * @package Aether\Kernel
 */
final class Application
{
    private Container $container;
    private Router $router;
    private Scheduler $scheduler;
    private Kernel $kernel;
    /** @var array<string, mixed> */
    private array $config = [];
    private bool $booted = false;

    private ?EventBus $eventBus = null;
    private ?Queue $queue = null;

    public function __construct(string $basePath = '')
    {
        $this->container = new Container();
        $this->router = new Router();
        $this->scheduler = new Scheduler();
        $this->kernel = new Kernel($this->container, $this->router, $this->scheduler);

        if ($basePath !== '') {
            $this->container->instance('path.base', $basePath);
        }
    }

    /**
     * Create a new application instance.
     */
    public static function create(string $basePath = ''): self
    {
        return new self($basePath);
    }

    // ── Configuration ──

    /**
     * Load configuration from an array or file.
     *
     * @param string|array<string, mixed> $config
     */
    public function configure(string|array $config): self
    {
        if (is_string($config) && is_file($config)) {
            $loaded = require $config;
            if (is_array($loaded)) {
                $this->config = array_merge($this->config, $loaded);
            }
        } elseif (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }

        return $this;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    // ── Routing ──

    public function get(string $path, string $handler, string $name = ''): self
    {
        $this->router->get($path, $handler, $name);
        return $this;
    }

    public function post(string $path, string $handler, string $name = ''): self
    {
        $this->router->post($path, $handler, $name);
        return $this;
    }

    public function put(string $path, string $handler, string $name = ''): self
    {
        $this->router->put($path, $handler, $name);
        return $this;
    }

    public function delete(string $path, string $handler, string $name = ''): self
    {
        $this->router->delete($path, $handler, $name);
        return $this;
    }

    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $this->router->group($prefix, $callback, $middleware);
        return $this;
    }

    public function getRouter(): Router { return $this->router; }

    // ── Services ──

    public function bind(string $id, callable|string|null $factory = null): self
    {
        $this->container->bind($id, $factory);
        return $this;
    }

    public function persistent(string $id, callable|string|null $factory = null): self
    {
        $this->container->persistent($id, $factory);
        return $this;
    }

    public function ephemeral(string $id, callable|string|null $factory = null): self
    {
        $this->container->ephemeral($id, $factory);
        return $this;
    }

    public function instance(string $id, mixed $value): self
    {
        $this->container->instance($id, $value);
        return $this;
    }

    public function getContainer(): Container { return $this->container; }

    // ── Middleware ──

    public function middleware(string $class): self
    {
        $this->kernel->addMiddleware($class);
        return $this;
    }

    // ── Events ──

    /**
     * Get the event bus. Created lazily.
     */
    public function events(): EventBus
    {
        if ($this->eventBus === null) {
            $this->eventBus = new EventBus($this->container);
            $this->container->instance(EventBus::class, $this->eventBus);
        }
        return $this->eventBus;
    }

    /**
     * Register an event listener.
     */
    public function on(string $event, string $listenerClass, string $method = 'handle'): self
    {
        $this->events()->listen($event, $listenerClass, $method);
        return $this;
    }

    // ── Queue ──

    /**
     * Get the job queue. Created lazily.
     */
    public function queue(): Queue
    {
        if ($this->queue === null) {
            $this->queue = new Queue($this->container, $this->scheduler);
            $this->container->instance(Queue::class, $this->queue);
        }
        return $this->queue;
    }

    // ── Views ──

    /**
     * Configure the view engine paths.
     */
    public function views(string $viewPath, string $layoutPath = ''): self
    {
        View::configure($viewPath, $layoutPath);
        return $this;
    }

    // ── Storage ──

    /**
     * Configure local file storage.
     */
    public function storage(string $storagePath, string $baseUrl = '/storage'): self
    {
        Storage::addDisk('local', new LocalDisk($storagePath, $baseUrl));
        Storage::setDefault('local');
        return $this;
    }

    // ── Lifecycle ──

    /**
     * Boot the application and start handling requests.
     *
     * In traditional mode: handles a single CGI request.
     * In worker mode: the WorkerManager takes over.
     */
    public function run(): void
    {
        $this->kernel->boot();
        $this->booted = true;

        // Traditional CGI/FPM mode
        $request = \Aether\Http\Request::fromGlobals();
        $response = $this->kernel->handle($request);
        $response->send();
    }

    /**
     * Handle a single request (for worker mode).
     */
    public function handleRequest(\Aether\Http\Request $request): \Aether\Http\Response
    {
        if (!$this->booted) {
            $this->kernel->boot();
            $this->booted = true;
        }

        return $this->kernel->handle($request);
    }

    public function getKernel(): Kernel { return $this->kernel; }
    public function getScheduler(): Scheduler { return $this->scheduler; }
    public function isBooted(): bool { return $this->booted; }

    /**
     * Get boot time in milliseconds.
     */
    public function getBootTime(): float
    {
        if (defined('AETHER_START')) {
            return (hrtime(true) - AETHER_START) / 1_000_000;
        }
        return 0.0;
    }
}
