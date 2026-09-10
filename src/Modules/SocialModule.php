<?php

declare(strict_types=1);

namespace NameSweep\Modules;

use NameSweep\CheckRequest;
use NameSweep\CheckResult;
use NameSweep\Providers\Social\SocialProviderInterface;
use NameSweep\Storage\CheckRepository;
use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * SocialModule — handle availability across the platforms in config/social.php.
 *
 * For each platform the module:
 *   1. Reads the fresh cache (7d TTL) when useCache.
 *   2. Builds the profile URL by replacing `{handle}` in the config template.
 *   3. GETs it (one shared HttpClient), then hands status+body to the matching
 *      provider's classify() — which returns available/registered/uncertain
 *      with the "uncertain over guessing" rule.
 * A single platform can be selected via $req->platform (for `/check/social`).
 */
final class SocialModule implements ModuleInterface
{
    public function __construct(
        /** @var array<string, SocialProviderInterface> $providers keyed by platform */
        private readonly array $providers,
        private readonly array $platformConfig,
        private readonly HttpClient $http,
        private readonly int $ttl,
        private readonly CheckRepository $repo,
        private readonly Logger $logger
    ) {
    }

    public function name(): string
    {
        return 'social';
    }

    public function check(CheckRequest $req): array
    {
        $results = [];

        // Determine which platforms to check: all configured, or a single one.
        $platforms = array_keys($this->platformConfig);
        if ($req->platform !== null) {
            if (!isset($this->platformConfig[$req->platform])) {
                $results[] = new CheckResult($req->name, '', 'uncertain', 'config', 'social', $req->platform, "Unknown platform '{$req->platform}'", null, null, date('c'), false);
                return $results;
            }
            $platforms = [$req->platform];
        }

        foreach ($platforms as $platform) {
            $config = $this->platformConfig[$platform];
            $url    = str_replace('{handle}', $req->name, (string) $config['url']);

            if ($req->useCache) {
                $row = $this->repo->findFresh($req->name, '', 'social', $platform, $this->ttl);
                if ($row !== null) {
                    $results[] = $this->resultFromRow($row);
                    continue;
                }
            }

            $results[] = $this->checkPlatform($req->name, $platform, $url);
        }

        return $results;
    }

    private function checkPlatform(string $name, string $platform, string $url): CheckResult
    {
        $provider = $this->providers[$platform] ?? null;
        if ($provider === null) {
            $this->logger->warning("No social provider for platform '{$platform}'", [], 'social');
            return new CheckResult($name, '', 'uncertain', 'config', 'social', $platform, "No provider for platform '{$platform}'", null, $url, date('c'), false);
        }

        try {
            $response   = $this->http->get($url, ['Accept-Language: en-US,en;q=0.9'], 3);
            $classified = $provider->classify($response['status'], $response['body']);
            $status     = $classified['status'];
            $detail     = $classified['detail'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning("Social check failed for {$name} on {$platform}", ['error' => $e->getMessage()], 'social');
            return new CheckResult($name, '', 'uncertain', $platform, 'social', $platform, $e->getMessage(), null, $url, date('c'), false);
        }

        if ($status === 'uncertain') {
            $this->logger->warning("Social uncertain signal for {$name} on {$platform}", ['detail' => $detail], 'social');
        }

        return new CheckResult($name, '', $status, $platform, 'social', $platform, $detail, null, $url, date('c'), false);
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