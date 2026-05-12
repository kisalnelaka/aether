<?php

declare(strict_types=1);

namespace Aether\Kernel;

use Aether\Http\Request;
use Aether\Http\Response;

/**
 * Worker Manager — persistent process spawning and signal handling.
 *
 * Manages worker processes that keep the framework resident in memory.
 * Compatible with RoadRunner (via stdin/stdout pipes) and custom
 * socket-based workers. Handles SIGTERM for graceful shutdown
 * and SIGUSR2 for hot-reload.
 *
 * @package Aether\Kernel
 */
final class WorkerManager
{
    private Application $app;
    private int $workerCount;
    private bool $running = false;
    /** @var array<int, int> PID => worker index */
    private array $workers = [];
    private string $mode;
    private int $requestsHandled = 0;
    private int $maxRequests;

    /**
     * @param Application $app
     * @param int $workerCount Number of worker processes
     * @param string $mode Worker mode: 'roadrunner', 'socket', 'stdio'
     * @param int $maxRequests Max requests per worker before recycle (0 = unlimited)
     */
    public function __construct(
        Application $app,
        int $workerCount = 4,
        string $mode = 'stdio',
        int $maxRequests = 10000,
    ) {
        $this->app = $app;
        $this->workerCount = $workerCount;
        $this->mode = $mode;
        $this->maxRequests = $maxRequests;
    }

    /**
     * Start the worker manager.
     *
     * On Unix: forks worker processes.
     * On Windows: runs in single-process mode.
     */
    public function start(): void
    {
        $this->running = true;

        $this->log("AETHER Worker Manager starting ({$this->workerCount} workers, mode: {$this->mode})");

        // Register signal handlers (Unix only. If you're on Windows, good luck.)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGUSR2, [$this, 'handleSignal']);
        }

        if ($this->canFork()) {
            $this->startForked();
        } else {
            $this->startSingleProcess();
        }
    }

    /**
     * Signal handler.
     */
    public function handleSignal(int $signal): void
    {
        match ($signal) {
            SIGTERM, SIGINT => $this->gracefulShutdown(),
            SIGUSR2 => $this->hotReload(),
            default => null,
        };
    }

    /**
     * Stop all workers.
     */
    public function stop(): void
    {
        $this->running = false;
        $this->log("Shutting down workers...");

        foreach ($this->workers as $pid => $index) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
        }

        // Wait for children to die so we don't end up with zombies
        while (count($this->workers) > 0) {
            $pid = pcntl_waitpid(-1, $status);
            if ($pid > 0) {
                unset($this->workers[$pid]);
                $this->log("Worker PID {$pid} exited");
            }
        }
    }

    public function isRunning(): bool { return $this->running; }
    public function getRequestsHandled(): int { return $this->requestsHandled; }

    // ── Private ──

    private function startForked(): void
    {
        for ($i = 0; $i < $this->workerCount; $i++) {
            $this->spawnWorker($i);
        }

        // Master process: monitor children
        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $pid = pcntl_waitpid(-1, $status, WNOHANG);

            if ($pid > 0 && isset($this->workers[$pid])) {
                $index = $this->workers[$pid];
                unset($this->workers[$pid]);
                $this->log("Worker PID {$pid} exited, respawning...");

                if ($this->running) {
                    $this->spawnWorker($index);
                }
            }

            usleep(10_000); // 10ms tick. Don't touch this, you'll burn CPU.
        }

        $this->stop();
    }

    private function spawnWorker(int $index): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new \RuntimeException("Failed to fork worker {$index}");
        }

        if ($pid === 0) {
            // Child process
            $this->workerLoop($index);
            exit(0);
        }

        // Parent
        $this->workers[$pid] = $index;
        $this->log("Worker {$index} started (PID: {$pid})");
    }

    private function workerLoop(int $index): void
    {
        $this->log("Worker {$index} ready");

        // Boot the app once. If you try to reboot it per request, I will find you.
        $kernel = $this->app->getKernel();
        $kernel->boot();

        $handled = 0;

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $request = $this->receiveRequest();
            if ($request === null) {
                usleep(1000);
                continue;
            }

            $response = $kernel->handle($request);
            $this->sendResponse($response);

            $handled++;

            if ($this->maxRequests > 0 && $handled >= $this->maxRequests) {
                $this->log("Worker {$index} reached max requests ({$this->maxRequests}), recycling");
                break;
            }
        }
    }

    private function startSingleProcess(): void
    {
        $this->log("Running in single-process mode (no pcntl)");
        $this->workerLoop(0);
    }

    private function receiveRequest(): ?Request
    {
        // stdin/stdout protocol. It's RoadRunner compatible because writing our own proxy is a waste of time.
        if ($this->mode === 'stdio' || $this->mode === 'roadrunner') {
            $header = fread(STDIN, 4);
            if ($header === false || strlen($header) < 4) {
                return null;
            }

            $length = unpack('N', $header)[1];
            $payload = '';
            $remaining = $length;

            while ($remaining > 0) {
                $chunk = fread(STDIN, min($remaining, 65536));
                if ($chunk === false) break;
                $payload .= $chunk;
                $remaining -= strlen($chunk);
            }

            $data = json_decode($payload, true);
            if (!is_array($data)) {
                return null;
            }

            return new Request(
                $data['method'] ?? 'GET',
                $data['uri'] ?? '/',
                $data['headers'] ?? [],
                $data['body'] ?? '',
                $data['server'] ?? [],
                $data['cookies'] ?? [],
                $data['post'] ?? [],
            );
        }

        return null;
    }

    private function sendResponse(Response $response): void
    {
        if ($this->mode === 'stdio' || $this->mode === 'roadrunner') {
            $payload = json_encode([
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
                'body' => $response->getBody(),
            ], JSON_UNESCAPED_UNICODE);

            $header = pack('N', strlen($payload));
            fwrite(STDOUT, $header . $payload);
            fflush(STDOUT);
        }
    }

    private function gracefulShutdown(): void
    {
        $this->log("Received shutdown signal");
        $this->running = false;
    }

    private function hotReload(): void
    {
        $this->log("Hot reload requested — recycling workers");
        foreach ($this->workers as $pid => $index) {
            if (function_exists('posix_kill')) {
                posix_kill($pid, SIGTERM);
            }
        }
    }

    private function canFork(): bool
    {
        return function_exists('pcntl_fork') && PHP_OS_FAMILY !== 'Windows';
    }

    private function log(string $message): void
    {
        $time = date('Y-m-d H:i:s');
        fwrite(STDERR, "[{$time}] [AETHER] {$message}\n");
    }
}
