<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\CheckRequest;
use NameSweep\Engine;
use NameSweep\Suggest\SuggestEngine;
use NameSweep\Suggest\SuggestRepository;
use NameSweep\Util\Logger;
use NameSweep\Util\SummaryWriter;

/**
 * Handles POST /suggest, GET /suggest/{sessionId} and POST /summary.
 */
final class SuggestController
{
    public function __construct(
        private readonly ?SuggestEngine $suggest,
        private readonly ?SuggestRepository $suggestRepo,
        private readonly ?Engine $engine,
        private readonly ?SummaryWriter $summary,
        private readonly Logger $logger
    ) {
    }

    public function create(): void
    {
        $body = $this->jsonBody();

        $description = $body['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            $this->fail(400, 'invalid_input', 'description must be a non-empty string', ['field' => 'description']);
        }
        if ($this->suggest === null) {
            $this->fail(503, 'internal_error', 'Suggest engine unavailable (database down)', ['db' => true]);
        }

        $count = isset($body['count']) ? (int) $body['count'] : 10;
        if ($count < 1 || $count > 50) {
            $this->fail(400, 'invalid_input', 'count must be between 1 and 50', ['field' => 'count']);
        }

        $tlds     = $this->strList($body['tlds'] ?? null);
        $modules  = $this->strList($body['modules'] ?? null);
        $includeRejects = !array_key_exists('include_rejects', $body) || $body['include_rejects'] !== false;

        try {
            $result = $this->suggest->suggest(trim($description), $count, $tlds, $modules, $includeRejects);
        } catch (\Throwable $e) {
            $this->logger->error('Suggest failed', ['error' => $e->getMessage()]);
            $this->fail(502, 'upstream_error', 'Suggest failed: ' . $e->getMessage(), []);
        }

        $this->json(200, $result);
    }

    public function show(string $sessionId): void
    {
        if ($this->suggestRepo === null) {
            $this->fail(503, 'internal_error', 'Suggest repository unavailable (database down)', ['db' => true]);
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $sessionId)) {
            $this->fail(400, 'invalid_input', 'sessionId must be a UUIDv4', ['field' => 'sessionId']);
        }

        $session = $this->suggestRepo->session($sessionId);
        if ($session === null) {
            $this->fail(404, 'not_found', 'Session not found', ['session_id' => $sessionId]);
        }

        $kept = [];
        $rejected = [];
        foreach ($this->suggestRepo->candidates($sessionId) as $row) {
            if ((int) $row['kept'] === 1) {
                $kept[] = ['name' => $row['name'], 'iteration' => (int) $row['iteration'], 'created_at' => $row['created_at']];
            } else {
                $rejected[] = ['name' => $row['name'], 'iteration' => (int) $row['iteration'], 'created_at' => $row['created_at']];
            }
        }

        $this->json(200, [
            'session_id'  => $sessionId,
            'description' => $session['description'],
            'model'       => $session['model'],
            'tlds'        => $session['tld_filter'] !== null ? json_decode($session['tld_filter'], true) : null,
            'modules'     => $session['modules_filter'] !== null ? json_decode($session['modules_filter'], true) : null,
            'created_at'  => $session['created_at'],
            'finalized_at'=> $session['finalized_at'],
            'kept'        => $kept,
            'rejected'    => $rejected,
            'candidates'  => array_merge(
                array_map(static fn (array $row): array => [
                    'name' => $row['name'],
                    'iteration' => (int) $row['iteration'],
                    'cleared' => true,
                    'rejection_reason' => null,
                ], $kept),
                array_map(static fn (array $row): array => [
                    'name' => $row['name'],
                    'iteration' => (int) $row['iteration'],
                    'cleared' => false,
                    'rejection_reason' => 'did not clear all requested modules',
                ], $rejected)
            ),
        ]);
    }

    public function summary(): void
    {
        $body = $this->jsonBody();
        $name = $body['name'] ?? null;
        if (!is_string($name) || !preg_match('/^[a-z0-9-]{1,63}$/i', $name)) {
            $this->fail(400, 'invalid_input', 'name must match [a-z0-9-]{1,63}', ['field' => 'name']);
        }
        if ($this->engine === null || $this->summary === null) {
            $this->fail(503, 'internal_error', 'Summary writer unavailable (database down)', ['db' => true]);
        }

        $req = new CheckRequest(
            $name,
            $this->strList($body['tlds'] ?? null),
            $this->strList($body['modules'] ?? null),
            $this->strList($body['markets'] ?? null),
            !isset($body['use_cache']) || $body['use_cache'] !== false
        );

        $data = $this->engine->checkAsArray($req);
        $summary = $this->summary->forReport($data, true);

        $this->json(200, [
            'name'    => $name,
            'summary' => $summary,
            'checked_at' => $data['checked_at'],
        ]);
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