<?php

declare(strict_types=1);

namespace NameSweep\Api;

use NameSweep\Storage\ApiKeyRepository;
use NameSweep\Util\Logger;
use NameSweep\Util\RateLimiter;

/**
 * Bearer-token auth + per-key rate limiting.
 *
 * @return array{ok:bool, status:int, body:array, key:?array}
 */
final class Auth
{
    public function __construct(
        private readonly ApiKeyRepository $keys,
        private readonly RateLimiter $rateLimiter,
        private readonly Logger $logger
    ) {
    }

    public function authenticate(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->deny(401, 'unauthorized', 'Missing or invalid Authorization header');
        }

        $key  = $m[1];
        $hash = hash('sha256', $key);
        $row  = $this->keys->findByHash($hash);
        if ($row === null) {
            $this->logger->warning('API auth failed: unknown key', ['prefix' => substr($key, 0, 8)], 'api');
            return $this->deny(401, 'unauthorized', 'Invalid API key');
        }

        $id = (int) $row['id'];

        $rl = $this->rateLimiter->allow($id);
        if (!$rl['allowed']) {
            return $this->deny(429, 'rate_limited', 'Rate limit exceeded', ['retry_after' => $rl['retry_after']]);
        }

        $this->keys->touchLastUsed($id);

        return ['ok' => true, 'status' => 200, 'body' => [], 'key' => $row];
    }

    private function deny(int $status, string $code, string $message, array $details = []): array
    {
        return [
            'ok'     => false,
            'status' => $status,
            'body'   => ['error' => ['code' => $code, 'message' => $message, 'details' => $details]],
            'key'    => null,
        ];
    }
}
