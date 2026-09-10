<?php

declare(strict_types=1);

namespace NameSweep\Providers\Whois;

use NameSweep\Util\Logger;

/**
 * Resolves the authoritative WHOIS host for a TLD via whois.iana.org (TCP 43).
 *
 * IANA's root-zone database answers a bare TLD query with the delegation
 * record, which carries a `whois:` line for every TLD that runs a WHOIS
 * service. This is what lets NameSweep check any delegated suffix without a
 * hand-maintained server table — the RDAP bootstrap covers ~1200 TLDs, and
 * this covers the rest (.io, .co, .me, .do, ...).
 *
 * Answers are cached in storage/cache/whois_servers.json for 30 days.
 * Negative answers are cached too, so an undelegated suffix costs one lookup
 * rather than one per check.
 */
final class IanaWhoisDirectory
{
    private const DEFAULT_MAX_AGE = 30 * 86400;

    /** @var array<string, array{host: ?string, delegated: bool, at: int}>|null */
    private ?array $entries = null;

    private bool $dirty = false;

    public function __construct(
        private readonly string $cacheFile,
        private readonly Logger $logger,
        private readonly int $maxAgeSeconds = self::DEFAULT_MAX_AGE,
        private readonly string $server = 'whois.iana.org',
        private readonly int $port = 43,
        private readonly int $timeout = 10
    ) {
    }

    /**
     * Authoritative WHOIS host for a TLD, or null when IANA lists none
     * (either the TLD is undelegated or it runs no WHOIS service).
     */
    public function hostFor(string $tld): ?string
    {
        return $this->lookup($tld)['host'];
    }

    /** True when IANA has a root-zone delegation record for the TLD. */
    public function isDelegated(string $tld): bool
    {
        return $this->lookup($tld)['delegated'];
    }

    /**
     * @return array{host: ?string, delegated: bool}
     */
    public function lookup(string $tld): array
    {
        $tld = strtolower(trim($tld, ". \t\n\r"));
        if ($tld === '' || !preg_match('/^[a-z0-9-]{1,63}$/', $tld)) {
            return ['host' => null, 'delegated' => false];
        }

        $this->loadCache();

        $entry = $this->entries[$tld] ?? null;
        if ($entry !== null && (time() - $entry['at']) <= $this->maxAgeSeconds) {
            return ['host' => $entry['host'], 'delegated' => $entry['delegated']];
        }

        try {
            $fresh = $this->query($tld);
        } catch (\Throwable $e) {
            $this->logger->warning("IANA WHOIS directory lookup failed for .{$tld}", ['error' => $e->getMessage()], 'whois');
            // A stale entry beats no answer at all.
            if ($entry !== null) {
                return ['host' => $entry['host'], 'delegated' => $entry['delegated']];
            }
            return ['host' => null, 'delegated' => false];
        }

        $this->entries[$tld] = $fresh + ['at' => time()];
        $this->dirty = true;
        $this->writeCache();

        return $fresh;
    }

    /**
     * @return array{host: ?string, delegated: bool}
     */
    private function query(string $tld): array
    {
        $socket = @fsockopen($this->server, $this->port, $errno, $errstr, $this->timeout);
        if ($socket === false) {
            throw new \RuntimeException("Connection to {$this->server}:{$this->port} failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);
        fwrite($socket, $tld . "\r\n");

        $raw = '';
        while (!feof($socket)) {
            $chunk = fread($socket, 4096);
            if ($chunk === false) {
                break;
            }
            $raw .= $chunk;
            if (strlen($raw) > 65536) {
                break;
            }
            $meta = stream_get_meta_data($socket);
            if (!empty($meta['timed_out'])) {
                break;
            }
        }
        fclose($socket);

        // "This query returned 0 objects." => the suffix is not in the root zone.
        if (stripos($raw, 'returned 0 objects') !== false) {
            return ['host' => null, 'delegated' => false];
        }

        if (preg_match('/^whois:\s*(\S+)\s*$/mi', $raw, $m)) {
            return ['host' => strtolower($m[1]), 'delegated' => true];
        }

        // Delegated (IANA answered with a record) but runs no WHOIS service.
        $delegated = stripos($raw, 'domain:') !== false;

        return ['host' => null, 'delegated' => $delegated];
    }

    private function loadCache(): void
    {
        if ($this->entries !== null) {
            return;
        }
        $this->entries = [];

        if (!is_file($this->cacheFile)) {
            return;
        }
        $raw = file_get_contents($this->cacheFile);
        if ($raw === false || $raw === '') {
            return;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }
        foreach ($data as $tld => $entry) {
            if (is_string($tld) && is_array($entry) && isset($entry['at'])) {
                $this->entries[$tld] = [
                    'host'      => isset($entry['host']) && is_string($entry['host']) ? $entry['host'] : null,
                    'delegated' => (bool) ($entry['delegated'] ?? false),
                    'at'        => (int) $entry['at'],
                ];
            }
        }
    }

    private function writeCache(): void
    {
        if (!$this->dirty) {
            return;
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger->warning("Cannot create cache dir: {$dir}", [], 'whois');
            return;
        }

        $json = json_encode($this->entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        // Write via a temp file so a concurrent reader never sees a half file.
        $tmp = $this->cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            return;
        }
        if (!rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
            return;
        }
        $this->dirty = false;
    }
}
