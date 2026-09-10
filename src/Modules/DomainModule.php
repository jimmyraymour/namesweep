<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckRequest;
use NameSweep\CheckResult;
use NameSweep\Providers\Pricing\TldPricing;
use NameSweep\Providers\Rdap\RdapClient;
use NameSweep\Providers\SuffixResolver;
use NameSweep\Providers\Whois\WhoisClient;
use NameSweep\Storage\CheckRepository;
use NameSweep\Util\Logger;

/**
 * Domain registration status per suffix.
 *
 * Suffixes are not restricted to a configured list: `SuffixResolver` works out
 * how to reach any delegated suffix (config override -> IANA RDAP bootstrap ->
 * IANA WHOIS directory), including multi-label ones such as `com.do`. A suffix
 * that resolves to nothing is genuinely undelegated, and says so.
 *
 * If the resolved provider fails, the result is `uncertain` with the reason in
 * detail — never a guess.
 */
final class DomainModule implements ModuleInterface
{
    public function __construct(
        private readonly SuffixResolver $suffixes,
        private readonly array $defaultTlds,
        private readonly int $ttl,
        private readonly RdapClient $rdapClient,
        private readonly WhoisClient $whoisClient,
        private readonly CheckRepository $repo,
        private readonly Logger $logger,
        private readonly ?TldPricing $pricing = null
    ) {
    }

    /**
     * Registration pricing for the suffix, attached to every domain row
     * whatever its status — a taken .com is still worth knowing the price of.
     * Read live from the price table rather than the cached row, so a report
     * never quotes a stale figure.
     */
    private function priced(CheckResult $result): CheckResult
    {
        if ($this->pricing === null || $result->tld === '') {
            return $result;
        }
        try {
            return $result->withPricing($this->pricing->forSuffix($result->tld));
        } catch (\Throwable $e) {
            $this->logger->warning('Pricing lookup failed', ['tld' => $result->tld, 'error' => $e->getMessage()], 'domain');
            return $result;
        }
    }

    public function name(): string
    {
        return 'domain';
    }

    public function check(CheckRequest $req): array
    {
        $tlds = $req->tlds !== [] ? $req->tlds : $this->defaultTlds;
        $results = [];

        $seen = [];
        foreach ($tlds as $tld) {
            $tld = SuffixResolver::normalize((string) $tld);
            if ($tld === '' || isset($seen[$tld])) {
                continue;
            }
            $seen[$tld] = true;

            if (!SuffixResolver::isValid($tld)) {
                $results[] = new CheckResult($req->name, mb_substr($tld, 0, 64), 'uncertain', 'config', 'domain', '', "Malformed suffix '{$tld}'", null, null, date('c'), false);
                continue;
            }

            if ($req->useCache) {
                $row = $this->repo->findFresh($req->name, $tld, 'domain', '', $this->ttl);
                if ($row !== null) {
                    $cached = $this->resultFromRow($row);
                    $this->logger->info(
                        "checked {$req->name}.{$tld} -> {$cached->status}",
                        ['tld' => $tld, 'status' => $cached->status, 'source' => $cached->source, 'fromCache' => true],
                        'domain'
                    );
                    $results[] = $this->priced($cached);
                    continue;
                }
            }

            try {
                $result = $this->checkTld($req->name, $tld);
                // Spec section 14: INFO on every check, with fromCache. Also the
                // only way to see how many live lookups a request really makes.
                $this->logger->info(
                    "checked {$req->name}.{$tld} -> {$result->status}",
                    ['tld' => $tld, 'status' => $result->status, 'source' => $result->source, 'fromCache' => false],
                    'domain'
                );
                $results[] = $this->priced($result);
            } catch (\Throwable $e) {
                $this->logger->warning("Domain check failed for {$req->name}.{$tld}", ['error' => $e->getMessage()], 'domain');
                $results[] = new CheckResult($req->name, $tld, 'uncertain', 'domain', 'domain', '', $e->getMessage(), null, null, date('c'), false);
            }
        }

        return $results;
    }

    private function checkTld(string $name, string $tld): CheckResult
    {
        $resolved = $this->suffixes->resolve($tld);

        if ($resolved === null) {
            // Not in the root zone and no WHOIS service: a real answer.
            return new CheckResult(
                $name,
                $tld,
                'uncertain',
                'config',
                'domain',
                '',
                "Suffix '.{$tld}' is not delegated (no RDAP or WHOIS service found)",
                null,
                null,
                date('c'),
                false
            );
        }

        if ($resolved['type'] === 'rdap') {
            return $this->rdapClient->check($name, $tld, $resolved['base']);
        }

        return $this->whoisClient->check($name, $tld, $resolved);
    }

    private function resultFromRow(array $row): CheckResult
    {
        $detail = $row['detail'] ?? null;
        if ($detail !== null) {
            $decoded = json_decode((string) $detail, true);
            $detail  = is_string($decoded) ? $decoded : (is_array($decoded) ? json_encode($decoded) : null);
        }

        // Domain rows store platform='' in the cache; the display platform is the
        // source ('rdap'/'whois'), so fall back to it on read-back.
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
