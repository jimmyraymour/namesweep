<?php

declare(strict_types=1);

namespace NameSweep\Storage;

/**
 * API key storage. Keys are stored only as sha256 hashes; the plaintext is
 * shown once at creation and never persisted.
 */
final class ApiKeyRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findByHash(string $hash): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL LIMIT 1');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function create(string $name, string $plaintext): array
    {
        $hash   = hash('sha256', $plaintext);
        $prefix = substr($plaintext, 0, 8);

        $stmt = $this->db->pdo()->prepare('INSERT INTO api_keys (name, key_hash, key_prefix) VALUES (?, ?, ?)');
        $stmt->execute([$name, $hash, $prefix]);

        return [
            'id'     => (int) $this->db->pdo()->lastInsertId(),
            'name'   => $name,
            'key'    => $plaintext,
            'prefix' => $prefix,
            'hash'   => $hash,
        ];
    }

    public function all(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, name, key_prefix, created_at, last_used_at, revoked_at FROM api_keys ORDER BY id'
        );
        return $stmt->fetchAll();
    }

    public function revoke(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function touchLastUsed(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?');
        $stmt->execute([$id]);
    }
}
