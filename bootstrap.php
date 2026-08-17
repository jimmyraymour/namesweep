<?php

/**
 * Shared bootstrap for every entry point (CLI, REST API, Web UI).
 *
 * Loads the Composer autoloader and .env, merges the config/ files, and
 * creates the shared services (logger, database). Returns an array of
 * services; each entry point consumes what it needs.
 *
 * Usage:
 *   $app = require __DIR__ . '/bootstrap.php';
 *
 * Note: this is a small addition over the spec's file list so the three
 * entry points don't duplicate wiring. It is documented in README.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use NameSweep\Storage\Database;
use NameSweep\Util\Env;
use NameSweep\Util\Logger;

$root = __DIR__;

// 1. Environment — must load before any config file reads getenv().
Env::load($root . '/.env');

// 2. Config — app.php is the main array; the rest are merged in.
$config = require $root . '/config/app.php';
$config['tlds']    = require $root . '/config/tlds.php';
$config['markets'] = require $root . '/config/markets.php';
$config['social']  = require $root . '/config/social.php';

// 3. Locale.
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// 4. Shared services.
$logger = new Logger($root . '/storage/logs', $config['app']['log_level'] ?? 'INFO');

$db = null;
$dbError = null;
try {
    $db = new Database($config['db']);
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    $logger->error('Database connection failed', ['error' => $dbError]);
}

return [
    'root'     => $root,
    'config'   => $config,
    'logger'   => $logger,
    'db'       => $db,
    'db_error' => $dbError,
];
