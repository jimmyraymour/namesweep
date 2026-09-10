<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\CheckRequest;
use NameSweep\Engine;
use NameSweep\Util\Logger;
use NameSweep\Util\ReportAnalyzer;
use NameSweep\Util\SummaryWriter;

/**
 * Handles POST /check and the per-module check endpoints.
 */
final class CheckController
{
    public function __construct(
        private readonly ?Engine $engine,
        private readonly ?SummaryWriter $summary,
        private readonly Logger $logger,
        private readonly array $config = []
    ) {
    }

    public function check(): void
    {
        $body = $this->jsonBody();

        // Bulk names (hybrid from Spec B) or a single name (Spec A).
        $names = [];
        if (isset($body['names']) && is_array($body['names'])) {
            $invalid = [];
            foreach ($body['names'] as $n) {
                if (is_string($n) && preg_match('/^[a-z0-9-]{1,63}$/i', $n)) {
                    $names[] = strtolower($n);
                } else {
                    $invalid[] = $n;
                }
            }
            $names = array_values(array_unique($names));
            if ($names === [] || $invalid !== []) {
                $this->fail(400, 'invalid_input', 'names must contain only valid names', ['field' => 'names']);
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
            $data['verdict'] = ReportAnalyzer::verdict(
                $data['results'],
                $this->budget($body),
                $this->currency($body)
            );
            $this->json(200, $this->withSummary($data, $body));
            return;
        }

        $results = [];
        foreach ($names as $n) {
            $results[$n] = $this->engine->checkAsArray($this->requestFromBody($body, $n));
            $results[$n]['verdict'] = ReportAnalyzer::verdict(
                $results[$n]['results'],
                $this->budget($body),
                $this->currency($body)
            );
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
        $req = new CheckRequest(
            $req->name,
            $req->tlds,
            [$module],
            $req->markets,
            $req->useCache,
            $req->platform,
            $req->alternatives,
            $req->budget,
            $req->currency
        );

        $data = $this->engine->checkAsArray($req);
        $data['verdict'] = ReportAnalyzer::verdict($data['results'], $this->budget($body), $this->currency($body));
        $this->json(200, $this->withSummary($data, $body));
    }

    private function withSummary(array $data, array $body): array
    {
        $include = !isset($body['include_summary']) || $body['include_summary'] !== false;
        if ($include && $this->summary !== null) {
            $data['summary'] = $this->summary->forReport($data);
        }
        return $data;
    }

    private function requestFromBody(array $body, string $name): CheckRequest
    {
        $modules = $this->strList($body['modules'] ?? null);

        // API knob: "trademark": false excludes the module (spec §6.4).
        if (($body['trademark'] ?? null) === false) {
            if ($modules === []) {
                $modules = array_values(array_diff(
                    $this->config['default_modules'] ?? ['domain', 'marketplace', 'social', 'trademark'],
                    ['trademark']
                ));
            } else {
                $modules = array_values(array_diff($modules, ['trademark']));
            }
        }

        return new CheckRequest(
            $name,
            $this->strList($body['tlds'] ?? null),
            $modules,
            $this->strList($body['markets'] ?? null),
            !isset($body['use_cache']) || $body['use_cache'] !== false,
            null, // platform: single-platform social calls set it in the controller
            // M12 — alternatives (adds suffix rows) and a budget that ranks
            // but never filters. Rejected non-numeric budgets instead of
            // coercing: a typo'd "50x" must not silently mean 50.
            ($body['alternatives'] ?? false) === true,
            isset($body['budget']) && is_numeric($body['budget']) ? (float) $body['budget'] : null,
            $this->currency($body)
        );
    }

    private function budget(array $body): ?float
    {
        return isset($body['budget']) && is_numeric($body['budget']) ? (float) $body['budget'] : null;
    }

    private function currency(array $body): string
    {
        return is_string($body['currency'] ?? null) && trim($body['currency']) !== ''
            ? strtoupper(trim($body['currency']))
            : 'USD';
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
