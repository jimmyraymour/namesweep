<?php

declare(strict_types=1);

namespace NameSweep\Util;

/**
 * Thin cURL wrapper — no Guzzle, no Symfony HttpClient.
 *
 * Returns ['status' => int, 'body' => string] on HTTP responses and throws a
 * \RuntimeException on transport-level failures (DNS, connect, timeout,
 * TLS). Callers decide how to treat non-2xx statuses.
 */
final class HttpClient
{
    public function __construct(
        private readonly int $timeout = 8,
        private readonly string $userAgent = 'NameSweep/1.0'
    ) {
    }

    /**
     * @param string[] $headers e.g. ['Accept: application/json']
     * @return array{status:int, body:string}
     */
    public function get(string $url, array $headers = [], int $maxRedirects = 3): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
            CURLOPT_MAXREDIRS      => $maxRedirects,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err  = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error ({$errno}): {$err}", $errno);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }
}
