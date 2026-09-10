<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckRequest;
use NameSweep\CheckResult;
use NameSweep\Providers\Marketplace\MarketplaceProviderInterface;
use NameSweep\Providers\Marketplace\RobotDomainSearchProvider;
use NameSweep\Providers\SuffixResolver;
use NameSweep\Storage\CheckRepository;
use NameSweep\Util\Logger;

/**
 * MarketplaceModule — is this domain actually obtainable, and from whom?
 *
 * Which chain runs depends on what the DomainModule found, because the two
 * cases have nothing in common:
 *
 *   REGISTERED (or parked/for_sale) — the interesting case, and the one this
 *   module used to skip entirely. A registered domain may still be buyable:
 *   parked for ads, or listed with a broker. Chain: DNS delegation signals
 *   first (definitive and cheap), then the parked-page detector.
 *
 *   AVAILABLE — nothing owns it, so there is no delegation to read. Only an
 *   aftermarket API can tell us whether the registry has it reserved or priced
 *   as a premium. Chain: RobotDomainSearch fan-out (curl_multi, cap 5), then
 *   Openprovider, then the parked detector.
 *
 * Before M10 the module returned early unless the domain was `available`, so
 * it could never answer "this registered domain is for sale" — the question it
 * exists to answer.
 *
 * A TLD the domain module could not classify (`uncertain`) is skipped: there is
 * no honest aftermarket answer for a domain whose registration state is unknown.
 */
final class MarketplaceModule implements ModuleInterface, DomainResultsAware
{
    /** @var CheckResult[]|null injected by the Engine; null = not supplied */
    private ?array $domainResults = null;

    /**
     * @param MarketplaceProviderInterface[] $registeredChain DNS signals, then parked-page detection
     * @param MarketplaceProviderInterface[] $availableChain  aftermarket APIs for unregistered names
     */
    public function __construct(
        private readonly DomainModule $domain,
        private readonly RobotDomainSearchProvider $primary,
        private readonly array $registeredChain,
        private readonly array $availableChain,
        private readonly int $concurrency,
        private readonly int $ttl,
        private readonly CheckRepository $repo,
        private readonly Logger $logger,
        private readonly array $defaultTlds
    ) {
    }

    public function name(): string
    {
        return 'marketplace';
    }

    public function setDomainResults(?array $results): void
    {
        $this->domainResults = $results;
    }

    public function check(CheckRequest $req): array
    {
        $tlds    = $req->tlds !== [] ? $req->tlds : $this->defaultTlds;
        $results = [];

        $statuses = $this->domainStatuses($req);

        $liveRegistered = [];
        $liveAvailable  = [];

        foreach ($tlds as $tld) {
            $tld = SuffixResolver::normalize((string) $tld);
            if ($tld === '') {
                continue;
            }

            $status = $statuses[$tld] ?? null;
            if ($status === null || $status === 'uncertain') {
                // No trustworthy registration state → no honest aftermarket answer.
                continue;
            }

            if ($req->useCache) {
                $row = $this->cacheLookup($req->name, $tld);
                if ($row !== null) {
                    $results[] = $this->resultFromRow($row);
                    continue;
                }
            }

            if ($status === 'available') {
                $liveAvailable[] = $tld;
            } else {
                $liveRegistered[] = $tld;
            }
        }

        // Registered names: delegation first, then the (brittle) page fetch.
        foreach ($liveRegistered as $tld) {
            $results[] = $this->walk($this->registeredChain, $req->name, $tld)
                ?? $this->noSignal($req->name, $tld, 'registered', 'No parking or brokerage signal found — registered and in use, or privately held');
        }

        // Available names: aftermarket APIs, fanned out in parallel first.
        if ($liveAvailable !== []) {
            $primaryResults = $this->primary->checkMany($req->name, $liveAvailable, $this->concurrency);
            foreach ($liveAvailable as $tld) {
                $results[] = $primaryResults[$tld]
                    ?? $this->walk($this->availableChain, $req->name, $tld)
                    ?? $this->noSignal($req->name, $tld, 'available', 'No aftermarket listing or parked signature detected');
            }
        }

        return $results;
    }

    /**
     * Registration status per suffix, preferring what the Engine already ran.
     *
     * @return array<string, string> suffix => status
     */
    private function domainStatuses(CheckRequest $req): array
    {
        $source = $this->domainResults;

        // Second guard behind the Engine's per-request reset: only trust
        // injected results that are actually about the name we were asked for.
        if ($source !== null) {
            foreach ($source as $r) {
                if ($r->name !== $req->name) {
                    $this->logger->warning(
                        'Discarding injected domain results for a different name',
                        ['expected' => $req->name, 'got' => $r->name],
                        'marketplace'
                    );
                    $source = null;
                    break;
                }
            }
        }

        if ($source === null) {
            // Called without the domain module in the request — run it
            // ourselves. Cache-backed, so this is normally cheap.
            $source = $this->domain->check($req);
        }

        $statuses = [];
        foreach ($source as $r) {
            $tld = SuffixResolver::normalize($r->tld);
            if ($tld !== '') {
                $statuses[$tld] = $r->status;
            }
        }
        return $statuses;
    }

    /**
     * First provider in the chain with an opinion, or null.
     *
     * @param MarketplaceProviderInterface[] $chain
     */
    private function walk(array $chain, string $name, string $tld): ?CheckResult
    {
        foreach ($chain as $provider) {
            try {
                $result = $provider->check($name, $tld);
            } catch (\Throwable $e) {
                $this->logger->warning(
                    "Marketplace provider {$provider->key()} failed for {$name}.{$tld}",
                    ['error' => $e->getMessage()],
                    'marketplace'
                );
                continue;
            }
            if ($result !== null) {
                return $result;
            }
        }
        return null;
    }

    private function noSignal(string $name, string $tld, string $status, string $detail): CheckResult
    {
        $this->logger->info("No aftermarket signal for {$name}.{$tld}", ['status' => $status], 'marketplace');
        return new CheckResult($name, $tld, $status, 'marketplace', 'marketplace', 'marketplace', $detail, null, null, date('c'), false);
    }

    /**
     * Returns the first fresh cached row across every provider platform plus
     * the 'marketplace' umbrella slot — or null.
     */
    private function cacheLookup(string $name, string $tld): ?array
    {
        foreach ($this->providerKeys() as $platform) {
            $row = $this->repo->findFresh($name, $tld, 'marketplace', $platform, $this->ttl);
            if ($row !== null) {
                return $row;
            }
        }
        return null;
    }

    /** @return string[] priority order of cache platforms to consult */
    private function providerKeys(): array
    {
        $keys = [$this->primary->key()];
        foreach ([...$this->registeredChain, ...$this->availableChain] as $provider) {
            $keys[] = $provider->key();
        }
        $keys[] = 'marketplace'; // umbrella for the no-signal result
        return array_values(array_unique($keys));
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
