<?php

declare(strict_types=1);

/**
 * Web UI front controller. Routes /, /suggest, /history, /settings to the
 * server-rendered pages; static assets and /api/* are handled by the server
 * (see .htaccess / public/router.php for the dev server).
 */

use NameSweep\Web\Layout;
use NameSweep\Web\Pages\HistoryPage;
use NameSweep\Web\Pages\HomePage;
use NameSweep\Web\Pages\SettingsPage;
use NameSweep\Web\Pages\SuggestPage;

$app = require __DIR__ . '/../bootstrap.php';

// Bifrost SSO (Phase 1): every UI page requires a signed-in identity. The
// browser carries the bifrost_session cookie (.raymour.local) and the shared
// client introspects it server-side. The REST API (public/api.php) keeps its
// Bearer-key auth; bin/ CLI is unaffected.
require dirname(__DIR__) . '/../bifrost/shared/php/BifrostClient.php';
Bifrost::requireLogin();

// The app may be mounted under a subdirectory (e.g. /namesweep/public on a
// shared host); strip that prefix from REQUEST_URI before routing.
$path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base  = Layout::base();
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$path = rtrim($path, '/') ?: '/';

header('Content-Type: text/html; charset=utf-8');

try {
    $page = match ($path) {
        '/'        => new HomePage($app),
        '/suggest' => new SuggestPage($app),
        '/history' => new HistoryPage($app),
        '/settings'=> new SettingsPage($app),
        default    => null,
    };

    if ($page === null) {
        http_response_code(404);
        echo Layout::page('Not found', '<p class="error">Page not found.</p>');
    } else {
        echo $page->render();
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo Layout::page('Error', '<p class="error">Something went wrong: ' . Layout::e($e->getMessage()) . '</p>');
}