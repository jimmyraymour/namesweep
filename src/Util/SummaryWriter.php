<?php

declare(strict_types=1);

namespace NameSweep\Util;

use NameSweep\Storage\Database;
use NameSweep\Suggest\LlmClient;

/**
 * LLM one-line summary at the top of a report (280-char cap).
 *
 * Cached in `report_summaries` keyed by name; regenerated when the newest
 * check result is newer than the cached summary (results changed since).
 * Only the LLM touches this string — the rest of a report is deterministic.
 * On LLM failure the summary is simply absent (null); the report still works.
 */
final class SummaryWriter
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly Database $db,
        private readonly Logger $logger,
        private readonly array $config
    ) {
    }

    /**
     * Returns the one-line summary for an Engine::checkAsArray output, or null.
     */
    public function forReport(array $checkArray, bool $force = false): ?string
    {
        $name = (string) ($checkArray['name'] ?? '');
        if ($name === '') {
            return null;
        }

        if (!$force) {
            $cached = $this->cached($name);
            if ($cached !== null && strtotime($cached['created_at']) >= $this->newestCheck($checkArray)) {
                return $cached['summary'];
            }
        }

        $prompts = $this->config['prompts'] ?? [];
        $user = str_replace(
            ['{name}', '{results_json}'],
            [$name, json_encode($checkArray['results'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            (string) ($prompts['summary_user'] ?? '')
        );

        try {
            $res = $this->llm->generate(
                (string) ($prompts['summary_system'] ?? ''),
                $user,
                (float) ($this->config['summary']['temperature'] ?? 0.2)
            );
        } catch (\Throwable $e) {
            $this->logger->warning("Summary generation failed for {$name}", ['error' => $e->getMessage()], 'summary');
            return null;
        }

        $summary = mb_substr(trim($res['text']), 0, 280);
        $this->store($name, $summary, $res['model']);
        return $summary;
    }

    private function cached(string $name): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT summary, created_at FROM report_summaries WHERE name = ? ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function store(string $name, string $summary, string $model): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO report_summaries (name, summary, model) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $summary, $model]);
    }

    private function newestCheck(array $checkArray): int
    {
        $newest = 0;
        foreach (($checkArray['results'] ?? []) as $rows) {
            foreach ($rows as $r) {
                $ts = strtotime((string) ($r['checked_at'] ?? ''));
                if ($ts !== false && $ts > $newest) {
                    $newest = $ts;
                }
            }
        }
        return $newest;
    }
}