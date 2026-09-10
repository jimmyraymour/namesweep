<?php

declare(strict_types=1);

namespace NameSweep\Providers\Pricing;

use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * What a suffix costs to register, renew and transfer.
 *
 * Source is Porkbun's public pricing endpoint, which needs no key and no
 * account and covers 907 suffixes including multi-label ones such as `com.mx`
 * (verified 2026-08-19). Cached for 24h in `storage/cache/tld_pricing.json`.
 *
 * These are one registrar's retail prices, not a market average. That is an
 * honest, checkable number rather than a guess, and every result says where it
 * came from via `source`. Suffixes Porkbun does not sell (`.do`, `.ht`) fall
 * through to `config/pricing_overrides.php`.
 *
 * Prices are read fresh from this table on every report rather than stored on
 * the cached check row: a price is a property of the suffix, not of a
 * particular lookup, and copying it onto rows would let a 30-day trademark row
 * carry a stale figure. (This is why the planned `002_pricing.sql` migration
 * turned out not to be needed.)
 */
final class TldPricing
{
    private const DEFAULT_MAX_AGE = 86400; // 24h

    /** @var array<string, array>|null decoded price table, memoised */
    private ?array $table = null;

    /**
     * @param array $overrides config/pricing_overrides.php
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $cacheFile,
        private readonly string $endpoint,
        private readonly array $overrides,
        private readonly Logger $logger,
        private readonly int $maxAgeSeconds = self::DEFAULT_MAX_AGE
    ) {
    }

    /**
     * @return array{registration: ?float, renewal: ?float, transfer: ?float, currency: string, source: string}|null
     */
    public function forSuffix(string $suffix): ?array
    {
        $suffix = strtolower(trim($suffix, ". \t\n\r"));
        if ($suffix === '') {
            return null;
        }

        $row = $this->table()[$suffix] ?? null;
        if ($row !== null) {
            return [
                'registration' => $this->money($row['registration'] ?? null),
                'renewal'      => $this->money($row['renewal'] ?? null),
                'transfer'     => $this->money($row['transfer'] ?? null),
                'currency'     => 'USD',
                'source'       => 'porkbun',
            ];
        }

        $override = $this->overrides['suffixes'][$suffix] ?? null;
        if ($override !== null) {
            $note = isset($override['verified_on']) ? " (as of {$override['verified_on']})" : '';
            return [
                'registration' => $this->money($override['registration'] ?? null),
                'renewal'      => $this->money($override['renewal'] ?? null),
                'transfer'     => $this->money($override['transfer'] ?? null),
                'currency'     => (string) ($override['currency'] ?? 'USD'),
                'source'       => (string) ($override['source'] ?? 'manual') . $note,
            ];
        }

        return null;
    }

    /** Number of suffixes we can price. Used by the discovery endpoint. */
    public function count(): int
    {
        return count($this->table()) + count($this->overrides['suffixes'] ?? []);
    }

    /** @return string[] suffixes available from the pricing source or overrides */
    public function suffixes(): array
    {
        $suffixes = array_map('strval', array_keys($this->table()));
        foreach (array_keys($this->overrides['suffixes'] ?? []) as $suffix) {
            $suffix = (string) $suffix;
            if (!in_array($suffix, $suffixes, true)) {
                $suffixes[] = $suffix;
            }
        }
        sort($suffixes);
        return $suffixes;
    }

    /**
     * @return array<string, array>
     */
    private function table(): array
    {
        if ($this->table !== null) {
            return $this->table;
        }

        if (is_file($this->cacheFile)) {
            $age = time() - (int) filemtime($this->cacheFile);
            if ($age <= $this->maxAgeSeconds) {
                return $this->table = $this->readCache();
            }
        }

        try {
            $this->fetch();
            return $this->table = $this->readCache();
        } catch (\Throwable $e) {
            // A stale table beats no prices at all; overrides still apply.
            $this->logger->warning('TLD pricing refresh failed', ['error' => $e->getMessage()], 'pricing');
            return $this->table = is_file($this->cacheFile) ? $this->readCache() : [];
        }
    }

    private function fetch(): void
    {
        $response = $this->http->get($this->endpoint, ['Accept: application/json']);
        if ($response['status'] !== 200) {
            throw new \RuntimeException("Pricing fetch failed with HTTP {$response['status']}");
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'SUCCESS' || !is_array($data['pricing'] ?? null)) {
            throw new \RuntimeException('Pricing response did not contain a SUCCESS pricing table');
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir: {$dir}");
        }

        $json = json_encode($data['pricing'], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode pricing table');
        }

        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not write pricing cache');
        }
    }

    /**
     * @return array<string, array>
     */
    private function readCache(): array
    {
        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function money(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return round((float) $value, 2);
    }
}
