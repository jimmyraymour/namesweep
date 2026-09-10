<?php

declare(strict_types=1);

namespace NameSweep\Suggest;

use NameSweep\Storage\Database;

/**
 * Read/write access to `suggest_sessions` and `suggest_candidates`.
 */
final class SuggestRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function createSession(string $id, string $description, string $model, array $tldFilter, array $modulesFilter): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO suggest_sessions (id, description, model, tld_filter, modules_filter)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $description,
            $model,
            $tldFilter !== [] ? json_encode($tldFilter) : null,
            $modulesFilter !== [] ? json_encode($modulesFilter) : null,
        ]);
    }

    public function addCandidate(string $sessionId, string $name, int $iteration, bool $kept): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO suggest_candidates (session_id, name, iteration, kept)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$sessionId, $name, $iteration, $kept ? 1 : 0]);
    }

    public function finalizeSession(string $sessionId): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE suggest_sessions SET finalized_at = NOW() WHERE id = ? AND finalized_at IS NULL');
        $stmt->execute([$sessionId]);
    }

    public function session(string $sessionId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM suggest_sessions WHERE id = ?');
        $stmt->execute([$sessionId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /** @return array<int, array{name:string, iteration:int, kept:int, created_at:string}> */
    public function candidates(string $sessionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT name, iteration, kept, created_at FROM suggest_candidates
             WHERE session_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        return $stmt->fetchAll();
    }
}