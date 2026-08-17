<?php

declare(strict_types=1);

namespace NameSweep\Util;

/**
 * Sliding-window rate limiter keyed by API key id.
 *
 * File-backed (one small JSON file per key under storage/tmp) because each
 * HTTP request is a fresh PHP process — pure in-memory state would reset
 * every request and never trigger. Fine for a single-user, single-server
 * deployment; a multi-node setup would need a shared store.
 *
 * @return array{allowed:bool, retry_after:int}
 */
final class RateLimiter
{
    public function __construct(
        private readonly int $perMinute = 60,
        private readonly string $stateDir = 'storage/tmp'
    ) {
    }

    public function allow(int $keyId): array
    {
        $now    = time();
        $window = 60;
        $cutoff = $now - $window;
        $file   = rtrim($this->stateDir, '/') . '/ratelimit-' . $keyId . '.json';

        $hits = [];
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $hits = array_map('intval', $data);
            }
        }
        $hits = array_values(array_filter($hits, static fn (int $t) => $t > $cutoff));

        if (count($hits) >= $this->perMinute) {
            $retryAfter = $hits[0] + $window - $now;
            return ['allowed' => false, 'retry_after' => max(1, $retryAfter)];
        }

        $hits[] = $now;
        @file_put_contents($file, json_encode($hits), LOCK_EX);

        return ['allowed' => true, 'retry_after' => 0];
    }
}
