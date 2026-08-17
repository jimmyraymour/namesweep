<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\CheckRequest;
use NameSweep\Engine;
use NameSweep\Util\Logger;

/**
 * Handles POST /check and the per-module check endpoints.
 */
final class CheckController
{
    public function __construct(
        private readonly ?Engine $engine,
        private readonly Logger $logger
    ) {
    }

    public function check(): void
    {
        $body = $this->jsonBody();

        // Bulk names (hybrid from Spec B) or a single name (Spec A).
        $names = [];
        if (isset($body['names']) && is_array($body['names'])) {
            foreach ($body['names'] as $n) {
                if (is_string($n) && preg_match('/^[a-z0-9-]{1,63}$/i', $n)) {
                    $names[] = $n;
                }
            }
            if ($names === []) {
                $this->fail(400, 'invalid_input', 'names must contain valid names', ['field' => 'names']);
            }
        } else {
            $name = $body['name'] ?? null;
            if (!is_string($name) || !preg_match('/^[a-z0-9-]{1,63}$/i', $name)) {
                $this->fail(400, 'invalid_input', 'name must match [a-z0-9-]{1,63}', ['field' => 'name']);
            }
            $names = [$name];
        }

        if ($this->engine === null) {
            $this->fail(503, 'internal_error', 'Engine unavailable (database down)', ['db' => true]);
        }

        if (count($names) === 1 && !isset($body['names'])) {
            $data = $this->engine->checkAsArray($this->requestFromBody($body, $names[0]));
            $this->json(200, $data);
            return;
        }

        $results = [];
        foreach ($names as $n) {
            $results[$n] = $this->engine->checkAsArray($this->requestFromBody($body, $n));
        }
        $this->json(200, [
            'names'      => $names,
            'checked_at' => date('c'),
            'results'    => $results,
        ]);
    }

    public function checkModule(string $module): void
    {
        $body = $this->jsonBody();
        $name = $body['name'] ?? null;
        if (!is_string($name) || !preg_match('/^[a-z0-9-]{1,63}$/i', $name)) {
            $this->fail(400, 'invalid_input', 'name must match [a-z0-9-]{1,63}', ['field' => 'name']);
        }
        if ($this->engine === null) {
            $this->fail(503, 'internal_error', 'Engine unavailable (database down)', ['db' => true]);
        }

        $req = $this->requestFromBody($body, $name);
        $req = new CheckRequest($req->name, $req->tlds, [$module], $req->markets, $req->useCache);

        $this->json(200, $this->engine->checkAsArray($req));
    }

    private function requestFromBody(array $body, string $name): CheckRequest
    {
        return new CheckRequest(
            $name,
            $this->strList($body['tlds'] ?? null),
            $this->strList($body['modules'] ?? null),
            $this->strList($body['markets'] ?? null),
            !isset($body['use_cache']) || $body['use_cache'] !== false
        );
    }

    private function strList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $value), static fn ($v) => $v !== ''));
    }

    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->fail(400, 'invalid_input', 'Request body must be valid JSON', ['field' => 'body']);
        }
        return is_array($data) ? $data : [];
    }

    private function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function fail(int $status, string $code, string $message, array $details = []): void
    {
        $this->json($status, ['error' => ['code' => $code, 'message' => $message, 'details' => $details]]);
        exit;
    }
}
