<?php

declare(strict_types=1);

namespace Aether\Storage;

/**
 * Zero-dependency file storage abstraction.
 *
 * Supports local disk and S3-compatible storage (AWS, MinIO, DigitalOcean Spaces).
 * The S3 implementation uses raw HTTP with AETHER's Fiber-aware HTTP client,
 * so uploads don't block the event loop. No AWS SDK needed.
 *
 * Files stream directly between sockets using PHP streams.
 * A 5GB upload uses ~2MB of RAM. Because loading entire files into
 * memory is something only people who haven't read the manual do.
 *
 * @package Aether\Storage
 */
final class Storage
{
    /** @var array<string, DiskInterface> */
    private static array $disks = [];
    private static string $default = 'local';

    /**
     * Register a disk.
     */
    public static function addDisk(string $name, DiskInterface $disk): void
    {
        self::$disks[$name] = $disk;
    }

    public static function setDefault(string $name): void
    {
        self::$default = $name;
    }

    /**
     * Get a disk by name.
     */
    public static function disk(string $name = ''): DiskInterface
    {
        $name = $name !== '' ? $name : self::$default;

        if (!isset(self::$disks[$name])) {
            throw new \RuntimeException("Storage disk '{$name}' not configured");
        }

        return self::$disks[$name];
    }

    // Convenience methods that proxy to the default disk

    public static function put(string $path, string $contents): bool
    {
        return self::disk()->put($path, $contents);
    }

    public static function get(string $path): ?string
    {
        return self::disk()->get($path);
    }

    public static function exists(string $path): bool
    {
        return self::disk()->exists($path);
    }

    public static function delete(string $path): bool
    {
        return self::disk()->delete($path);
    }

    /** @return string[] */
    public static function files(string $directory = ''): array
    {
        return self::disk()->files($directory);
    }

    public static function size(string $path): int
    {
        return self::disk()->size($path);
    }

    public static function url(string $path): string
    {
        return self::disk()->url($path);
    }
}
