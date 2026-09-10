<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\CheckResult;
use NameSweep\Engine;
use NameSweep\Util\Logger;

/**
 * POST /api/v1/check/trademark/manual — the owner flips a trademark row
 * (Haiti, or a failed ONAPI scrape) to a definitive status by hand.
 *
 * Body: {"name": "lumen", "jurisdiction": "ht", "status": "registered", "note": "..."}
 * Writes a source='manual' row with the provider key of the market, so
 * subsequent checks read it back from the fresh cache. Requires the API key.
 */
final class TrademarkController
{
    public function __construct(
        private readonly ?Engine $engine,
        private readonly array $config,
        private readonly Logger $logger
    ) {
    }

    public function manual(array $key): void
    {
        $body = $this->jsonBody();

        $name = $body['name'] ?? null;
        if (!is_string($name) || !preg_match('/^[a-z0-9-]{1,63}$/i', $name)) {
            $this->fail(400, 'invalid_input', 'name must match [a-z0-9-]{1,63}', ['field' => 'name']);
        }

        $market = strtolower((string) ($body['jurisdiction'] ?? ''));
        $markets = $this->config['markets'] ?? [];
        if (!isset($markets[$market])) {
            $this->fail(400, 'invalid_input', 'jurisdiction must be one of: ' . implode(', ', array_keys($markets)), ['field' => 'jurisdiction']);
        }

        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, ['available', 'registered'], true)) {
            $this->fail(400, 'invalid_input', 'status must be available|registered', ['field' => 'status']);
        }

        $note = trim((string) ($body['note'] ?? ''));
        if ($note === '') {
            $this->fail(400, 'invalid_input', 'note is required (what did the owner check?)', ['field' => 'note']);
        }

        if ($this->engine === null) {
            $this->fail(503, 'internal_error', 'Engine unavailable (database down)', ['db' => true]);
        }

        $provider = (string) $markets[$market]['provider'];
        $user     = (string) ($key['name'] ?? 'unknown');
        $detail   = 'manually verified by ' . $user . ' at ' . date('c') . ' — ' . mb_substr($note, 0, 500);

        $this->engine->saveManual(new CheckResult(
            strtolower($name),
            '',
            $status,
            'manual',
            'trademark',
            $provider,
            $detail,
            null,
            null,
            date('c'),
            false
        ));

        $this->logger->info("Trademark manual verify: {$name} ({$market}) → {$status}", ['by' => $user], 'trademark');
        $this->json(201, [
            'name'        => strtolower($name),
            'jurisdiction' => $market,
            'status'      => $status,
            'source'      => 'manual',
            'platform'    => $provider,
            'detail'      => $detail,
            'checked_at'  => date('c'),
        ]);
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