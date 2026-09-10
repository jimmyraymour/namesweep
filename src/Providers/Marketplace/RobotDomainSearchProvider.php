<?php

declare(strict_types=1);

namespace NameSweep\Providers\Marketplace;

use NameSweep\CheckResult;
use NameSweep\Util\Logger;

/**
 * RobotDomainSearch aftermarket provider (keyless, free beta).
 *
 * Wraps the documented API endpoints:
 *   https://api.robotdomainsearch.com/availability?domain={name}.{tld}   (Spec A)
 *   https://api.robotdomainsearch.com/v1/aftermarket?domain={name}.{tld} (Spec B)
 *
 * LIVE VERIFICATION (2026-08-17): api.robotdomainsearch.com resolved to
 * 165.227.69.224 but the API endpoint did not accept connections from this
 * environment (connection timed out on both http and https). The primary
 * endpoint is therefore configurable via config ('aftermarket.robotdomainsearch_*')
 * and defaults to the Spec B shape. When the provider cannot connect it throws,
 * and the MarketplaceModule correctly fails over to the next tier — this is the
 * literal behavior the 3-tier priority requires. Re-visit the live endpoint +
 * JSON shape on the VPS and update config + this doc block accordingly.
 *
 * Parsing is deliberately defensive across both documented response shapes
 * (fields: available / for_sale / sellable / buy_now_price / min_price /
 * price / landing_page).
 */
final class RobotDomainSearchProvider implements MarketplaceProviderInterface
{
    private const MAX_RETRIES = 2;

    public function __construct(
        private readonly string $availabilityUrl,
        private readonly string $aftermarketUrl,
        private readonly \NameSweep\Util\HttpClient $http,
        private readonly Logger $logger
    ) {
    }

    public function key(): string
    {
        return 'robotdomainsearch';
    }

    public function check(string $name, string $tld): ?CheckResult
    {
        $domain = $name . '.' . $tld;

        // Try the aftermarket endpoint first (richer shape), then the
        // availability endpoint as a fallback for the same domain.
        $attempts = [
            ['url' => str_replace('{domain}', rawurlencode($domain), $this->aftermarketUrl)],
            ['url' => str_replace('{domain}', rawurlencode($domain), $this->availabilityUrl)],
        ];

        $lastError = null;
        foreach ($attempts as $attempt) {
            try {
                $response = $this->http->get($attempt['url'], ['Accept: application/json']);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }

            if ($response['status'] === 429) {
                for ($r = 1; $r <= self::MAX_RETRIES; $r++) {
                    sleep(2 ** ($r - 1));
                    try {
                        $response = $this->http->get($attempt['url'], ['Accept: application/json']);
                    } catch (\Throwable $e) {
                        $lastError = $e;
                        break;
                    }
                    if ($response['status'] !== 429) {
                        break;
                    }
                }
                if ($response['status'] === 429) {
                    $this->logger->warning("RobotDomainSearch rate limited for {$domain}", [], 'marketplace');
                    throw new \RuntimeException("RobotDomainSearch rate limited for {$domain}");
                }
            }

            if ($response['status'] === 200) {
                return $this->parse($name, $tld, $response['body']);
            }

            $lastError = new \RuntimeException("RobotDomainSearch HTTP {$response['status']} for {$domain}");
        }

        throw $lastError ?? new \RuntimeException("RobotDomainSearch unreachable for {$domain}");
}
/**
     * Fan-out helper used by the module's primary pass: checks many TLDs in
     * parallel via curl_multi (capped at $concurrency) and returns one result
     * per TLD key with the same semantics as check() (definitive result or null
     * on a miss / transport error).
     *
     * @param string[] $tlds
     * @return array<string, ?CheckResult>
     */
    public function checkMany(string $name, array $tlds, int $concurrency = 5): array
    {
        if ($tlds === []) {
            return [];
        }
        $tlds = array_values(array_unique($tlds));

        $jobs = [];
        foreach ($tlds as $tld) {
            $domain = $name . '.' . $tld;
            $jobs[$tld] = [
                'url'          => str_replace('{domain}', rawurlencode($domain), $this->aftermarketUrl),
                'headers'      => ['Accept: application/json'],
                'maxRedirects' => 3,
            ];
        }

        $responses = $this->http->getMulti($jobs, $concurrency);
        $byTld = [];

        foreach ($tlds as $tld) {
            $resp = $responses[$tld] ?? ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'no result'];

            if (!$resp['ok']) {
                $this->logger->warning("RobotDomainSearch transport error for {$name}.{$tld}", ['error' => $resp['error']], 'marketplace');
                $byTld[$tld] = null;
                continue;
            }
            if ($resp['status'] === 200) {
                try {
                    $byTld[$tld] = $this->parse($name, $tld, $resp['body']);
                } catch (\Throwable $e) {
                    $this->logger->warning("RobotDomainSearch parse error for {$name}.{$tld}", ['error' => $e->getMessage()], 'marketplace');
                    $byTld[$tld] = null;
                }
                continue;
            }
            if ($resp['status'] === 429) {
                $this->logger->warning("RobotDomainSearch rate limited for {$name}.{$tld}", [], 'marketplace');
            }
            $byTld[$tld] = null;
        }

        return $byTld;
    }

/**
     * Definitively interpret a 200 payload for the domain.
     *
     * @return CheckResult|null a definitive result, or null when the payload
     *         says nothing about the domain (treated as a miss).
     */
    private function parse(string $name, string $tld, string $body): ?CheckResult
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("RobotDomainSearch returned invalid JSON for {$name}.{$tld}");
        }
        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;

        $available = $this->truthy($payload['available'] ?? null);
        $forSale   = $this->truthy($payload['for_sale'] ?? null) || $this->truthy($payload['sellable'] ?? null);

        if ($available) {
            return new CheckResult($name, $tld, 'available', 'robotdomainsearch', 'marketplace', 'robotdomainsearch', null, null, null, date('c'), false);
        }

        if ($forSale) {
            $price = $this->extractPrice($payload);
            $url   = $this->stringOrNull($payload['landing_page'] ?? ($payload['url'] ?? null));
            return new CheckResult($name, $tld, 'for_sale', 'robotdomainsearch', 'marketplace', 'robotdomainsearch', null, $price, $url, date('c'), false);
        }

        if ($available === false && $forSale === false && $payload !== []) {
            return new CheckResult($name, $tld, 'registered', 'robotdomainsearch', 'marketplace', 'robotdomainsearch', null, null, null, date('c'), false);
        }

        return null;
    }
private function extractPrice(array $payload): ?float
    {
        $candidates = [$payload['buy_now_price'] ?? null, $payload['min_price'] ?? null, $payload['price'] ?? null];
        $prices = array_filter(array_map(fn ($p) => $this->floatOrNull($p), $candidates));
        return $prices === [] ? null : (float) min($prices);
    }

    private function truthy(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            $s = strtolower(trim($v));
            if (in_array($s, ['true', '1', 'yes'], true)) {
                return true;
            }
            if (in_array($s, ['false', '0', 'no'], true)) {
                return false;
            }
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        return null;
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function stringOrNull(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v : null;
    }
}
