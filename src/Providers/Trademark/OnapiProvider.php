<?php

declare(strict_types=1);

namespace NameSweep\Providers\Trademark;

use NameSweep\CheckResult;
use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * ONAPI (Dominican Republic) — best-effort form-scrape stub.
 *
 * There is no clean public ONAPI API. The spec's search URL
 * (https://onapi.gob.do/busqueda-de-marcas) is attempted with the term in the
 * query string; the results table, when present, is counted:
 *   0 rows  → available
 *   >0 rows → registered (row count + first 200 chars of the first row)
 *
 * Anything else (non-200, no table, unparseable HTML) → `uncertain` with the
 * exact message "ONAPI page unparseable — manual assist required". LIVE
 * VERIFICATION (2026-08-17): the spec URL 404s and the live site only offers
 * a Joomla site search (com_finder) with no server-side results table, so in
 * practice this provider reports `uncertain` — the spec-designed behavior.
 * The endpoint is configurable ('trademark.onapi_url') so a real search page
 * can be pointed at later without a code change. Best-effort status is
 * documented in the README; the UI shows it as a plain `uncertain` row.
 */
final class OnapiProvider implements TrademarkProviderInterface
{
    public const UNPARSEABLE = 'ONAPI page unparseable — manual assist required';

    public function __construct(
        private readonly string $url,
        private readonly HttpClient $http,
        private readonly Logger $logger
    ) {
    }

    public function key(): string
    {
        return 'onapi';
    }

    public function check(string $name): CheckResult
    {
        $url = $this->url . (str_contains($this->url, '?') ? '&' : '?') . 'q=' . rawurlencode($name);

        try {
            $response = $this->http->get($url, ['Accept-Language: es-DO,es;q=0.9']);
        } catch (\Throwable $e) {
            $this->logger->warning("ONAPI unreachable for {$name}", ['error' => $e->getMessage()], 'trademark');
            return new CheckResult($name, '', 'uncertain', 'onapi', 'trademark', 'onapi', self::UNPARSEABLE, null, $url, date('c'), false);
        }

        if ($response['status'] !== 200) {
            $this->logger->warning("ONAPI HTTP {$response['status']} for {$name}", [], 'trademark');
            return new CheckResult($name, '', 'uncertain', 'onapi', 'trademark', 'onapi', self::UNPARSEABLE, null, $url, date('c'), false);
        }

        $parsed = $this->extractRows($response['body']);
        if (!$parsed['found']) {
            $this->logger->warning("ONAPI page unparseable for {$name}", [], 'trademark');
            return new CheckResult($name, '', 'uncertain', 'onapi', 'trademark', 'onapi', self::UNPARSEABLE, null, $url, date('c'), false);
        }

        $rows = $parsed['rows'];
        if ($rows === []) {
            return new CheckResult($name, '', 'available', 'onapi', 'trademark', 'onapi', 'No ONAPI trademark records found', null, $url, date('c'), false);
        }

        $detail = 'Found ' . count($rows) . ' ONAPI trademark record(s): ' . mb_substr($rows[0], 0, 200);
        return new CheckResult($name, '', 'registered', 'onapi', 'trademark', 'onapi', $detail, null, $url, date('c'), false);
    }

    /**
     * @return array{found:bool, rows:string[]} found = a results table exists;
     *         rows = its data rows (text of the first plausible table)
     */
    private function extractRows(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadHTML($html)) {
            libxml_clear_errors();
            return ['found' => false, 'rows' => []];
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//table') as $table) {
            $rows = [];
            foreach ($xpath->query('.//tr', $table) as $tr) {
                $text = trim(preg_replace('/\s+/', ' ', $tr->textContent) ?? '');
                if ($text !== '') {
                    $rows[] = $text;
                }
            }
            if (count($rows) >= 2) {
                return ['found' => true, 'rows' => array_slice($rows, 1)];
            }
        }
        return ['found' => false, 'rows' => []];
    }
}