<?php

declare(strict_types=1);

namespace Aether\Queue;

use Aether\Container\Container;
use Aether\Fiber\Scheduler;

/**
 * In-Memory Job Queue. No Redis. No Supervisor. No external anything.
 *
 * Since AETHER runs in persistent memory, we keep a queue right here.
 * Jobs are pushed in, background Fibers pull them out and execute them.
 * If a job fails, it retries up to maxRetries times.
 *
 * For cross-process persistence, jobs can optionally be backed by
 * the SharedMemoryCache (shmop). But for most apps, in-memory is fine.
 * Your server is already running 24/7 anyway.
 *
 * @package Aether\Queue
 */
final class Queue
{
    /** @var array<string, \SplQueue<Job>> Named queues */
    private array $queues = [];

    /** @var Job[] Failed jobs (dead letter queue) */
    private array $failed = [];

    private Container $container;
    private Scheduler $scheduler;
    private bool $processing = false;

    // stats
    private int $dispatched = 0;
    private int $processed = 0;
    private int $failedCount = 0;

    public function __construct(Container $container, Scheduler $scheduler)
    {
        $this->container = $container;
        $this->scheduler = $scheduler;
    }

    /**
     * Push a job onto the queue.
     */
    public function push(Job $job): self
    {
        $queueName = $job->queue;
        if (!isset($this->queues[$queueName])) {
            $this->queues[$queueName] = new \SplQueue();
        }

        $this->queues[$queueName]->enqueue($job);
        $this->dispatched++;

        return $this;
    }

    /**
     * Dispatch a job from a class and method name.
     * Convenience wrapper so you don't have to construct Job objects manually.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $handler,
        string $method = 'handle',
        array $payload = [],
        string $queue = 'default',
        int $maxRetries = 3,
        int $delay = 0,
    ): string {
        $job = new Job($handler, $method, $payload, $maxRetries, $delay, $queue);
        $this->push($job);
        return $job->id;
    }

    /**
     * Process all pending jobs in a queue.
     * Call this from the WorkerManager's background fiber loop.
     */
    public function process(string $queueName = 'default', int $batchSize = 50): int
    {
        if (!isset($this->queues[$queueName]) || $this->queues[$queueName]->isEmpty()) {
            return 0;
        }

        $this->processing = true;
        $processed = 0;
        $queue = $this->queues[$queueName];

        while (!$queue->isEmpty() && $processed < $batchSize) {
            $job = $queue->dequeue();

            // Delayed jobs: re-enqueue if not ready yet
            if ($job->delaySeconds > 0) {
                $readyAt = $job->createdAt + $job->delaySeconds;
                if (microtime(true) < $readyAt) {
                    $queue->enqueue($job);
                    break; // come back later
                }
            }

            $job->attempts++;

            try {
                $instance = $this->container->resolve($job->handler);
                $instance->{$job->method}($job->payload);
                $this->processed++;
                $processed++;
            } catch (\Throwable $e) {
                if ($job->shouldRetry()) {
                    // Back of the line. Try again.
                    $queue->enqueue($job);
                } else {
                    // Dead letter queue
                    $this->failed[] = $job;
                    $this->failedCount++;
                    error_log("[AETHER Queue] Job {$job->id} failed permanently: " . $e->getMessage());
                }
            }
        }

        $this->processing = false;
        return $processed;
    }

    /**
     * Start a background fiber that continuously processes jobs.
     * The fiber yields between batches so it doesn't block the event loop.
     */
    public function startWorker(string $queueName = 'default', int $batchSize = 10): void
    {
        $this->scheduler->defer(function () use ($queueName, $batchSize) {
            while (true) {
                $count = $this->process($queueName, $batchSize);

                // If no jobs, sleep briefly to avoid CPU spin
                if ($count === 0) {
                    $this->scheduler->delay(0.1); // 100ms
                }

                // Yield control back to the scheduler
                if (\Fiber::getCurrent() !== null) {
                    \Fiber::suspend();
                }
            }
        });
    }

    /**
     * Get the number of pending jobs in a queue.
     */
    public function pending(string $queueName = 'default'): int
    {
        return isset($this->queues[$queueName]) ? $this->queues[$queueName]->count() : 0;
    }

    /**
     * Get failed jobs.
     * @return Job[]
     */
    public function getFailedJobs(): array
    {
        return $this->failed;
    }

    /**
     * Retry all failed jobs by pushing them back.
     */
    public function retryFailed(): int
    {
        $count = count($this->failed);
        foreach ($this->failed as $job) {
            $job->attempts = 0; // reset attempt count
            $this->push($job);
        }
        $this->failed = [];
        return $count;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $pending = 0;
        foreach ($this->queues as $q) {
            $pending += $q->count();
        }

        return [
            'dispatched' => $this->dispatched,
            'processed' => $this->processed,
            'failed' => $this->failedCount,
            'pending' => $pending,
            'queues' => count($this->queues),
            'dead_letter' => count($this->failed),
        ];
    }
}
