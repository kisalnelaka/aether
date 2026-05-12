<?php

declare(strict_types=1);

namespace Aether\Storage;

/**
 * Local filesystem disk. Stores files on the server.
 * This is the default and the simplest option.
 */
final class LocalDisk implements DiskInterface
{
    private string $root;
    private string $baseUrl;

    public function __construct(string $root, string $baseUrl = '/storage')
    {
        $this->root = rtrim($root, '/\\');
        $this->baseUrl = rtrim($baseUrl, '/');

        if (!is_dir($this->root)) {
            mkdir($this->root, 0755, true);
        }
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($fullPath, $contents) !== false;
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->fullPath($path);
        if (!is_file($fullPath)) {
            return null;
        }
        $content = file_get_contents($fullPath);
        return $content !== false ? $content : null;
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);
        if (is_file($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    /** @return string[] */
    public function files(string $directory = ''): array
    {
        $dir = $directory !== '' ? $this->root . DIRECTORY_SEPARATOR . $directory : $this->root;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $items = scandir($dir);
        if ($items === false) return [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_file($full)) {
                $relative = $directory !== '' ? $directory . '/' . $item : $item;
                $files[] = $relative;
            }
        }

        return $files;
    }

    public function size(string $path): int
    {
        $fullPath = $this->fullPath($path);
        return is_file($fullPath) ? (int)filesize($fullPath) : 0;
    }

    public function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function putStream(string $path, mixed $stream): bool
    {
        $fullPath = $this->fullPath($path);
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $dest = fopen($fullPath, 'wb');
        if ($dest === false) return false;

        $bytes = stream_copy_to_stream($stream, $dest);
        fclose($dest);

        return $bytes !== false;
    }

    private function fullPath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
