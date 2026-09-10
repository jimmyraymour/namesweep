<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\Util\HttpClient;

/**
 * Simple front-controller router for /api/v1/*.
 */
final class Router
{
    private ?string $authKeyName = null;

    public function __construct(private readonly array $app)
    {
    }

    public function dispatch(string $method, string $uri): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Request log line: runs on every request, including the exit() paths
        // inside controllers (shutdown handlers fire regardless of exit()).
        // status comes from http_response_code() as set by the handlers.
        register_shutdown_function(function () use ($method, $uri): void {
            $logger = $this->app['logger'] ?? null;
            if ($logger === null) {
                return;
            }
            $key = $this->authKeyName ?? '-';
            $logger->info(sprintf(
                '%s %s -> %d (key=%s, %.0fms)',
                $method,
                $uri,
                http_response_code(),
                $key,
                (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000
            ), [], 'api');
        });

        $path = preg_replace('/\?.*$/', '', $uri) ?? '/';

        // The app may be mounted under a subdirectory (e.g. /namesweep/public
        // on a shared host); strip that prefix from the URI before routing.
        // A direct hit on api.php is normalized away as well.
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'));
        $base   = rtrim(dirname($script), '/');
        if ($base !== '' && $base !== '/' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        $path = preg_replace('#^/api\.php#', '', $path) ?? '/';
        $path = rtrim($path, '/');

        if (!preg_match('#^/api/v1(?:/|$)#', $path)) {
            $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'Not found', 'details' => []]]);
            return;
        }
        $route = substr($path, strlen('/api/v1')) ?: '/';

        // Public endpoint.
        if ($route === '/health' && $method === 'GET') {
            $this->json(200, [
                'status'     => 'ok',
                'db'         => $this->app['db'] !== null ? 'ok' : 'down',
                'ollama'     => $this->ollamaStatus(),
                'openrouter' => ($this->app['config']['llm']['openrouter_api_key'] ?? '') !== '' ? 'ok' : 'down',
            ]);
            return;
        }

        // Everything else requires a valid API key.
        $auth = new Auth($this->app['api_keys'], $this->app['rate_limiter'], $this->app['logger']);
        $authResult = $auth->authenticate();
        if (!$authResult['ok']) {
            $this->authKeyName = '-';
            if ($authResult['status'] === 429) {
                header('Retry-After: ' . ($authResult['body']['error']['details']['retry_after'] ?? 1));
            }
            $this->json($authResult['status'], $authResult['body']);
            return;
        }
        $this->authKeyName = (string) ($authResult['key']['name'] ?? 'key#' . ($authResult['key']['id'] ?? '?'));

        $controller = new CheckController($this->app['engine'], $this->app['summary_writer'], $this->app['logger'], $this->app['config']);
        $suggest    = new SuggestController(
            $this->app['suggest_engine'],
            $this->app['suggest_repo'],
            $this->app['engine'],
            $this->app['summary_writer'],
            $this->app['logger']
        );

        if ($route === '/check' && $method === 'POST') {
            $controller->check();
            return;
        }
        if ($route === '/check/domain' && $method === 'POST') {
            $controller->checkModule('domain');
            return;
        }
        if ($route === '/check/trademark/manual' && $method === 'POST') {
            $trademark = new TrademarkController(
                $this->app['engine'],
                $this->app['config'],
                $this->app['logger']
            );
            $trademark->manual($authResult['key'] ?? []);
            return;
        }
        if (preg_match('#^/check/(marketplace|social|trademark)$#', $route, $m) && $method === 'POST') {
            $controller->checkModule($m[1]);
            return;
        }
        if ($route === '/tlds' && $method === 'GET') {
            $this->tlds();
            return;
        }
        if ($route === '/pricing' && $method === 'GET') {
            $this->pricing();
            return;
        }
        if ($route === '/suggest' && $method === 'POST') {
            $suggest->create();
            return;
        }
        if (preg_match('#^/suggest/([0-9a-f-]{36})$#', $route, $m) && $method === 'GET') {
            $suggest->show($m[1]);
            return;
        }
        if ($route === '/summary' && $method === 'POST') {
            $suggest->summary();
            return;
        }

        $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'Not found', 'details' => []]]);
    }

    private function tlds(): void
    {
        $suffixes = array_keys($this->app['config']['tlds'] ?? []);
        $pricing = $this->app['tld_pricing'];
        $rdap = $this->app['rdap_bootstrap'] ?? null;
        if ($pricing !== null) {
            $suffixes = array_values(array_unique(array_merge($suffixes, $pricing->suffixes())));
        }
        if ($rdap !== null) {
            $suffixes = array_values(array_unique(array_merge($suffixes, $rdap->suffixes())));
        }
        sort($suffixes);
        $this->json(200, [
            'tlds' => array_map(static fn (string $tld): array => ['tld' => $tld], $suffixes),
            'count' => count($suffixes),
            'source' => 'config/tlds.php plus IANA-resolved and priced suffixes',
        ]);
    }

    private function pricing(): void
    {
        $pricing = $this->app['tld_pricing'];
        if ($pricing === null) {
            $this->json(503, ['error' => ['code' => 'internal_error', 'message' => 'Pricing unavailable (database down)', 'details' => []]]);
            return;
        }
        $suffixes = $pricing->suffixes();
        $rows = [];
        foreach ($suffixes as $suffix) {
            $rows[$suffix] = $pricing->forSuffix($suffix);
        }
        $this->json(200, [
            'pricing' => $rows,
            'count' => count($rows),
            'source' => 'Porkbun public pricing plus config/pricing_overrides.php',
        ]);
    }

    private function ollamaStatus(): string
    {
        $base = rtrim($this->app['config']['llm']['ollama_base_url'] ?? 'http://127.0.0.1:11434', '/');
        try {
            $res = (new HttpClient(3, 'NameSweep/1.0'))->get($base . '/api/tags');
            return $res['status'] === 200 ? 'ok' : 'down';
        } catch (\Throwable) {
            return 'down';
        }
    }

    private function json(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
