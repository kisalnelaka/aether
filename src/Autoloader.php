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
    /** @var string The namespace prefix (e.g. "Aether") */
    private string $prefix;

    /** @var int Cached length of the prefix string */
    private int $prefixLength;

    /** @var string Base directory for the namespace */
    private string $baseDir;

    /** @var array<string, string> Optional pre-compiled class map */
    private array $classMap = [];

    /** @var array<string, true> Track loaded classes for diagnostics */
    private array $loaded = [];

    public function __construct(string $prefix, string $baseDir)
    {
        $this->prefix = $prefix;
        $this->prefixLength = strlen($prefix);
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
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

        // Check prefix match using strpos (no regex)
        if (strncmp($class, $this->prefix . '\\', $this->prefixLength + 1) !== 0) {
            return false;
        }

        // Strip the prefix and convert namespace separators to directory separators
        $relativeClass = substr($class, $this->prefixLength + 1);
        $file = $this->baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

        if (is_file($file)) {
            require $file;
            $this->loaded[$class] = true;
            return true;
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
