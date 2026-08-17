<?php

/**
 * Router script for the PHP built-in dev server.
 *
 *   php -S 127.0.0.1:8000 -t public public/router.php
 *
 * The built-in server has no front-controller routing on its own, so this
 * file points /api/* at api.php, serves static assets as-is, and routes
 * everything else to the web UI (index.php, built in M7).
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// Serve an existing static file directly (css/js/images).
if ($path !== '/' && is_file($file)) {
    return false;
}

// REST API.
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}

// Web UI (built in M7).
if (is_file(__DIR__ . '/index.php')) {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => ['code' => 'not_found', 'message' => 'Web UI not built yet (M7)', 'details' => []]]);
return true;
