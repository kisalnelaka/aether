<?php

declare(strict_types=1);

/**
 * AETHER Framework — Container Test Suite
 *
 * Run: php tests/ContainerTest.php
 */

require_once __DIR__ . '/../aether.php';

use Aether\Container\Container;
use Aether\Container\ServiceScope;
use Aether\Container\ContainerException;

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
echo "  AETHER Container Test Suite\n";
echo "══════════════════════════════════════\n\n";

// ── Helper Classes ──
class TestServiceA { public string $value = 'A'; }
class TestServiceB
{
    public function __construct(public TestServiceA $a) {}
}

// ── Test 1: Basic Binding ──
echo "▸ Basic Binding\n";
$c = new Container();
$c->bind(TestServiceA::class, TestServiceA::class);

$a = $c->resolve(TestServiceA::class);
assert_true($a instanceof TestServiceA, 'Resolves TestServiceA');
assert_true($a->value === 'A', 'TestServiceA has correct value');

// ── Test 2: Instance Binding ──
echo "\n▸ Instance Binding\n";
$c2 = new Container();
$obj = new TestServiceA();
$obj->value = 'custom';
$c2->instance(TestServiceA::class, $obj);

$resolved = $c2->resolve(TestServiceA::class);
assert_true($resolved->value === 'custom', 'Instance binding returns exact object');
assert_true($resolved === $obj, 'Same reference');

// ── Test 3: Factory Binding ──
echo "\n▸ Factory Binding\n";
$c3 = new Container();
$c3->bind('counter', function () {
    static $count = 0;
    $count++;
    return $count;
}, ServiceScope::Transient);

$v1 = $c3->resolve('counter');
$v2 = $c3->resolve('counter');
assert_true($v1 === 1 && $v2 === 2, 'Transient creates new instance each time');

// ── Test 4: Persistent Scope ──
echo "\n▸ Persistent Scope\n";
$c4 = new Container();
$c4->persistent(TestServiceA::class, fn() => new TestServiceA());

$a1 = $c4->resolve(TestServiceA::class);
$a2 = $c4->resolve(TestServiceA::class);
assert_true($a1 === $a2, 'Persistent returns same instance');

// ── Test 5: Ephemeral Scope ──
echo "\n▸ Ephemeral Scope\n";
$c5 = new Container();
$c5->ephemeral(TestServiceA::class, fn() => new TestServiceA());

$e1 = $c5->resolve(TestServiceA::class);
$e2 = $c5->resolve(TestServiceA::class);
assert_true($e1 === $e2, 'Ephemeral returns same instance within request');

$c5->resetEphemerals();
$e3 = $c5->resolve(TestServiceA::class);
assert_true($e1 !== $e3, 'Ephemeral returns new instance after reset');

// ── Test 6: Dependency Resolution ──
echo "\n▸ Dependency Resolution\n";
$c6 = new Container();
$c6->bind(TestServiceA::class, TestServiceA::class);
$c6->bind(TestServiceB::class, TestServiceB::class, ServiceScope::Transient, [
    'a' => TestServiceA::class,
]);

$b = $c6->resolve(TestServiceB::class);
assert_true($b instanceof TestServiceB, 'Resolves TestServiceB');
assert_true($b->a instanceof TestServiceA, 'Injects TestServiceA into TestServiceB');

// ── Test 7: Circular Dependency Detection ──
echo "\n▸ Circular Dependency Detection\n";
$c7 = new Container();
$c7->bind('serviceX', function (Container $c) { return $c->resolve('serviceY'); });
$c7->bind('serviceY', function (Container $c) { return $c->resolve('serviceX'); });

$caught = false;
try {
    $c7->resolve('serviceX');
} catch (ContainerException $e) {
    $caught = strpos($e->getMessage(), 'Circular') !== false;
}
assert_true($caught, 'Detects circular dependency');

// ── Test 8: Alias ──
echo "\n▸ Alias Resolution\n";
$c8 = new Container();
$c8->bind(TestServiceA::class, TestServiceA::class);
$c8->alias('MyInterface', TestServiceA::class);

$resolved = $c8->resolve('MyInterface');
assert_true($resolved instanceof TestServiceA, 'Alias resolves to concrete');

// ── Test 9: Diagnostics ──
echo "\n▸ Diagnostics\n";
$c9 = new Container();
$c9->persistent('a', fn() => 1);
$c9->ephemeral('b', fn() => 2);
$c9->transient('c', fn() => 3);

$diag = $c9->diagnostics();
assert_true($diag['persistent'] === 1, 'Diagnostics: 1 persistent');
assert_true($diag['ephemeral'] === 1, 'Diagnostics: 1 ephemeral');
assert_true($diag['transient'] === 1, 'Diagnostics: 1 transient');

// ── Results ──
echo "\n══════════════════════════════════════\n";
echo "  Results: {$passed} passed, {$failed} failed\n";
echo "══════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
