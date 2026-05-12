<?php

declare(strict_types=1);

namespace Aether\AOT;

use Aether\Attributes\Controller;
use Aether\Attributes\Route;
use Aether\Attributes\Get;
use Aether\Attributes\Post;
use Aether\Attributes\Put;
use Aether\Attributes\Delete;
use Aether\Attributes\Patch;
use Aether\Attributes\Middleware as MiddlewareAttr;

/**
 * Route Scanner — discovers routes from PHP 8 Attributes.
 *
 * Scans controller classes for #[Route], #[Get], #[Post], etc.
 * attributes and returns structured route definitions. Uses
 * Reflection only at build time (AOT), never at runtime.
 *
 * @package Aether\AOT
 */
final class RouteScanner
{
    /**
     * Scan a directory for controller classes with route attributes.
     *
     * @param string $directory Absolute path to controller directory
     * @param string $namespace Base namespace for controllers
     * @return array<int, array<string, mixed>> Route definitions
     */
    public function scanDirectory(string $directory, string $namespace): array
    {
        $routes = [];
        $files = $this->findPhpFiles($directory);

        foreach ($files as $file) {
            $className = $this->fileToClassName($file, $directory, $namespace);
            if ($className === null || !class_exists($className)) {
                continue;
            }
            $classRoutes = $this->scanClass($className);
            foreach ($classRoutes as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * Scan a single class for route attributes.
     *
     * @param string $className Fully qualified class name
     * @return array<int, array<string, mixed>>
     */
    public function scanClass(string $className): array
    {
        $routes = [];
        $ref = new \ReflectionClass($className);

        // Get class-level prefix and middleware
        $prefix = '';
        $classMiddleware = [];

        $controllerAttrs = $ref->getAttributes(Controller::class);
        if (count($controllerAttrs) > 0) {
            $controller = $controllerAttrs[0]->newInstance();
            $prefix = $controller->prefix;
            $classMiddleware = $controller->middleware;
        }

        // Scan class-level middleware attributes
        $mwAttrs = $ref->getAttributes(MiddlewareAttr::class);
        foreach ($mwAttrs as $mwAttr) {
            $mw = $mwAttr->newInstance();
            $classMiddleware[] = $mw->class;
        }

        // Scan each public method
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor()) {
                continue;
            }

            $routeAttrs = array_merge(
                $method->getAttributes(Route::class),
                $method->getAttributes(Get::class),
                $method->getAttributes(Post::class),
                $method->getAttributes(Put::class),
                $method->getAttributes(Delete::class),
                $method->getAttributes(Patch::class),
            );

            // Method-level middleware
            $methodMiddleware = [];
            $methodMwAttrs = $method->getAttributes(MiddlewareAttr::class);
            foreach ($methodMwAttrs as $mwAttr) {
                $mw = $mwAttr->newInstance();
                $methodMiddleware[] = $mw->class;
            }

            foreach ($routeAttrs as $attr) {
                $route = $attr->newInstance();
                $fullPath = rtrim($prefix, '/') . '/' . ltrim($route->path, '/');
                $allMiddleware = array_merge($classMiddleware, $route->middleware, $methodMiddleware);

                $routes[] = [
                    'method' => $route->method,
                    'path' => $fullPath,
                    'handler' => $className . '@' . $method->getName(),
                    'controllerClass' => $className,
                    'controllerMethod' => $method->getName(),
                    'middleware' => array_unique($allMiddleware),
                    'name' => $route->name,
                ];
            }
        }

        return $routes;
    }

    /**
     * @return array<string>
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        $items = scandir($directory);
        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $files = array_merge($files, $this->findPhpFiles($path));
            } elseif (str_ends_with($item, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function fileToClassName(string $file, string $baseDir, string $namespace): ?string
    {
        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncmp($file, $baseDir, strlen($baseDir)) !== 0) {
            return null;
        }
        $relative = substr($file, strlen($baseDir));
        // Remove .php extension
        if (str_ends_with($relative, '.php')) {
            $relative = substr($relative, 0, -4);
        }
        $className = $namespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        return $className;
    }
}
