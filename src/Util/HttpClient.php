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
final class HttpClient implements HttpClientInterface
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

    /**
     * POSTs a raw (already-encoded) JSON body.
     *
     * @param string[] $headers extra headers, e.g. ['Authorization: Bearer x']
     * @return array{status:int, body:string}
     */
    public function postJson(string $url, string $jsonBody, int $timeout = 60, array $headers = []): array
    {
        $ch = curl_init($url);
        $allHeaders = array_merge(['Content-Type: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_USERAGENT      => $this->userAgent,
            CURLOPT_HTTPHEADER     => $allHeaders,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $err   = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error ({$errno}): {$err}", $errno);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Performs many GET requests concurrently, capped at `$concurrency`.
     *
     * Each job is keyed by the caller (e.g. a TLD); the YAML/array shape mirrors
     * get() so request-level options stay consistent. Transport failures are NOT
     * thrown here — the per-key result carries `ok=false` + `error` so a caller
     * can fan out and then decide per request.
     *
     * @param array<string, array{url:string, headers?:string[], maxRedirects?:int}> $jobs
     * @return array<string, array{ok:bool, status:int, body:string, error:?string}>
     */
    public function getMulti(array $jobs, int $concurrency = 5): array
    {
        if ($jobs === []) {
            return [];
        }
        $concurrency = max(1, min(25, $concurrency));

        $results = [];
        $mh = curl_multi_init();
        $handles = [];
        $queue = $jobs;
        $active = 0;

        $start = function (string $key, array $job) use (&$handles, &$active, &$queue, $mh): void {
            $ch = curl_init($job['url']);
            $headers = $job['headers'] ?? [];
            $maxRedirects = (int) ($job['maxRedirects'] ?? 3);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => $maxRedirects > 0,
                CURLOPT_MAXREDIRS      => $maxRedirects,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
                CURLOPT_USERAGENT      => $this->userAgent,
                CURLOPT_HTTPHEADER     => $headers,
            ]);
            $handles[$key] = $ch;
            curl_multi_add_handle($mh, $ch);
            $active++;
            unset($queue[$key]);
        };

        // Seed the first window.
        foreach ($jobs as $key => $job) {
            if ($active >= $concurrency) {
                break;
            }
            $start($key, $job);
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.25);
            }

            // Reap finished handles.
            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch     = $info['handle'];
                $key    = (string) array_search($ch, $handles, true);
                curl_multi_remove_handle($mh, $ch);

                $body = curl_multi_getcontent($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                unset($handles[$key]);
                $active--;

                if ($body === false || $err !== '') {
                    $results[$key] = ['ok' => false, 'status' => 0, 'body' => '', 'error' => $err !== '' ? "cURL: {$err}" : 'cURL: empty response'];
                } else {
                    $results[$key] = ['ok' => true, 'status' => $code, 'body' => (string) $body, 'error' => null];
                }

                // Start a replacement if more work is queued.
                if ($queue !== []) {
                    $next = array_key_first($queue);
                    $start((string) $next, $queue[$next]);
                }
            }
            if ($status === CURLM_CALL_MULTI_PERFORM) {
                curl_multi_exec($mh, $running);
            }
        } while ($active > 0);

        // Any jobs that never started (shouldn't happen) → transport failure.
        foreach ($queue as $key => $_job) {
            $results[$key] = ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cURL: request not started'];
        }

        curl_multi_close($mh);
        return $results;
    }
}
