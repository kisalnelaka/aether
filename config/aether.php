<?php

declare(strict_types=1);

/**
 * AETHER Framework Configuration
 *
 * @return array<string, mixed>
 */
return [
    /**
     * Application name.
     */
    'name' => 'AETHER Application',

    /**
     * Debug mode — set to true for verbose error responses.
     */
    'debug' => (bool)(getenv('AETHER_DEBUG') ?: false),

    /**
     * Controller scan paths.
     * Namespace => Directory path.
     * Used by the AOT compiler to discover route attributes.
     */
    'controllers' => [
        // 'App\\Controllers' => __DIR__ . '/../app/Controllers',
    ],

    /**
     * Service scan paths.
     * Namespace => Directory path.
     * Used by the AOT compiler to generate hydrator factories.
     */
    'services' => [
        // 'App\\Services' => __DIR__ . '/../app/Services',
    ],

    /**
     * Worker configuration.
     */
    'workers' => 4,
    'worker_mode' => 'stdio',       // 'stdio' | 'roadrunner' | 'socket'
    'max_requests' => 10000,         // Requests before worker recycle

    /**
     * Shared memory cache settings.
     */
    'cache' => [
        'shm_key' => 0x4145,        // System V IPC key
        'shm_size' => 1_048_576,     // 1MB
    ],

    /**
     * Database configuration.
     */
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => '',
        'username' => 'root',
        'password' => '',
    ],

    /**
     * Global middleware stack (executed on every request).
     */
    'middleware' => [
        // \App\Middleware\CorsMiddleware::class,
        // \App\Middleware\AuthMiddleware::class,
    ],
];
