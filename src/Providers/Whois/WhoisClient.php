<?php

declare(strict_types=1);

namespace NameSweep\Providers\Whois;

use NameSweep\CheckResult;
use NameSweep\Util\Logger;

/**
 * Direct WHOIS on TCP 43.
 *
 * Markers come from the first source that has any, in order:
 *   1. the suffix's own `markers` in config/tlds.php
 *   2. bootstrap.txt at the repo root (hand-filled from live probes)
 *   3. the generic `_default` set in config/tlds.php
 *
 * Match priority is configurable per source. Per-suffix sets keep the spec's
 * `registered > for_sale > parked > available` order; the generic set checks
 * `available` first, because several registries echo "Domain Name: <query>"
 * back in a not-found response (see config/tlds.php `_default`).
 *
 * If no marker matches, the raw response is logged and `uncertain` is
 * returned — never a guess.
 */
final class WhoisClient
{
    private const DEFAULT_PRIORITY = ['registered', 'for_sale', 'parked', 'available'];

    /**
     * @param array $defaultMarkers the `_default` entry from config/tlds.php
     */
    public function __construct(
        private readonly Logger $logger,
        private readonly string $bootstrapFile,
        private readonly array $defaultMarkers = []
    ) {
    }

    public function check(string $name, string $tld, array $tldConfig): CheckResult
    {
        $domain = $name . '.' . $tld;
        $host   = $tldConfig['host'] ?? null;
        $port   = (int) ($tldConfig['port'] ?? 43);

        if ($host === null || $host === '') {
            return new CheckResult($name, $tld, 'uncertain', 'whois', 'domain', 'whois', "No WHOIS host configured for .{$tld}", null, null, date('c'), false);
        }

        $raw = $this->query($host, $port, $domain); // throws on network failure

        [$markers, $priority] = $this->markersFor($tld, $tldConfig);
        $matched = $this->matchMarkers($raw, $markers, $priority);

        if ($matched !== null) {
            return new CheckResult($name, $tld, $matched, 'whois', 'domain', 'whois', null, null, null, date('c'), false);
        }

        // Only now consider a rate-limit error: some registries carry
        // rate-limit boilerplate inside perfectly good records.
        if ($this->isRateLimited($raw)) {
            $this->logger->warning("WHOIS rate limited for {$domain}", ['host' => $host], 'whois');
            return new CheckResult($name, $tld, 'uncertain', 'whois', 'domain', 'whois', 'rate limited by ' . $host, null, null, date('c'), false);
        }

        $this->logger->warning("WHOIS marker miss for {$domain}", ['raw' => mb_substr($raw, 0, 200)], 'whois');
        return new CheckResult($name, $tld, 'uncertain', 'whois', 'domain', 'whois', mb_substr($raw, 0, 200), null, null, date('c'), false);
    }

    /**
     * @return array{0: array<string, string[]>, 1: string[]} markers, priority
     */
    private function markersFor(string $tld, array $tldConfig): array
    {
        $configured = $tldConfig['markers'] ?? [];
        if (!$this->allEmpty($configured)) {
            return [$configured, $tldConfig['priority'] ?? self::DEFAULT_PRIORITY];
        }

        $bootstrap = $this->markersFromBootstrap($tld);
        if (!$this->allEmpty($bootstrap)) {
            return [$bootstrap, self::DEFAULT_PRIORITY];
        }

        return [
            $this->defaultMarkers['markers'] ?? [],
            $this->defaultMarkers['priority'] ?? self::DEFAULT_PRIORITY,
        ];
    }

    private function isRateLimited(string $raw): bool
    {
        foreach ($this->defaultMarkers['rate_limit_markers'] ?? [] as $marker) {
            if ($marker !== '' && stripos($raw, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    private function query(string $host, int $port, string $domain): string
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if ($socket === false) {
            throw new \RuntimeException("WHOIS connection to {$host}:{$port} failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 10);
        fwrite($socket, $domain . "\r\n");

        $raw = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 4096);
            if ($chunk === false) {
                break;
            }
            $raw .= $chunk;
            if (strlen($raw) > 65536) { // safety cap
                break;
            }
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                break;
            }
        }
        fclose($socket);

        return $raw;
    }

    private function allEmpty(array $markers): bool
    {
        foreach ($markers as $list) {
            if (is_array($list) && $list !== []) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, string[]>
     */
    private function markersFromBootstrap(string $tld): array
    {
        $markers = ['available' => [], 'registered' => [], 'parked' => [], 'for_sale' => []];
        if (!is_file($this->bootstrapFile)) {
            return $markers;
        }

        $lines = file($this->bootstrapFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $markers;
        }

        $section = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            // Section headers accept multi-label suffixes, e.g. [com.do].
            if (preg_match('/^\[([a-z0-9.-]+)\]$/i', $line, $m)) {
                $section = strtolower($m[1]);
                continue;
            }
            if ($section !== $tld) {
                continue;
            }
            if (preg_match('/^(\w+)_marker\s*=\s*(.+)$/i', $line, $m)) {
                $key = strtolower($m[1]);
                if (isset($markers[$key])) {
                    $markers[$key][] = trim($m[2]);
                }
            }
        }

        return $markers;
    }

    /**
     * @param array<string, string[]> $markers
     * @param string[] $priority
     */
    private function matchMarkers(string $raw, array $markers, array $priority): ?string
    {
        foreach ($priority as $status) {
            foreach ($markers[$status] ?? [] as $marker) {
                if ($marker !== '' && stripos($raw, $marker) !== false) {
                    return $status;
                }
            }
        }
        return null;
    }
}
