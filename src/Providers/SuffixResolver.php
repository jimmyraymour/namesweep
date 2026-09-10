<?php

declare(strict_types=1);

namespace NameSweep\Providers;

use NameSweep\Providers\Rdap\RdapBootstrap;
use NameSweep\Providers\Whois\IanaWhoisDirectory;
use NameSweep\Util\Logger;

/**
 * Works out how to check any domain suffix — the replacement for the closed
 * allowlist that used to live in config/tlds.php.
 *
 * Resolution order:
 *   1. config/tlds.php — hand-verified overrides and marker sets win outright.
 *   2. IANA RDAP bootstrap — ~1200 TLDs, already cached for the domain module.
 *      A multi-label suffix (com.do, co.uk) is looked up whole first, then by
 *      its last label, because the registry serves its entire namespace.
 *   3. IANA WHOIS directory — whois.iana.org names the authoritative WHOIS
 *      host for every remaining delegated TLD (.io, .co, .me, .do, ...).
 *   4. Nothing — the suffix is not delegated, which is a real answer, not a
 *      gap in our coverage.
 *
 * resolve() returns a config/tlds.php-shaped array so DomainModule treats a
 * discovered suffix exactly like a configured one.
 */
final class SuffixResolver
{
    /** Longest suffix we accept — `checks`.`tld` is VARCHAR(64). */
    public const MAX_LENGTH = 64;

    /** @var array<string, array|null> in-process memo, keyed by suffix */
    private array $memo = [];

    /**
     * @param array<string, array> $tldConfig config/tlds.php, without `_default`
     */
    public function __construct(
        private readonly array $tldConfig,
        private readonly RdapBootstrap $bootstrap,
        private readonly IanaWhoisDirectory $iana,
        private readonly Logger $logger
    ) {
    }

    /**
     * Lower-cases and strips leading/trailing dots and whitespace.
     * Does not validate — pair with isValid().
     */
    public static function normalize(string $suffix): string
    {
        return strtolower(trim($suffix, ". \t\n\r\0\x0B"));
    }

    /**
     * Accepts one or more dot-separated DNS labels, so multi-label suffixes
     * such as `com.do` and `co.uk` are first-class.
     */
    public static function isValid(string $suffix): bool
    {
        if ($suffix === '' || strlen($suffix) > self::MAX_LENGTH) {
            return false;
        }
        return (bool) preg_match(
            '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/',
            $suffix
        );
    }

    /** The registry-level label: `do` for `com.do`, `uk` for `co.uk`. */
    public static function lastLabel(string $suffix): string
    {
        $parts = explode('.', $suffix);
        return (string) end($parts);
    }

    /**
     * How to check this suffix, or null when it cannot be resolved.
     *
     * @return array{type: string, base?: string, host?: string, port?: int, markers?: array, origin: string}|null
     */
    public function resolve(string $suffix): ?array
    {
        if (array_key_exists($suffix, $this->memo)) {
            return $this->memo[$suffix];
        }
        return $this->memo[$suffix] = $this->resolveUncached($suffix);
    }

    private function resolveUncached(string $suffix): ?array
    {
        if (!self::isValid($suffix)) {
            return null;
        }

        $last = self::lastLabel($suffix);

        // 1. Explicit config wins — these entries carry hand-verified markers.
        $cfg = $this->tldConfig[$suffix] ?? null;
        if ($cfg !== null) {
            $resolved = $this->fromConfig($suffix, $last, $cfg);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        // 2. RDAP: the whole suffix, then the registry that serves it.
        $base = $this->rdapBase($suffix);
        if ($base !== null) {
            return ['type' => 'rdap', 'base' => $base, 'origin' => 'bootstrap'];
        }
        if ($last !== $suffix) {
            $base = $this->rdapBase($last);
            if ($base !== null) {
                return ['type' => 'rdap', 'base' => $base, 'origin' => 'bootstrap-parent'];
            }
        }

        // 3. Whatever WHOIS host IANA names for the registry.
        $host = $this->iana->hostFor($last);
        if ($host !== null) {
            return ['type' => 'whois', 'host' => $host, 'port' => 43, 'markers' => [], 'origin' => 'iana'];
        }

        $this->logger->warning("No RDAP or WHOIS service found for .{$suffix}", ['last_label' => $last], 'domain');
        return null;
    }

    /**
     * @param array $cfg one config/tlds.php entry
     * @return array{type: string, base?: string, host?: string, port?: int, markers?: array, origin: string}|null
     */
    private function fromConfig(string $suffix, string $last, array $cfg): ?array
    {
        if (($cfg['type'] ?? 'rdap') === 'rdap') {
            $base = $this->rdapBase($suffix) ?? ($last !== $suffix ? $this->rdapBase($last) : null);
            if ($base !== null) {
                return ['type' => 'rdap', 'base' => $base, 'origin' => 'config'];
            }
            // Configured as RDAP but absent from the bootstrap (this is how
            // .io behaves) — fall through to the entry's WHOIS details.
        }

        $host = $cfg['host'] ?? '';
        if ($host === '') {
            $host = (string) ($this->iana->hostFor($last) ?? '');
        }
        if ($host === '') {
            return null;
        }

        return [
            'type'    => 'whois',
            'host'    => $host,
            'port'    => (int) ($cfg['port'] ?? 43),
            'markers' => $cfg['markers'] ?? [],
            'origin'  => 'config',
        ];
    }

    /**
     * The bootstrap throws when it has no cache and cannot fetch one; that is
     * not fatal here, because WHOIS can still answer.
     */
    private function rdapBase(string $suffix): ?string
    {
        try {
            return $this->bootstrap->baseUrlFor($suffix);
        } catch (\Throwable $e) {
            $this->logger->warning('RDAP bootstrap unavailable; falling back to WHOIS', ['error' => $e->getMessage()], 'domain');
            return null;
        }
    }
}
