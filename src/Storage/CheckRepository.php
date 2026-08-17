<?php

declare(strict_types=1);

namespace NameSweep\Storage;

use NameSweep\CheckResult;

/**
 * Read/write access to the `checks` result cache.
 *
 * Only the Engine writes (via save()); modules only read (findFresh()).
 */
final class CheckRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Returns a fresh cached row for the exact (name, tld, module, platform)
     * key, or null.
     */
    public function findFresh(string $name, string $tld, string $module, string $platform, int $ttlSeconds): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM checks
             WHERE name = ? AND tld = ? AND module = ? AND platform = ?
               AND expires_at > NOW()
             ORDER BY checked_at DESC LIMIT 1'
        );
        $stmt->execute([$name, $tld, $module, $platform]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function save(CheckResult $r, int $ttlSeconds): void
    {
        $checkedTs = $r->checkedAt !== '' ? strtotime($r->checkedAt) : time();

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO checks (name, tld, module, platform, status, source, detail, price, url, checked_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               status = VALUES(status), source = VALUES(source), detail = VALUES(detail),
               price = VALUES(price), url = VALUES(url),
               checked_at = VALUES(checked_at), expires_at = VALUES(expires_at)'
        );
        $stmt->execute([
            $r->name,
            $r->tld,
            $r->module,
            $this->storagePlatform($r),
            $r->status,
            $r->source,
            $r->detail !== null ? json_encode($r->detail) : null,
            $r->price,
            $r->url,
            date('Y-m-d H:i:s', $checkedTs),
            date('Y-m-d H:i:s', $checkedTs + $ttlSeconds),
        ]);
    }

    /**
     * The cache-key platform. Domain results are not platform-shaped, so they
     * use '' (matching the checks.platform default); other modules key by their
     * provider platform (e.g. 'robotdomainsearch', 'twitter', 'onapi').
     */
    private function storagePlatform(CheckResult $r): string
    {
        return $r->module === 'domain' ? '' : $r->platform;
    }

    public function clearModule(string $module): int
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM checks WHERE module = ?');
        $stmt->execute([$module]);
        return $stmt->rowCount();
    }

    public function clearAll(): int
    {
        return $this->db->pdo()->exec('DELETE FROM checks');
    }
}
