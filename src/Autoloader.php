<?php

declare(strict_types=1);

namespace Aether;

/**
 * PSR-4 compatible autoloader — zero Composer dependency.
 *
 * Maps the root namespace prefix to a base directory and resolves
 * class names to file paths using byte-level string operations
 * (no preg_match). Supports nested namespaces and optional
 * class-map caching for AOT-compiled builds.
 *
 * @package Aether
 */
final class Autoloader
{
    /** @var array<string, string> Namespace => directory */
    private array $prefixes = [];

    /** @var array<string, string> Optional pre-compiled class map */
    private array $classMap = [];

    /** @var array<string, true> Track loaded classes for diagnostics */
    private array $loaded = [];

    private static ?self $instance = null;

    public function __construct()
    {
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function addNamespace(string $prefix, string $baseDir): void
    {
        $this->prefixes[$prefix] = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Register this autoloader with spl_autoload.
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'loadClass'], true, true);
    }

    /**
     * Unregister this autoloader.
     */
    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'loadClass']);
    }

    /**
     * Load a pre-compiled class map for AOT builds.
     *
     * @param array<string, string> $map Fully qualified class name => file path
     */
    public function addClassMap(array $map): void
    {
        $this->classMap = $map + $this->classMap;
    }

    /**
     * Attempt to load a class by its fully qualified name.
     *
     * Resolution order:
     * 1. Check the pre-compiled class map.
     * 2. Convert namespace separators to directory separators.
     *
     * @param string $class Fully qualified class name
     * @return bool True if the class file was loaded
     */
    public function loadClass(string $class): bool
    {
        // Fast path: class map lookup
        if (isset($this->classMap[$class])) {
            $file = $this->classMap[$class];
            if (is_file($file)) {
                require $file;
                $this->loaded[$class] = true;
                return true;
            }
        }

        foreach ($this->prefixes as $prefix => $baseDir) {
            $len = strlen($prefix);
            if (strncmp($class, $prefix . '\\', $len + 1) === 0) {
                $relativeClass = substr($class, $len + 1);
                $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

                if (is_file($file)) {
                    require $file;
                    $this->loaded[$class] = true;
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get list of all classes loaded by this autoloader.
     *
     * @return array<string>
     */
    public function getLoadedClasses(): array
    {
        return array_keys($this->loaded);
    }

    /**
     * Check if a specific class was loaded by this autoloader.
     */
    public function isLoaded(string $class): bool
    {
        return isset($this->loaded[$class]);
    }
}
