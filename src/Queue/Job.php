<?php

declare(strict_types=1);

namespace Aether\Queue;

/**
 * A single job payload. Serializable so it can live in shared memory.
 */
final class Job
{
    public readonly string $id;
    public readonly float $createdAt;
    public int $attempts = 0;

    public function __construct(
        public readonly string $handler,
        public readonly string $method,
        /** @var array<string, mixed> */
        public readonly array $payload = [],
        public readonly int $maxRetries = 3,
        public readonly int $delaySeconds = 0,
        public readonly string $queue = 'default',
    ) {
        $this->id = bin2hex(random_bytes(8));
        $this->createdAt = microtime(true);
    }

    public function shouldRetry(): bool
    {
        return $this->attempts < $this->maxRetries;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'handler' => $this->handler,
            'method' => $this->method,
            'payload' => $this->payload,
            'attempts' => $this->attempts,
            'max_retries' => $this->maxRetries,
            'delay' => $this->delaySeconds,
            'queue' => $this->queue,
            'created_at' => $this->createdAt,
        ];
    }
}
