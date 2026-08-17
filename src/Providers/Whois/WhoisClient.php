<?php

declare(strict_types=1);

namespace NameSweep\Providers\Whois;

use NameSweep\CheckResult;
use NameSweep\Util\Logger;

/**
 * Direct WHOIS on TCP 43.
 *
 * Marker matching priority: registered > for_sale > parked > available.
 * If no marker matches, the raw response (first 200 chars) is logged and
 * `uncertain` is returned — never a guess.
 *
 * When a TLD's marker arrays in config/tlds.php are empty, markers are read
 * from bootstrap.txt at the repo root (filled in by the owner after a VPS
 * test). While both sources are empty, the response is logged and
 * `uncertain` is returned, per the spec's acceptance criteria.
 */
final class WhoisClient
{
    public function __construct(
        private readonly Logger $logger,
        private readonly string $bootstrapFile
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

        $markers = $tldConfig['markers'] ?? [];
        if ($this->allEmpty($markers)) {
            $markers = $this->markersFromBootstrap($tld);
        }

        $matched = $this->matchMarkers($raw, $markers);

        if ($matched === null) {
            $this->logger->warning("WHOIS marker miss for {$domain}", ['raw' => mb_substr($raw, 0, 200)], 'whois');
            return new CheckResult($name, $tld, 'uncertain', 'whois', 'domain', 'whois', mb_substr($raw, 0, 200), null, null, date('c'), false);
        }

        return new CheckResult($name, $tld, $matched, 'whois', 'domain', 'whois', null, null, null, date('c'), false);
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
            if (preg_match('/^\[([a-z0-9]+)\]$/i', $line, $m)) {
                $section = strtolower($m[1]);
                continue;
            }
            if ($section !== $tld && !in_array($section, [$tld], true)) {
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
     */
    private function matchMarkers(string $raw, array $markers): ?string
    {
        // Priority: registered > for_sale > parked > available.
        foreach (['registered', 'for_sale', 'parked', 'available'] as $status) {
            foreach ($markers[$status] ?? [] as $marker) {
                if ($marker !== '' && stripos($raw, $marker) !== false) {
                    return $status;
                }
            }
        }
        return null;
    }
}
