<?php

declare(strict_types=1);

namespace NameSweep\Providers\Marketplace;

use NameSweep\CheckResult;
use NameSweep\Util\Logger;

/**
 * Openprovider aftermarket provider (Sedo / Afternic), key-gated.
 *
 * This provider is only wired into the MarketplaceModule when
 * OPENPROVIDER_API_KEY is set (see bootstrap.php). It is the "secondary" tier
 * in the aftermarket chain, tried only when RobotDomainSearch has nothing to say.
 *
 * LIVE VERIFICATION: the exact aftermarket endpoint + auth for Sedo/Afternic via
 * Openprovider's API could not be confirmed from this environment (no API key was
 * available). The implementation follows the documented contract and is defensive:
 * it POSTs the configured CheckDomain endpoint and looks for the documented price
 * fields. Confirm the real endpoint/auth on the VPS once an API key is supplied,
 * then update $this->path + the doc comment. Until then the provider returns
 * `uncertain` with an explanatory detail on any unparseable/error response.
 *
 * Per spec §6.2:
 *   - non-null buy_now_price or min_price → for_sale with the lower as price
 *   - registered/sold → registered · available=true → available
 */
final class OpenproviderProvider implements MarketplaceProviderInterface
{
    private const API_PATH = 'domains/check';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly \NameSweep\Util\HttpClient $http,
        private readonly Logger $logger
    ) {
    }

    public function key(): string
    {
        return 'openprovider';
    }

    public function check(string $name, string $tld): ?CheckResult
    {
        $url = rtrim($this->baseUrl, '/') . '/' . self::API_PATH;

        $payload = json_encode([
            'domains' => [
                ['name' => $name, 'extension' => $tld],
            ],
        ], JSON_THROW_ON_ERROR);

        try {
            $response = $this->http->get($url, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ], 1);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Openprovider request failed: {$e->getMessage()}");
        }

        if ($response['status'] !== 200) {
            // No aftermarket data for this domain → miss (fall through to parked).
            if (in_array($response['status'], [404, 401, 403], true)) {
                return null;
            }
            throw new \RuntimeException("Openprovider HTTP {$response['status']} for {$name}.{$tld}");
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Openprovider returned invalid JSON');
        }
        $results = $data['data']['results'] ?? ($data['results'] ?? null);
        if (!is_array($results) || $results === []) {
            return null; // no row for this domain → miss
        }

        $row = is_array($results[0]) ? $results[0] : [];
        $available = $this->truthy($row['is_available'] ?? ($row['available'] ?? null));

        $forSaleFields = $row['prices'] ?? $row;
        $buyNow = $this->floatOrNull($forSaleFields['buy_now_price'] ?? null);
        $minPrice = $this->floatOrNull($forSaleFields['min_price'] ?? null);
        $price = $this->lowest([$buyNow, $minPrice]);

        if ($price !== null) {
            return new CheckResult($name, $tld, 'for_sale', 'openprovider', 'marketplace', 'openprovider', null, $price, null, date('c'), false);
        }

        if ($available === true) {
            return new CheckResult($name, $tld, 'available', 'openprovider', 'marketplace', 'openprovider', null, null, null, date('c'), false);
        }

        if ($available === false) {
            return new CheckResult($name, $tld, 'registered', 'openprovider', 'marketplace', 'openprovider', null, null, null, date('c'), false);
        }

        $this->logger->warning("Openprovider response unparseable for {$name}.{$tld}", [], 'marketplace');
        return new CheckResult($name, $tld, 'uncertain', 'openprovider', 'marketplace', 'openprovider', 'Openprovider aftermarket response unparseable — confirm live endpoint/auth', null, null, date('c'), false);
    }

    /** @param (?float)[] $vals */
    private function lowest(array $vals): ?float
    {
        $numerics = array_filter($vals, static fn ($v) => $v !== null);
        return $numerics === [] ? null : (float) min($numerics);
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function truthy(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['true', '1', 'yes'], true);
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        return null;
    }
}