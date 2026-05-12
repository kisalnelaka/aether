<?php

declare(strict_types=1);

namespace Aether\AOT;

use Aether\Router\RadixTree;
use Aether\Router\RouteCompiler;

/**
 * AOT Compiler — orchestrates all ahead-of-time compilation steps.
 *
 * Coordinates:
 * 1. Route scanning from attributes → Radix Tree → compiled PHP file
 * 2. Dependency scanning → Hydrator factories → compiled PHP file
 * 3. Class map generation → Autoloader optimization
 *
 * @package Aether\AOT
 */
final class Compiler
{
    private RouteScanner $routeScanner;
    private HydratorGenerator $hydratorGenerator;
    private RouteCompiler $routeCompiler;

    public function __construct()
    {
        $this->routeScanner = new RouteScanner();
        $this->hydratorGenerator = new HydratorGenerator();
        $this->routeCompiler = new RouteCompiler();
    }

    /**
     * Run the full AOT compilation pipeline.
     *
     * @param array<string, string> $controllerPaths Namespace => directory
     * @param array<string, string> $servicePaths    Namespace => directory
     * @param string $cacheDir Output directory for compiled files
     * @return array<string, mixed> Compilation report
     */
    public function compile(
        array $controllerPaths,
        array $servicePaths,
        string $cacheDir,
    ): array {
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $report = [
            'routes' => 0,
            'hydrators' => 0,
            'classmap_entries' => 0,
            'time_ms' => 0,
            'files_generated' => [],
        ];

        $start = hrtime(true);

        // Dump the radix tree to PHP. OpCache will optimize this better than you ever could.
        $routesFile = $cacheDir . DIRECTORY_SEPARATOR . 'compiled_routes.php';
        $tree = new RadixTree();

        foreach ($controllerPaths as $namespace => $directory) {
            $routes = $this->routeScanner->scanDirectory($directory, $namespace);
            foreach ($routes as $route) {
                $tree->insert(
                    $route['method'],
                    $route['path'],
                    $route['handler'],
                    $route['controllerClass'],
                    $route['controllerMethod'],
                    $route['middleware'],
                    $route['name'] ?? '',
                );
            }
            $report['routes'] += count($routes);
        }

        $this->routeCompiler->compile($tree, $routesFile);
        $report['files_generated'][] = $routesFile;

        // Generate static factories to avoid Reflection. Because Reflection is slow.
        $hydratorsFile = $cacheDir . DIRECTORY_SEPARATOR . 'compiled_hydrators.php';
        $allPaths = array_merge($controllerPaths, $servicePaths);

        foreach ($allPaths as $namespace => $directory) {
            $this->hydratorGenerator->generate($directory, $namespace, $hydratorsFile);
            // Count classes
            $classes = $this->countPhpFiles($directory);
            $report['hydrators'] += $classes;
        }

        $report['files_generated'][] = $hydratorsFile;

        // 3. Generate class map
        $classMapFile = $cacheDir . DIRECTORY_SEPARATOR . 'compiled_classmap.php';
        $classMap = $this->buildClassMap($allPaths);
        $report['classmap_entries'] = count($classMap);

        $this->writeClassMap($classMap, $classMapFile);
        $report['files_generated'][] = $classMapFile;

        $report['time_ms'] = (hrtime(true) - $start) / 1_000_000;

        return $report;
    }

    /**
     * Clear all compiled cache files.
     */
    public function clearCache(string $cacheDir): void
    {
        $files = ['compiled_routes.php', 'compiled_hydrators.php', 'compiled_classmap.php'];
        foreach ($files as $file) {
            $path = $cacheDir . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param array<string, string> $paths Namespace => directory
     * @return array<string, string> FQCN => file path
     */
    private function buildClassMap(array $paths): array
    {
        $map = [];
        foreach ($paths as $namespace => $directory) {
            $baseDir = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            $files = $this->findPhpFiles($directory);
            foreach ($files as $file) {
                if (strncmp($file, $baseDir, strlen($baseDir)) !== 0) continue;
                $relative = substr($file, strlen($baseDir));
                if (str_ends_with($relative, '.php')) {
                    $relative = substr($relative, 0, -4);
                }
                $class = $namespace . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
                $map[$class] = $file;
            }
        }
        return $map;
    }

    private function writeClassMap(array $map, string $outputPath): void
    {
        $content = "<?php\n\ndeclare(strict_types=1);\n\n";
        $content .= "/**\n * AUTO-GENERATED class map.\n * Generated: " . date('Y-m-d H:i:s') . "\n */\n\n";
        $content .= "return " . var_export($map, true) . ";\n";

        $dir = dirname($outputPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($outputPath, $content, LOCK_EX);
    }

    private function countPhpFiles(string $dir): int
    {
        $count = 0;
        $items = scandir($dir);
        if ($items === false) return 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) { $count += $this->countPhpFiles($path); }
            elseif (str_ends_with($item, '.php')) { $count++; }
        }
        return $count;
    }

    /** @return array<string> */
    private function findPhpFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);
        if ($items === false) return $files;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) { $files = array_merge($files, $this->findPhpFiles($path)); }
            elseif (str_ends_with($item, '.php')) { $files[] = $path; }
        }
        return $files;
    }
}
