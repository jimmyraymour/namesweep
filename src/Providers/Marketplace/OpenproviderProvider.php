<?php

declare(strict_types=1);

namespace NameSweep\Providers\Marketplace;

use NameSweep\CheckResult;
use NameSweep\Util\HttpClientInterface;
use NameSweep\Util\Logger;

/**
 * Openprovider aftermarket / premium provider, credential-gated.
 *
 * Wired into the MarketplaceModule only when both OPENPROVIDER_USERNAME and
 * OPENPROVIDER_PASSWORD_HASH are set (see bootstrap.php). It is the "secondary"
 * tier in the aftermarket chain, tried only when RobotDomainSearch has nothing
 * to say.
 *
 * AUTH MODEL (verified against developer.openprovider.com, 2026-09-17).
 * Openprovider does NOT issue a static API key. The hash generated in the
 * control panel is a permanent *password*, exchanged for a short-lived bearer
 * token:
 *
 *   POST {base}/auth/login  {"username": ..., "password": <hash>, "ip": ...}
 *     -> {"code":0,"data":{"token":"...","reseller_id":N},"desc":""}
 *   POST {base}/domains/check   Authorization: Bearer <token>
 *
 * The token is cached on disk so a sweep of many domains logs in once, and a
 * 401 mid-sweep triggers exactly one re-login before giving up. Tokens expire;
 * the 401 retry, not the TTL, is what makes this correct.
 *
 * RESPONSE SHAPE (from the published CheckDomainResponse schema):
 *   data.results[0] = {
 *     domain, status, reason, is_premium, claim_key, whois,
 *     premium: {currency, price:{create}},
 *     price:   {product:{currency,price}, reseller:{currency,price}}
 *   }
 *
 * Per spec 6.2, mapped as:
 *   - is_premium with a non-zero premium create price -> for_sale at that price
 *   - status 'free'            -> available
 *   - status 'active'/'inuse'  -> registered
 *   - anything else            -> uncertain, carrying the raw status
 *
 * UNCONFIRMED: the full `status` enum is not published in the API reference.
 * 'free' / 'active' / 'inuse' are handled; any other value returns `uncertain`
 * with the raw string in the detail and a warning in the log, so an unseen
 * value shows up as a question rather than a wrong answer. Confirm the enum on
 * the sandbox and extend the STATUS_* constants below.
 */
final class OpenproviderProvider implements MarketplaceProviderInterface
{
    private const LOGIN_PATH = 'auth/login';
    private const CHECK_PATH = 'domains/check';

    /** Registry/registrar statuses meaning the name is taken. */
    private const STATUS_REGISTERED = ['active', 'inuse', 'in_use'];

    /** Registry statuses meaning the name can be registered. */
    private const STATUS_AVAILABLE = ['free'];

