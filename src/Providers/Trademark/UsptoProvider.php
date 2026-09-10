<?php

declare(strict_types=1);

namespace NameSweep\Providers\Trademark;

use NameSweep\CheckResult;
use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * USPTO (United States) — the live TM Search index behind tmsearch.uspto.gov.
 *
 * TESS was retired in 2025; the replacement's backing store is an
 * Elasticsearch proxy. LIVE VERIFICATION (2026-08-17):
 *
 *   POST https://tmsearch.uspto.gov/prod-v1-0-0/tmsearch
 *   {"query":{"bool":{"should":[{"term":{"WM":{"value":"LUMEN"}}}],
 *    "minimum_should_match":1}},"size":3,
 *    "_source":["wordmark","ownerName","alive","serialNumber","registrationNumber"]}
 *
 * Response envelope quirks (verified live):
 *   - the count is hits.totalValue (NOT hits.total.value)
 *   - the term value must be lowercase — the index lowercases the field
 *     (verified live 2026-08-17: "LUMEN" → 0 hits, "lumen" → 408)
 *   - you request fields as `_source`, the response returns them as `source`
 *   - serialNumber/registrationNumber can be null; hits.hits[].id is the
 *     USPTO serial
 *   - expired registrations are included → hits only mean "a record exists";
 *     status derivation stays with the owner
 *   - no auth; a plain User-Agent is fine (verified with NameSweep/1.0);
 *     behind AWS WAF — may 403 without notice
 *
 * 0 hits → available. >0 hits → registered (count + first record's wordmark,
 * serial, owner snippet). Unparseable response → uncertain.
 */
final class UsptoProvider implements TrademarkProviderInterface
{
    public function __construct(
        private readonly string $url,
        private readonly HttpClient $http,
        private readonly Logger $logger
    ) {
    }

    public function key(): string
    {
        return 'uspto';
    }

    public function check(string $name): CheckResult
    {
        $payload = json_encode([
            'query' => [
                'bool' => [
                    'should'               => [['term' => ['WM' => ['value' => strtolower($name)]]]],
                    'minimum_should_match' => 1,
                ],
            ],
            'size'    => 3,
            '_source' => ['wordmark', 'ownerName', 'alive', 'serialNumber', 'registrationNumber'],
        ], JSON_UNESCAPED_SLASHES);

        try {
            $response = $this->http->postJson($this->url, (string) $payload, 30);
        } catch (\Throwable $e) {
            $this->logger->warning("USPTO unreachable for {$name}", ['error' => $e->getMessage()], 'trademark');
            return new CheckResult($name, '', 'uncertain', 'uspto', 'trademark', 'uspto', 'USPTO search unreachable — manual assist recommended', null, $this->url, date('c'), false);
        }

        if ($response['status'] !== 200) {
            $this->logger->warning("USPTO HTTP {$response['status']} for {$name}", [], 'trademark');
            return new CheckResult($name, '', 'uncertain', 'uspto', 'trademark', 'uspto', 'USPTO search HTTP ' . $response['status'] . ' — manual assist recommended', null, $this->url, date('c'), false);
        }

        try {
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->logger->warning("USPTO unparseable response for {$name}", [], 'trademark');
            return new CheckResult($name, '', 'uncertain', 'uspto', 'trademark', 'uspto', 'USPTO response unparseable — manual assist recommended', null, $this->url, date('c'), false);
        }

        $hits = $data['hits'] ?? null;
        if (!is_array($hits)) {
            return new CheckResult($name, '', 'uncertain', 'uspto', 'trademark', 'uspto', 'USPTO response unparseable — manual assist recommended', null, $this->url, date('c'), false);
        }

        $total = $hits['totalValue'] ?? 0;
        if (!is_int($total) && !is_float($total)) {
            $total = 0;
        }

        if ((int) $total === 0) {
            return new CheckResult($name, '', 'available', 'uspto', 'trademark', 'uspto', 'No live USPTO trademark records found', null, $this->url, date('c'), false);
        }

        $detail = $this->firstRecordDetail((int) $total, $hits['hits'] ?? []);
        return new CheckResult($name, '', 'registered', 'uspto', 'trademark', 'uspto', $detail, null, $this->url, date('c'), false);
    }

    private function firstRecordDetail(int $total, mixed $hits): string
    {
        $first = 'unknown record';
        if (is_array($hits) && isset($hits[0]) && is_array($hits[0])) {
            $src  = $hits[0]['source'] ?? [];
            $mark = is_string($src['wordmark'] ?? null) ? $src['wordmark'] : null;
            $own  = is_array($src['ownerName'] ?? null) ? implode('; ', array_map('strval', $src['ownerName'])) : null;
            $ser  = $src['serialNumber'] ?? $hits[0]['id'] ?? null;

            $bits = [];
            if (is_string($mark) && $mark !== '') {
                $bits[] = $mark;
            }
            if ($ser !== null && $ser !== '') {
                $bits[] = 'serial ' . $ser;
            }
            if (is_string($own) && $own !== '') {
                $bits[] = mb_substr($own, 0, 80);
            }
            if ($bits !== []) {
                $first = implode(' — ', $bits);
            }
        }

        return 'Found ' . $total . ' USPTO trademark record(s); first: ' . mb_substr($first, 0, 200);
    }
}