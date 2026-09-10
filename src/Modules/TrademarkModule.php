<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckRequest;
use NameSweep\CheckResult;
use NameSweep\Providers\Trademark\TrademarkProviderInterface;
use NameSweep\Storage\CheckRepository;
use NameSweep\Util\Logger;

/**
 * TrademarkModule — registry checks per market in config/markets.php.
 *
 * For each market (dr|ht|us) the module:
 *   1. Reads the fresh cache (30d TTL) keyed on the provider (onapi|hti|uspto)
 *      when useCache — this is also how POST /check/trademark/manual rows
 *      (source='manual', same platform key) surface on later checks.
 *   2. Runs the market's provider.
 * A provider exception or unknown market/provider becomes `uncertain` (never
 * blocks the report). The whole module short-circuits to [] when `enabled` is
 * false (config 'trademark.enabled'); the CLI --no-trademark flag and the API
 * "trademark": false request field exclude the module instead.
 */
final class TrademarkModule implements ModuleInterface
{
    public function __construct(
        /** @var array<string, array{provider:string, jurisdiction:string}> $markets */
        private readonly array $markets,
        /** @var array<string, TrademarkProviderInterface> $providers keyed by provider key */
        private readonly array $providers,
        private readonly bool $enabled,
        /** @var string[] $defaultMarkets markets to check when the request omits them */
        private readonly array $defaultMarkets,
        private readonly int $ttl,
        private readonly CheckRepository $repo,
        private readonly Logger $logger
    ) {
    }

    public function name(): string
    {
        return 'trademark';
    }

    public function check(CheckRequest $req): array
    {
        if (!$this->enabled) {
            return [];
        }

        $markets = $req->markets !== [] ? $req->markets : $this->defaultMarkets;
        $results = [];

        foreach ($markets as $market) {
            $config = $this->markets[$market] ?? null;
            if ($config === null) {
                $results[] = new CheckResult($req->name, '', 'uncertain', 'config', 'trademark', $market, "Unknown trademark market '{$market}'", null, null, date('c'), false);
                continue;
            }

            $provider = $this->providers[$config['provider']] ?? null;
            if ($provider === null) {
                $results[] = new CheckResult($req->name, '', 'uncertain', 'config', 'trademark', $config['provider'], "No provider for trademark market '{$market}'", null, null, date('c'), false);
                continue;
            }

            if ($req->useCache) {
                $row = $this->repo->findFresh($req->name, '', 'trademark', $provider->key(), $this->ttl);
                if ($row !== null) {
                    $results[] = $this->resultFromRow($row);
                    continue;
                }
            }

            try {
                $results[] = $provider->check($req->name);
            } catch (\Throwable $e) {
                $this->logger->warning("Trademark provider {$provider->key()} failed for {$req->name}", ['error' => $e->getMessage()], 'trademark');
                $results[] = new CheckResult($req->name, '', 'uncertain', $provider->key(), 'trademark', $provider->key(), $e->getMessage(), null, null, date('c'), false);
            }
        }

        return $results;
    }

    private function resultFromRow(array $row): CheckResult
    {
        $detail = $row['detail'] ?? null;
        if ($detail !== null) {
            $decoded = json_decode((string) $detail, true);
            $detail  = is_string($decoded) ? $decoded : (is_array($decoded) ? json_encode($decoded) : null);
        }
        $platform = $row['platform'] !== '' ? (string) $row['platform'] : (string) $row['source'];
        return new CheckResult(
            (string) $row['name'],
            (string) $row['tld'],
            (string) $row['status'],
            (string) $row['source'],
            (string) $row['module'],
            $platform,
            $detail,
            $row['price'] !== null ? (float) $row['price'] : null,
            $row['url'],
            date('c', strtotime((string) $row['checked_at'])),
            true
        );
    }
}