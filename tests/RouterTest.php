<?php

declare(strict_types=1);

/**
 * AETHER Framework — Router Test Suite
 *
 * Run: php tests/RouterTest.php
 */

require_once __DIR__ . '/../aether.php';

use Aether\Router\RadixTree;
use Aether\Router\Router;
use Aether\Router\RouteCompiler;

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✓ {$message}\n";
    } else {
        $failed++;
        echo "  ✗ FAIL: {$message}\n";
    }
}

echo "\n══════════════════════════════════════\n";
echo "  AETHER Router Test Suite\n";
echo "══════════════════════════════════════\n\n";

// ── Test 1: Static Routes ──
echo "▸ Static Routes\n";
$tree = new RadixTree();
$tree->insert('GET', '/users', 'UserController@index');
$tree->insert('GET', '/users/list', 'UserController@list');
$tree->insert('POST', '/users', 'UserController@store');

$m = $tree->match('GET', '/users');
assert_true($m !== null && $m->route->handler === 'UserController@index', 'GET /users');

$m = $tree->match('GET', '/users/list');
assert_true($m !== null && $m->route->handler === 'UserController@list', 'GET /users/list');

$m = $tree->match('POST', '/users');
assert_true($m !== null && $m->route->handler === 'UserController@store', 'POST /users');

$m = $tree->match('GET', '/nonexistent');
assert_true($m === null, 'GET /nonexistent returns null');

// ── Test 2: Dynamic Parameters ──
echo "\n▸ Dynamic Parameters\n";
$tree2 = new RadixTree();
$tree2->insert('GET', '/users/{id}', 'UserController@show');
$tree2->insert('GET', '/users/{id}/posts/{postId}', 'PostController@show');

$m = $tree2->match('GET', '/users/42');
assert_true($m !== null && $m->params['id'] === '42', 'GET /users/42 → id=42');

$m = $tree2->match('GET', '/users/99/posts/7');
assert_true(
    $m !== null && $m->params['id'] === '99' && $m->params['postId'] === '7',
    'GET /users/99/posts/7 → id=99, postId=7'
);

// ── Test 3: Wildcard Routes ──
echo "\n▸ Wildcard Routes\n";
$tree3 = new RadixTree();
$tree3->insert('GET', '/files/{path*}', 'FileController@serve');

$m = $tree3->match('GET', '/files/docs/readme.md');
assert_true(
    $m !== null && $m->params['path'] === 'docs/readme.md',
    'GET /files/docs/readme.md → path=docs/readme.md'
);

// ── Test 4: Named Routes & URL Generation ──
echo "\n▸ Named Routes\n";
$tree4 = new RadixTree();
$tree4->insert('GET', '/users/{id}', 'UserController@show', '', '', [], 'users.show');

$url = $tree4->generate('users.show', ['id' => '42']);
assert_true($url === '/users/42', 'Generate /users/42 from name');

// ── Test 5: Route Compilation (AOT) ──
echo "\n▸ Route Compilation\n";
$tree5 = new RadixTree();
$tree5->insert('GET', '/api/v1/items', 'ItemController@index', '', '', [], 'items.index');
$tree5->insert('GET', '/api/v1/items/{id}', 'ItemController@show', '', '', [], 'items.show');

$compiler = new RouteCompiler();
$tmpFile = __DIR__ . '/../cache/test_compiled_routes.php';
$compiler->compile($tree5, $tmpFile);

assert_true(is_file($tmpFile), 'Compiled routes file created');

$loaded = $compiler->load($tmpFile);
$m = $loaded->match('GET', '/api/v1/items');
assert_true($m !== null && $m->route->handler === 'ItemController@index', 'Loaded tree matches /api/v1/items');

$m = $loaded->match('GET', '/api/v1/items/55');
assert_true($m !== null && $m->params['id'] === '55', 'Loaded tree matches /api/v1/items/55');

@unlink($tmpFile);

// ── Test 6: Router Facade ──
echo "\n▸ Router Facade\n";
$router = new Router();
$router->get('/home', 'HomeController@index', 'home');
$router->post('/submit', 'FormController@submit');
$router->group('/admin', function ($r) {
    $r->get('/dashboard', 'AdminController@dashboard', 'admin.dashboard');
});

$m = $router->dispatch('GET', '/home');
assert_true($m !== null, 'Router facade GET /home');

$m = $router->dispatch('GET', '/admin/dashboard');
assert_true($m !== null, 'Router facade GET /admin/dashboard');

// ── Test 7: Method Not Allowed ──
echo "\n▸ Method Not Allowed\n";
$allowed = $router->getAllowedMethods('/home');
assert_true(in_array('GET', $allowed, true), '/home allows GET');
assert_true(!in_array('POST', $allowed, true), '/home does not allow POST');

// ── Results ──
echo "\n══════════════════════════════════════\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo "══════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
