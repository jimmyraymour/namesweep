<?php

/**
 * Shared bootstrap for every entry point (CLI, REST API, Web UI).
 *
 * Loads the Composer autoloader and .env, merges the config/ files, and
 * creates the shared services (logger, database, engine). Returns an array
 * of services; each entry point consumes what it needs.
 *
 * Usage:
 *   $app = require __DIR__ . '/bootstrap.php';
 *
 * Note: this is a small addition over the spec's file list so the three
 * entry points don't duplicate wiring. It is documented in README.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use NameSweep\Engine;
use NameSweep\Modules\DomainModule;
use NameSweep\Providers\Rdap\RdapBootstrap;
use NameSweep\Providers\Rdap\RdapClient;
use NameSweep\Providers\Whois\WhoisClient;
use NameSweep\Storage\ApiKeyRepository;
use NameSweep\Storage\CheckRepository;
use NameSweep\Storage\Database;
use NameSweep\Util\Env;
use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;
use NameSweep\Util\RateLimiter;

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
    $db = new Database($config['db'], $config['app']['timezone'] ?? null);
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    $logger->error('Database connection failed', ['error' => $dbError]);
}

// 5. Engine wiring (only built when the DB is available).
$engine = null;
$apiKeys = null;
$checks = null;
if ($db !== null) {
    $checks  = new CheckRepository($db);
    $apiKeys = new ApiKeyRepository($db);

    $http = new HttpClient($config['http']['timeout'], $config['http']['user_agent']);
    $rdapBootstrap = new RdapBootstrap($http, $config['rdap']['bootstrap_cache'], $config['rdap']['bootstrap_url'], $logger);
    $rdapClient    = new RdapClient($http, $logger);
    $whoisClient   = new WhoisClient($logger, $root . '/bootstrap.txt');

    $modules = [
        'domain' => new DomainModule(
            $config['tlds'],
            $config['default_tlds'],
            $config['ttl']['domain'],
            $rdapBootstrap,
            $rdapClient,
            $whoisClient,
            $checks,
            $logger
        ),
    ];

    $engine = new Engine($modules, $checks, $logger, $config['ttl']);
}

return [
    'root'       => $root,
    'config'     => $config,
    'logger'     => $logger,
    'db'         => $db,
    'db_error'   => $dbError,
    'engine'     => $engine,
    'checks'     => $checks,
    'api_keys'   => $apiKeys,
    'rate_limiter' => new RateLimiter($config['rate_limit']['per_minute'], $root . '/storage/tmp'),
];
