<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckRequest;
use NameSweep\CheckResult;
use NameSweep\Providers\Rdap\RdapBootstrap;
use NameSweep\Providers\Rdap\RdapClient;
use NameSweep\Providers\Whois\WhoisClient;
use NameSweep\Storage\CheckRepository;
use NameSweep\Util\Logger;

/**
 * Domain registration status per TLD.
 *
 * Provider order per TLD config:
 *   type=rdap  -> RdapClient (via IANA bootstrap)
 *   type=whois -> WhoisClient (TCP 43)
 *   rdap TLD missing from the bootstrap -> WHOIS if a host is configured
 * If both fail, the TLD result is `uncertain` with the reason in detail.
 */
final class DomainModule implements ModuleInterface
{
    public function __construct(
        private readonly array $tldConfig,
        private readonly array $defaultTlds,
        private readonly int $ttl,
        private readonly RdapBootstrap $rdapBootstrap,
        private readonly RdapClient $rdapClient,
        private readonly WhoisClient $whoisClient,
        private readonly CheckRepository $repo,
        private readonly Logger $logger
    ) {
    }

    public function name(): string
    {
        return 'domain';
    }

    public function check(CheckRequest $req): array
    {
        $tlds = $req->tlds !== [] ? $req->tlds : $this->defaultTlds;
        $results = [];

        foreach ($tlds as $tld) {
            $tld = strtolower(ltrim((string) $tld, '.'));
            if ($tld === '') {
                continue;
            }

            if (!isset($this->tldConfig[$tld])) {
                $this->logger->warning("Unsupported TLD .{$tld}", [], 'domain');
                $results[] = new CheckResult($req->name, $tld, 'uncertain', 'config', 'domain', '', "Unsupported TLD '{$tld}'", null, null, date('c'), false);
                continue;
            }

            if ($req->useCache) {
                $row = $this->repo->findFresh($req->name, $tld, 'domain', '', $this->ttl);
                if ($row !== null) {
                    $results[] = $this->resultFromRow($row);
                    continue;
                }
            }

            try {
                $results[] = $this->checkTld($req->name, $tld, $this->tldConfig[$tld]);
            } catch (\Throwable $e) {
                $this->logger->warning("Domain check failed for {$req->name}.{$tld}", ['error' => $e->getMessage()], 'domain');
                $results[] = new CheckResult($req->name, $tld, 'uncertain', 'domain', 'domain', '', $e->getMessage(), null, null, date('c'), false);
            }
        }

        return $results;
    }

    private function checkTld(string $name, string $tld, array $tldConfig): CheckResult
    {
        $type = $tldConfig['type'] ?? 'rdap';

        if ($type === 'rdap') {
            $base = $this->rdapBootstrap->baseUrlFor($tld);
            if ($base !== null) {
                return $this->rdapClient->check($name, $tld, $base);
            }
            // RDAP TLD missing from the bootstrap -> fall through to WHOIS
        }

        return $this->whoisClient->check($name, $tld, $tldConfig);
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
