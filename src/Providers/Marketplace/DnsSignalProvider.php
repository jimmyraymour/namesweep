<?php

declare(strict_types=1);

namespace NameSweep\Providers\Marketplace;

use NameSweep\CheckResult;
use NameSweep\Util\Logger;

/**
 * Reads a domain's delegation to decide whether it is parked or listed for sale.
 *
 * This is the primary aftermarket tier because it is the only one that
 * actually works without an account. The marketplace landing pages are
 * JavaScript-rendered: Afternic, DAN and NameFind answer a bare HTTPS request
 * with 114 bytes of `window.location.href="/lander"` and nothing else, so
 * `ParkedPageDetector` finds no signature and the domain is reported
 * available. The nameservers say plainly who holds it.
 *
 * Signals, in order:
 *   1. NS delegated to a known marketplace/broker  -> for_sale
 *   2. NS delegated to a known parking operator    -> parked
 *   3. No NS at all on a registered domain         -> registered (dormant)
 *   4. Anything else                               -> null (no opinion)
 *
 * Never guesses. An unrecognised nameserver returns null so the next tier gets
 * a turn, because most registered domains are ordinary websites.
 */
final class DnsSignalProvider implements MarketplaceProviderInterface
{
    /**
     * @param array $config config/parking.php
     */
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger
    ) {
    }

    public function key(): string
    {
        return 'dnssignal';
    }

    public function check(string $name, string $tld): ?CheckResult
    {
        $domain = $name . '.' . $tld;
        $hosts  = $this->nameservers($domain);

        if ($hosts === []) {
            // A registered domain with no delegation is dormant: not usable,
            // but nothing says it is for sale either.
            return new CheckResult(
                $name,
                $tld,
                'registered',
                'dnssignal',
                'marketplace',
                $this->key(),
                'Registered but not delegated (no nameservers) — dormant, no aftermarket listing found',
                null,
                null,
                date('c'),
                false
            );
        }

        $operators = $this->config['nameservers'] ?? [];

        foreach ($hosts as $host) {
            $operatorDomain = $this->registrableDomain($host);
            $match = $operators[$operatorDomain] ?? null;
            if ($match === null) {
                continue;
            }

            $label  = (string) ($match['label'] ?? $operatorDomain);
            $status = (string) ($match['status'] ?? 'parked');

            $this->logger->info(
                "DNS aftermarket signal for {$domain}",
                ['ns' => $host, 'operator' => $label, 'status' => $status],
                'marketplace'
            );

            return new CheckResult(
                $name,
                $tld,
                $status,
                'dnssignal',
                'marketplace',
                $this->key(),
                "Nameservers point to {$label} ({$host})",
                null, // the delegation proves the listing, never the price
                $this->listingUrl($label, $domain),
                date('c'),
                false
            );
        }

        return null; // ordinary hosting — let the next tier look
    }

    /**
     * @return string[] nameserver hostnames, lower-cased, no trailing dot
     */
    private function nameservers(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_NS);
        if ($records === false || $records === []) {
            return [];
        }

        $hosts = [];
        foreach ($records as $record) {
            $target = $record['target'] ?? '';
            if (is_string($target) && $target !== '') {
                $hosts[] = strtolower(rtrim($target, '.'));
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Last two labels of a nameserver hostname, which is where the operator
     * lives: `ns1.afternic.com` and `dns2.afternic.com` both give
     * `afternic.com`.
     */
    private function registrableDomain(string $host): string
    {
        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            return $host;
        }
        return implode('.', array_slice($parts, -2));
    }

    private function listingUrl(string $label, string $domain): ?string
    {
        $template = $this->config['listing_urls'][$label] ?? null;
        return is_string($template) ? str_replace('{domain}', $domain, $template) : null;
    }
}
