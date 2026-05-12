<?php

declare(strict_types=1);

namespace Aether\Cache;

/**
 * Shared Memory Cache — cross-process caching via shmop.
 *
 * Uses PHP's shmop extension to share cached data across
 * worker processes without serialization to disk.
 *
 * @package Aether\Cache
 */
final class SharedMemoryCache
{
    private int $segmentSize;
    private int $shmKey;
    /** @var \Shmop|false */
    private mixed $segment = false;
    private bool $opened = false;

    /**
     * @param int $key       System V IPC key (ftok-derived or manual)
     * @param int $size      Segment size in bytes (default 1MB)
     */
    public function __construct(int $key = 0x4145, int $size = 1_048_576)
    {
        $this->shmKey = $key;
        $this->segmentSize = $size;
    }

    /**
     * Open or create the shared memory segment.
     */
    public function open(): void
    {
        if ($this->opened) {
            return;
        }

        if (!extension_loaded('shmop')) {
            throw new \RuntimeException("shmop extension is required for SharedMemoryCache");
        }

        // Try to open existing segment
        $this->segment = @shmop_open($this->shmKey, 'w', 0, 0);

        if ($this->segment === false) {
            // Create new segment
            $this->segment = shmop_open($this->shmKey, 'c', 0644, $this->segmentSize);
            if ($this->segment === false) {
                throw new \RuntimeException("Failed to create shared memory segment");
            }
            // Initialize with empty data
            $this->writeRaw($this->serialize([]));
        }

        $this->opened = true;
    }

    /**
     * Get a cached value.
     *
     * @return mixed|null Returns null if key not found or expired
     */
    public function get(string $key): mixed
    {
        $this->open();
        $data = $this->readAll();

        if (!isset($data[$key])) {
            return null;
        }

        $entry = $data[$key];

        // Check TTL
        if ($entry['ttl'] > 0 && time() > $entry['created'] + $entry['ttl']) {
            $this->delete($key);
            return null;
        }

        return $entry['value'];
    }

    /**
     * Set a cached value.
     *
     * @param string $key
     * @param mixed $value Must be serializable
     * @param int $ttl Time-to-live in seconds (0 = forever)
     */
    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->open();
        $data = $this->readAll();

        $data[$key] = [
            'value' => $value,
            'ttl' => $ttl,
            'created' => time(),
        ];

        $this->writeRaw($this->serialize($data));
    }

    /**
     * Check if a key exists (and is not expired).
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Delete a key.
     */
    public function delete(string $key): void
    {
        $this->open();
        $data = $this->readAll();
        unset($data[$key]);
        $this->writeRaw($this->serialize($data));
    }

    /**
     * Clear all cached data.
     */
    public function flush(): void
    {
        $this->open();
        $this->writeRaw($this->serialize([]));
    }

    /**
     * Get cache statistics.
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $this->open();
        $data = $this->readAll();
        $size = shmop_size($this->segment);

        return [
            'entries' => count($data),
            'segment_size' => $size,
            'segment_key' => $this->shmKey,
        ];
    }

    /**
     * Close the shared memory segment.
     */
    public function close(): void
    {
        $this->segment = false;
        $this->opened = false;
    }

    /**
     * Destroy the shared memory segment entirely.
     */
    public function destroy(): void
    {
        if ($this->opened && $this->segment !== false) {
            shmop_delete($this->segment);
            $this->segment = false;
            $this->opened = false;
        }
    }

    // ── Private ──

    /** @return array<string, array{value: mixed, ttl: int, created: int}> */
    private function readAll(): array
    {
        if ($this->segment === false) {
            return [];
        }

        $size = shmop_size($this->segment);
        $raw = shmop_read($this->segment, 0, $size);

        if ($raw === false || $raw === '') {
            return [];
        }

        // Find the null terminator (data is null-padded)
        $nullPos = strpos($raw, "\0");
        if ($nullPos !== false) {
            $raw = substr($raw, 0, $nullPos);
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $data = $this->unserialize($raw);
        return is_array($data) ? $data : [];
    }

    private function writeRaw(string $data): void
    {
        if ($this->segment === false) {
            return;
        }

        $padded = str_pad($data, $this->segmentSize, "\0");
        shmop_write($this->segment, $padded, 0);
    }

    private function serialize(mixed $data): string
    {
        return \serialize($data);
    }

    private function unserialize(string $raw): mixed
    {
        return @\unserialize($raw);
    }
}