    private ?string $token = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $username,
        private readonly string $passwordHash,
        private readonly HttpClientInterface $http,
        private readonly Logger $logger,
        private readonly ?string $tokenCachePath = null,
        private readonly string $provider = 'SEDO',
        private readonly int $tokenTtlSeconds = 3000
    ) {
    }

    public function key(): string
    {
        return 'openprovider';
    }

    public function check(string $name, string $tld): ?CheckResult
    {
        $response = $this->checkOnce($name, $tld, $this->token());

        // Token expired mid-sweep: re-login exactly once, then retry.
        if ($response['status'] === 401) {
            $this->logger->info('Openprovider token rejected - re-authenticating', [], 'marketplace');
            $this->forgetToken();
            $response = $this->checkOnce($name, $tld, $this->token());
        }

        // A credential problem is NOT a miss. Throwing here means the module
        // logs it and falls through, instead of silently reporting nothing on
        // every domain for a whole sweep.
        if (in_array($response['status'], [401, 403], true)) {
            throw new \RuntimeException(
                "Openprovider rejected the credentials (HTTP {$response['status']}) - "
                . 'check OPENPROVIDER_USERNAME / OPENPROVIDER_PASSWORD_HASH'
            );
        }

        if ($response['status'] === 404) {
            return null; // no row for this domain -> miss, fall through to parked
        }

        if ($response['status'] !== 200) {
            throw new \RuntimeException("Openprovider HTTP {$response['status']} for {$name}.{$tld}");
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('Openprovider returned invalid JSON');
        }

        if (($data['maintenance'] ?? false) === true) {
            throw new \RuntimeException('Openprovider is in maintenance');
        }

        $results = $data['data']['results'] ?? null;
        if (!is_array($results) || $results === []) {
            return null; // no row for this domain -> miss
        }

        $row = is_array($results[0]) ? $results[0] : [];

        return $this->toResult($name, $tld, $row);
    }

    /** @param array<string, mixed> $row */
    private function toResult(string $name, string $tld, array $row): CheckResult
    {
        $now = date('c');
        $status = strtolower(trim((string) ($row['status'] ?? '')));

        // A registry premium / reserved name is the one thing this tier exists
        // to see: an available name with a price attached.
        $premiumPrice = $this->floatOrNull($row['premium']['price']['create'] ?? null);
        $isPremium = ($row['is_premium'] ?? false) === true;

        if ($isPremium && $premiumPrice !== null && $premiumPrice > 0.0) {
            return new CheckResult(
                $name,
                $tld,
                'for_sale',
                'openprovider',
                'marketplace',
                'openprovider',
                'Registry premium via Openprovider (' . $this->provider . ')',
                $premiumPrice,
                null,
                $now,
                false,
                null,
                null,
                null,
                $this->stringOrNull($row['premium']['currency'] ?? null)
            );
        }

        if (in_array($status, self::STATUS_AVAILABLE, true)) {
            return new CheckResult($name, $tld, 'available', 'openprovider', 'marketplace', 'openprovider', null, null, null, $now, false);
        }

        if (in_array($status, self::STATUS_REGISTERED, true)) {
            $reason = $this->stringOrNull($row['reason'] ?? null);
            return new CheckResult($name, $tld, 'registered', 'openprovider', 'marketplace', 'openprovider', $reason, null, null, $now, false);
        }

        $this->logger->warning(
            "Openprovider returned an unhandled status for {$name}.{$tld}",
            ['status' => $status, 'reason' => (string) ($row['reason'] ?? '')],
            'marketplace'
        );

        return new CheckResult(
            $name,
            $tld,
            'uncertain',
            'openprovider',
            'marketplace',
            'openprovider',
            $status === ''
                ? 'Openprovider returned no status for this domain'
                : "Openprovider returned an unhandled status '{$status}'",
            null,
            null,
            $now,
            false
        );
    }

    /** @return array{status:int, body:string} */
    private function checkOnce(string $name, string $tld, string $token): array
    {
        $payload = json_encode([
            'domains'    => [['name' => $name, 'extension' => $tld]],
            'with_price' => true,
            'provider'   => $this->provider,
        ], JSON_THROW_ON_ERROR);

        try {
            return $this->http->postJson(
                $this->url(self::CHECK_PATH),
                $payload,
                30,
                ['Authorization: Bearer ' . $token]
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException("Openprovider request failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * A usable bearer token: the one in memory, the cached one, or a fresh login.
     */
    private function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $cached = $this->readCachedToken();
        if ($cached !== null) {
            return $this->token = $cached;
        }

        return $this->token = $this->login();
    }

    private function login(): string
    {
        $payload = json_encode([
            'username' => $this->username,
            'password' => $this->passwordHash,
            // Openprovider accepts an IP allowlist hint; 0.0.0.0 means "any",
            // which is what a roaming CLI needs.
            'ip'       => '0.0.0.0',
        ], JSON_THROW_ON_ERROR);

        try {
            $response = $this->http->postJson($this->url(self::LOGIN_PATH), $payload, 30);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Openprovider login failed: {$e->getMessage()}", 0, $e);
        }

        if ($response['status'] !== 200) {
            // Never echo the body - it can quote the submitted credentials back.
            throw new \RuntimeException(
                "Openprovider login rejected (HTTP {$response['status']}) - "
                . 'check OPENPROVIDER_USERNAME / OPENPROVIDER_PASSWORD_HASH'
            );
        }

        $data = json_decode($response['body'], true);
        $token = $data['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Openprovider login returned no token');
        }

        $this->logger->info('Openprovider: authenticated, token cached', [], 'marketplace');
        $this->writeCachedToken($token);

        return $token;
    }

    private function readCachedToken(): ?string
    {
        if ($this->tokenCachePath === null || !is_file($this->tokenCachePath)) {
            return null;
        }

        $raw = @file_get_contents($this->tokenCachePath);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        $token = $data['token'] ?? null;
        $expires = (int) ($data['expires_at'] ?? 0);

        if (!is_string($token) || $token === '' || $expires <= time()) {
            return null;
        }

        return $token;
    }

    private function writeCachedToken(string $token): void
    {
        if ($this->tokenCachePath === null) {
            return;
        }

        $dir = dirname($this->tokenCachePath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = json_encode([
            'token'      => $token,
            'expires_at' => time() + $this->tokenTtlSeconds,
        ], JSON_THROW_ON_ERROR);

        if (@file_put_contents($this->tokenCachePath, $payload, LOCK_EX) !== false) {
            // The token is a live credential - keep it off other accounts.
            @chmod($this->tokenCachePath, 0600);
        }
    }

    private function forgetToken(): void
    {
        $this->token = null;
        if ($this->tokenCachePath !== null && is_file($this->tokenCachePath)) {
            @unlink($this->tokenCachePath);
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . $path;
    }

    private function floatOrNull(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private function stringOrNull(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
