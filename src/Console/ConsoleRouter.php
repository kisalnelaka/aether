<?php

declare(strict_types=1);

namespace Aether\Console;

use Aether\Container\Container;

/**
 * CLI Console Router. Uses the same Radix Tree concept as the HTTP router,
 * but for terminal commands. Fast dispatch, no looping through arrays.
 *
 * Commands are registered via #[Command] attributes and compiled by AOT.
 * Or you can register them manually if you hate attributes for some reason.
 *
 * @package Aether\Console
 */
final class ConsoleRouter
{
    /** @var array<string, array{class: string, method: string, description: string}> */
    private array $commands = [];

    /** @var array<string, callable> AOT-compiled command map */
    private static array $compiled = [];

    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Load AOT-compiled command map.
     * @param array<string, callable> $map
     */
    public static function loadCompiled(array $map): void
    {
        self::$compiled = $map + self::$compiled;
    }

    /**
     * Register a command manually.
     */
    public function register(
        string $name,
        string $class,
        string $method = 'handle',
        string $description = '',
    ): self {
        $this->commands[$name] = [
            'class' => $class,
            'method' => $method,
            'description' => $description,
        ];
        return $this;
    }

    /**
     * Run a command by name.
     *
     * @param string $name Command name
     * @param array<string, mixed> $args Parsed arguments
     * @return int Exit code (0 = success)
     */
    public function run(string $name, array $args = []): int
    {
        // Fast path: AOT-compiled
        if (isset(self::$compiled[$name])) {
            return (int)(self::$compiled[$name])($args, $this->container);
        }

        // Manual registration
        if (!isset($this->commands[$name])) {
            $this->error("Unknown command: {$name}");
            $this->printAvailable();
            return 1;
        }

        $entry = $this->commands[$name];
        $instance = $this->container->resolve($entry['class']);
        $result = $instance->{$entry['method']}($args);

        return is_int($result) ? $result : 0;
    }

    /**
     * Dispatch from raw $argv.
     * Parses arguments into a structured array.
     *
     * @param string[] $argv
     */
    public function dispatch(array $argv): int
    {
        $args = array_slice($argv, 1);
        $command = $args[0] ?? 'help';
        $parsed = $this->parseArgs(array_slice($args, 1));

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            $this->printHelp();
            return 0;
        }

        if ($command === 'list') {
            $this->printAvailable();
            return 0;
        }

        return $this->run($command, $parsed);
    }

    /**
     * Get all registered commands for help output.
     * @return array<string, string> name => description
     */
    public function getCommands(): array
    {
        $cmds = [];
        foreach ($this->commands as $name => $entry) {
            $cmds[$name] = $entry['description'];
        }
        foreach (self::$compiled as $name => $fn) {
            if (!isset($cmds[$name])) {
                $cmds[$name] = '(compiled)';
            }
        }
        return $cmds;
    }

    // ── Private ──

    /**
     * Parse CLI arguments into key-value pairs.
     * Handles: --key=value, --flag, -f, positional args
     *
     * @param string[] $args
     * @return array<string, mixed>
     */
    private function parseArgs(array $args): array
    {
        $parsed = ['_positional' => []];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if (str_starts_with($arg, '--')) {
                $key = substr($arg, 2);
                $eqPos = strpos($key, '=');
                if ($eqPos !== false) {
                    $parsed[substr($key, 0, $eqPos)] = substr($key, $eqPos + 1);
                } else {
                    // Check if next arg is the value
                    $next = $args[$i + 1] ?? null;
                    if ($next !== null && !str_starts_with($next, '-')) {
                        $parsed[$key] = $next;
                        $i++;
                    } else {
                        $parsed[$key] = true;
                    }
                }
            } elseif (str_starts_with($arg, '-') && strlen($arg) === 2) {
                $key = substr($arg, 1);
                $next = $args[$i + 1] ?? null;
                if ($next !== null && !str_starts_with($next, '-')) {
                    $parsed[$key] = $next;
                    $i++;
                } else {
                    $parsed[$key] = true;
                }
            } else {
                $parsed['_positional'][] = $arg;
            }
        }

        return $parsed;
    }

    private function printHelp(): void
    {
        echo "\n  AETHER Console\n";
        echo "  Usage: php bin/aether <command> [options]\n\n";
        $this->printAvailable();
    }

    private function printAvailable(): void
    {
        $cmds = $this->getCommands();
        if (count($cmds) === 0) {
            echo "  No commands registered.\n\n";
            return;
        }

        echo "  Available commands:\n";
        $maxLen = max(array_map('strlen', array_keys($cmds)));
        foreach ($cmds as $name => $desc) {
            echo sprintf("    %-{$maxLen}s  %s\n", $name, $desc);
        }
        echo "\n";
    }

    private function error(string $message): void
    {
        echo "[ERROR] {$message}\n";
    }
}
