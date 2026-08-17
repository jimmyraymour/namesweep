<?php

declare(strict_types=1);

namespace NameSweep\Providers\Rdap;

use NameSweep\CheckResult;
use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;

/**
 * Queries a single RDAP server for a domain's registration status.
 *
 * - HTTP 404 → available
 * - HTTP 200 → registered, unless the RDAP events show an explicit
 *   expiration date in the past
 * - HTTP 429 → retry up to 3 times with exponential backoff (1s, 2s, 4s),
 *   then uncertain
 * - Network/timeout errors → throw; the caller handles them
 */
final class RdapClient
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly Logger $logger
    ) {
    }

    public function check(string $name, string $tld, string $baseUrl): CheckResult
    {
        $domain = $name . '.' . $tld;
        $url    = rtrim($baseUrl, '/') . '/domain/' . $domain;

        $attempts = 0;
        while (true) {
            $attempts++;
            $response = $this->http->get($url, ['Accept: application/rdap+json']);
            if ($response['status'] === 429 && $attempts < 3) {
                sleep(2 ** ($attempts - 1)); // 1s, 2s
                continue;
            }
            break;
        }

        $status = $response['status'];

        if ($status === 404) {
            return new CheckResult($name, $tld, 'available', 'rdap', 'domain', 'rdap', null, null, null, date('c'), false);
        }

        if ($status === 200) {
            if ($this->expired($response['body'])) {
                return new CheckResult($name, $tld, 'available', 'rdap', 'domain', 'rdap', 'RDAP shows expired registration', null, null, date('c'), false);
            }
            return new CheckResult($name, $tld, 'registered', 'rdap', 'domain', 'rdap', null, null, null, date('c'), false);
        }

        if ($status === 429) {
            $this->logger->warning("RDAP rate limited for {$domain}", ['status' => $status], 'rdap');
            return new CheckResult($name, $tld, 'uncertain', 'rdap', 'domain', 'rdap', 'rate limited after retries', null, null, date('c'), false);
        }

        $this->logger->warning("Unexpected RDAP status for {$domain}", ['status' => $status], 'rdap');
        return new CheckResult($name, $tld, 'uncertain', 'rdap', 'domain', 'rdap', "unexpected HTTP {$status}", null, null, date('c'), false);
    }

    private function expired(string $body): bool
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return false;
        }
        foreach ($data['events'] ?? [] as $event) {
            if (($event['eventAction'] ?? '') === 'expiration') {
                $ts = strtotime($event['eventDate'] ?? '');
                if ($ts !== false && $ts < time()) {
                    return true;
                }
            }
        }
        return false;
    }
}
