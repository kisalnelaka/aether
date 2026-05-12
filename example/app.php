<?php

declare(strict_types=1);

/**
 * Example AETHER Application
 *
 * Shows you how to boot it. Don't overcomplicate this.
 *
 * Usage:
 *   php example/app.php
 */

require_once __DIR__ . '/../aether.php';

use Aether\Kernel\Application;
use Aether\Http\Request;
use Aether\Http\Response;

// ── Create Application ──

$app = Application::create(AETHER_ROOT);

// ── Register Routes (manual mode. Use attributes instead if you want to be smart) ──

$app->get('/', 'App\\Controllers\\HomeController@index', 'home');
$app->get('/health', 'App\\Controllers\\HomeController@health', 'health');
$app->get('/users/{id}', 'App\\Controllers\\HomeController@showUser', 'users.show');
$app->post('/users', 'App\\Controllers\\HomeController@createUser', 'users.create');
$app->get('/files/{path*}', 'App\\Controllers\\HomeController@serveFile', 'files.serve');

// ── Route Groups ──

$app->group('/api/v1', function ($router) {
    $router->get('/status', 'App\\Controllers\\HomeController@health', 'api.status');
});

// ── Register Services ──

$app->persistent('config', fn() => ['app' => 'AETHER Demo']);

// ── Run ──

$app->run();
