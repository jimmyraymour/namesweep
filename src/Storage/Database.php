<?php

declare(strict_types=1);

namespace NameSweep\Storage;

use PDO;

/**
 * Thin PDO wrapper. Kept deliberately small so it is easy to read and debug.
 * All queries use prepared statements; this class only hands out the PDO
 * handle.
 *
 * The MySQL session time zone is aligned to the app timezone so NOW() /
 * CURRENT_TIMESTAMP comparisons (e.g. expires_at > NOW()) agree with the
 * timestamps PHP writes.
 */
final class Database
{
    private PDO $pdo;

    public function __construct(array $config, ?string $timezone = null)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'] ?? '127.0.0.1',
            $config['port'] ?? '3306',
            $config['name'] ?? 'namesweep'
        );

        $this->pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        if ($timezone !== null && $timezone !== '') {
            try {
                $offset = (new \DateTime('now', new \DateTimeZone($timezone)))->format('P');
                $this->pdo->exec('SET time_zone = ' . $this->pdo->quote($offset));
            } catch (\Throwable $e) {
                // Non-fatal: default session timezone is fine.
            }
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
