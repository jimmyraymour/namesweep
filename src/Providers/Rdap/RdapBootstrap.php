<?php

declare(strict_types=1);

namespace NameSweep\Providers\Rdap;

use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * Loads and caches the IANA RDAP bootstrap (data.iana.org/rdap/dns.json).
 *
 * - Fresh cache file (<= 7 days old) is used as-is.
 * - Stale cache file: refresh in the background of the request; if the fetch
 *   fails, fall back to the stale cache with a WARN.
 * - No cache file: fetch; if the fetch fails, throw — the caller falls back
 *   to WHOIS where possible.
 */
final class RdapBootstrap
{
    private const DEFAULT_MAX_AGE = 7 * 86400;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $cacheFile,
        private readonly string $bootstrapUrl,
        private readonly Logger $logger,
        private readonly int $maxAgeSeconds = self::DEFAULT_MAX_AGE
    ) {
    }

    /**
     * Returns the RDAP base URL for a TLD, or null if the TLD is not served.
     */
    public function baseUrlFor(string $tld): ?string
    {
        $bootstrap = $this->load();
        foreach ($bootstrap['services'] ?? [] as $service) {
            $tlds = $service[0] ?? [];
            $urls = $service[1] ?? [];
            if (in_array($tld, $tlds, true)) {
                return $urls[0] ?? null;
            }
        }
        return null;
    }

    /**
     * @return array{services: array}
     */
    private function load(): array
    {
        if (is_file($this->cacheFile)) {
            $age = time() - filemtime($this->cacheFile);
            if ($age <= $this->maxAgeSeconds) {
                return $this->readCache();
            }
            // Stale — try to refresh, keep the stale copy on failure.
            try {
                $this->fetch();
                return $this->readCache();
            } catch (\Throwable $e) {
                $this->logger->warning('RDAP bootstrap refresh failed; using stale cache', ['error' => $e->getMessage()], 'rdap');
                return $this->readCache();
            }
        }

        $this->fetch();
        return $this->readCache();
    }

    private function fetch(): void
    {
        $response = $this->http->get($this->bootstrapUrl);
        if ($response['status'] !== 200) {
            throw new \RuntimeException("RDAP bootstrap fetch failed with HTTP {$response['status']}");
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['services'])) {
            throw new \RuntimeException('RDAP bootstrap response did not contain a services list');
        }
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache dir: {$dir}");
        }
        file_put_contents($this->cacheFile, $response['body'], LOCK_EX);
    }

    private function readCache(): array
    {
        $raw = file_get_contents($this->cacheFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data) || !isset($data['services'])) {
            throw new \RuntimeException('RDAP bootstrap cache file is corrupt');
        }
        return $data;
    }
}
