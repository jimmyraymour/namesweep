<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\Util\HttpClient;

/**
 * Simple front-controller router for /api/v1/*.
 */
final class Router
{
    public function __construct(private readonly array $app)
    {
    }

    public function dispatch(string $method, string $uri): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $path = preg_replace('/\?.*$/', '', $uri) ?? '/';
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
            if ($authResult['status'] === 429) {
                header('Retry-After: ' . ($authResult['body']['error']['details']['retry_after'] ?? 1));
            }
            $this->json($authResult['status'], $authResult['body']);
            return;
        }

        $controller = new CheckController($this->app['engine'], $this->app['logger']);

        if ($route === '/check' && $method === 'POST') {
            $controller->check();
            return;
        }
        if ($route === '/check/domain' && $method === 'POST') {
            $controller->checkModule('domain');
            return;
        }
        if (preg_match('#^/check/(marketplace|social|trademark)$#', $route, $m) && $method === 'POST') {
            $controller->checkModule($m[1]);
            return;
        }

        $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'Not found', 'details' => []]]);
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
