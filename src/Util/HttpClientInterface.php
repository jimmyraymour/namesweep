<?php

declare(strict_types=1);

namespace NameSweep\Util;

/**
 * The HTTP surface a provider depends on.
 *
 * Exists so providers can be unit-tested against a recorded response instead
 * of the live internet. HttpClient is the only production implementation and
 * stays final; tests supply their own.
 *
 * Implementations return ['status' => int, 'body' => string] for any HTTP
 * response and throw \RuntimeException on transport-level failure (DNS,
 * connect, timeout, TLS). Callers decide how to treat non-2xx statuses.
 */
interface HttpClientInterface
{
    /**
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers = [], int $maxRedirects = 3): array;

    /**
     * @param string[] $headers
     * @return array{status:int, body:string}
     */
    public function postJson(string $url, string $jsonBody, int $timeout = 60, array $headers = []): array;

    /**
     * @param array<string, array{url:string, headers?:string[], maxRedirects?:int}> $jobs
     * @return array<string, array{ok:bool, status:int, body:string, error:?string}>
     */
    public function getMulti(array $jobs, int $concurrency = 5): array;
}
