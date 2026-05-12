<?php

declare(strict_types=1);

namespace Aether\Storage;

/**
 * Storage disk contract. Implement this if you want a custom driver.
 * Or just use LocalDisk and S3Disk like a normal person.
 */
interface DiskInterface
{
    public function put(string $path, string $contents): bool;
    public function get(string $path): ?string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
    /** @return string[] */
    public function files(string $directory = ''): array;
    public function size(string $path): int;
    public function url(string $path): string;

    /**
     * Stream a file from a resource handle.
     * For large uploads. Don't load 500MB into a string.
     * @param resource $stream
     */
    public function putStream(string $path, mixed $stream): bool;
}
