<?php

declare(strict_types=1);

namespace NameSweep\Storage;

/**
 * Runtime settings persisted to storage/settings.json (gitignored).
 *
 * The Settings page writes here; bootstrap.php merges the overrides into the
 * config arrays on every process start (each web request reloads bootstrap,
 * so edits take effect immediately). Config files stay the versioned source
 * of truth; this file holds only machine-local edits.
 */
final class SettingsStore
{
    private const MAX_BYTES = 65536;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return array{prompts?: array<string,string>, tlds?: string[]} decoded overrides
     */
    public function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '' || strlen($raw) > self::MAX_BYTES) {
            return [];
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    public function save(array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || strlen($json) > self::MAX_BYTES) {
            return false;
        }

        $tmp = $this->file . '.tmp';
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            return false;
        }
        return rename($tmp, $this->file);
    }

    public function prompts(array $defaults): array
    {
        $saved = $this->load()['prompts'] ?? [];
        $merged = $defaults;
        foreach ($saved as $key => $value) {
            if (is_string($value) && isset($merged[$key])) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    /** @return string[]|null enabled suffixes, or null when the config default applies */
    public function tlds(): ?array
    {
        $saved = $this->load()['tlds'] ?? null;
        if (!is_array($saved)) {
            return null;
        }
        $tlds = $this->cleanSuffixes($saved);
        return $tlds === [] ? null : $tlds;
    }

    /**
     * Suffixes the owner typed in by hand on the Settings page. Additive —
     * since M9 any delegated suffix is checkable, so these exist only to put
     * the suffix in front of you in the UI.
     *
     * @return string[]
     */
    public function extraTlds(): array
    {
        $saved = $this->load()['extra_tlds'] ?? null;
        return is_array($saved) ? $this->cleanSuffixes($saved) : [];
    }

    /**
     * Normalises and validates a suffix list, accepting multi-label suffixes
     * such as `com.do`. Mirrors SuffixResolver::isValid().
     *
     * @return string[]
     */
    public function cleanSuffixes(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $suffix = strtolower(trim((string) $value, ". \t\n\r\0\x0B"));
            if ($suffix === '' || strlen($suffix) > 64 || isset($out[$suffix])) {
                continue;
            }
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $suffix)) {
                $out[$suffix] = true;
            }
        }
        return array_keys($out);
    }
}