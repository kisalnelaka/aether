<?php

declare(strict_types=1);

/**
 * Project AETHER — Framework Bootstrap
 *
 * This file is the single entry point for the AETHER framework.
 * It initializes the custom autoloader, sets up error handling,
 * and provides the global framework constants.
 *
 * @package Aether
 * @version 1.0.0
 * @license MIT
 */

define('AETHER_VERSION', '1.0.0');
define('AETHER_START', hrtime(true));
define('AETHER_ROOT', __DIR__);
define('AETHER_SRC', __DIR__ . DIRECTORY_SEPARATOR . 'src');
define('AETHER_CACHE', __DIR__ . DIRECTORY_SEPARATOR . 'cache');
define('AETHER_CONFIG', __DIR__ . DIRECTORY_SEPARATOR . 'config');

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, "AETHER requires PHP 8.3 or later. Current version: " . PHP_VERSION . "\n");
    exit(1);
}

require_once AETHER_SRC . DIRECTORY_SEPARATOR . 'Autoloader.php';

$autoloader = new \Aether\Autoloader('Aether', AETHER_SRC);
$autoloader->register();
