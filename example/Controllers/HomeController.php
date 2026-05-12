<?php

declare(strict_types=1);

namespace App\Controllers;

use Aether\Attributes\Controller;
use Aether\Attributes\Get;
use Aether\Attributes\Post;
use Aether\Http\Request;
use Aether\Http\Response;

/**
 * An example controller. It does nothing useful.
 */
#[Controller(prefix: '/api')]
final class HomeController
{
    #[Get(path: '/', name: 'home')]
    public function index(Request $request): Response
    {
        return Response::json([
            'framework' => 'AETHER',
            'version' => AETHER_VERSION,
            'message' => 'Welcome. Stop using Laravel.',
            'boot_time_ns' => hrtime(true) - AETHER_START,
        ]);
    }

    #[Get(path: '/health', name: 'health')]
    public function health(Request $request): Response
    {
        return Response::json([
            'status' => 'operational',
            'memory_bytes' => memory_get_usage(false),
            'memory_peak_bytes' => memory_get_peak_usage(false),
            'php_version' => PHP_VERSION,
            'timestamp' => time(),
        ]);
    }

    #[Get(path: '/users/{id}', name: 'users.show')]
    public function showUser(Request $request): Response
    {
        $userId = $request->getRouteParam('id');

        return Response::json([
            'user' => [
                'id' => $userId,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
        ]);
    }

    #[Post(path: '/users', name: 'users.create')]
    public function createUser(Request $request): Response
    {
        $data = $request->json();

        return Response::json([
            'created' => true,
            'user' => $data,
        ], 201);
    }

    #[Get(path: '/files/{path*}', name: 'files.serve')]
    public function serveFile(Request $request): Response
    {
        $filePath = $request->getRouteParam('path');

        return Response::json([
            'file' => $filePath,
            'resolved' => true,
        ]);
    }
}
