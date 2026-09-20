<?php

declare(strict_types=1);

namespace NameSweep\Suggest;

use NameSweep\CheckRequest;
use NameSweep\Engine;
use NameSweep\Util\Logger;

/**
 * Suggest orchestrator — the LLM loop.
 *
 * Iteration:
 *   1. Send description (+ on iteration > 1: the rejected names) to Bifrost's
 *      'suggest' job (Local Ollama -> OpenRouter fallback is configured in
 *      Bifrost, not here — see AI routes redesign, root CLAUDE.md).
 *   2. Receive N candidate names (strict JSON parse; a step that returns
 *      unparseable JSON is a failed attempt, and Bifrost's own job steps
 *      fall back to the next route — this app no longer picks backends).
 *   3. Run Engine::check for each candidate (modules filter = domain + social).
 *   4. Keep only names that are `available` for ALL requested TLDs and ALL
 *      social platforms.
 *   5. Loop back with the rejected list as context until kept >= target.
 *   6. Hard cap: 5 iterations. If the target isn't met, return what was kept.
 *
 * Every generated name is persisted (kept or rejected) per session.
 */
final class SuggestEngine
{
    private const DEFAULT_MODULES = ['domain', 'social'];

    public function __construct(
        private readonly Engine $engine,
        private readonly SuggestRepository $repo,
        private readonly Logger $logger,
        private readonly array $config
    ) {
    }

    /**
     * @param string[] $tlds empty = config default
     * @param string[] $modules empty = ['domain','social']
     * @return array{session_id:string, iterations:int, kept:list<array{name:string, checks:array}>, rejected?:list<array{name:string, iteration:int}>}
     * @throws \RuntimeException when the LLM produced no parseable names at all
     */
    public function suggest(string $description, int $count = 10, array $tlds = [], array $modules = [], bool $includeRejects = true): array
    {
        $count = max(1, min(50, $count));
        $modules = $modules !== [] ? $modules : self::DEFAULT_MODULES;
        $maxIterations = max(1, (int) ($this->config['suggest']['max_iterations'] ?? 5));
        $temperature   = (float) ($this->config['suggest']['temperature'] ?? 0.7);

        $sessionId = $this->uuid();
        $kept      = [];   // name => checks array
        $rejected  = [];   // name => iteration
        $reports   = [];   // every candidate, including rejected, with full checks
        $reasons   = [];   // name => deterministic rejection reason
        $model     = null;
        $created   = false;
        $iterations = 0;

        for ($iteration = 1; $iteration <= $maxIterations; $iteration++) {
            $iterations = $iteration;
            if (count($kept) >= $count) {
                break;
            }

            try {
                [$modelLabel, $names] = $this->generateNames($description, $count - count($kept), $tlds, array_keys($rejected), $temperature);
            } catch (\Throwable $e) {
                // One unusable LLM response shouldn't abort the whole run —
                // the next iteration retries with the rejected names as context.
                $this->logger->warning("Suggest: iteration {$iteration} produced no usable names", ['error' => $e->getMessage()], 'suggest');
                continue;
            }
            $model = $modelLabel;

            if (!$created) {
                $this->repo->createSession($sessionId, $description, $model, $tlds, $modules);
                $created = true;
            }

            $seen = array_merge(array_keys($kept), array_keys($rejected));
            foreach ($names as $raw) {
                $name = strtolower(trim((string) $raw));
                if (!preg_match('/^[a-z0-9-]{1,63}$/', $name)) {
                    $this->logger->debug("Suggest: skipping invalid candidate", ['name' => $name], 'suggest');
                    continue;
                }
                if (in_array($name, $seen, true)) {
                    continue;
                }
                $seen[] = $name;

                $checks = $this->engine->checkAsArray(new CheckRequest($name, $tlds, $modules, [], true));
                $reason = $this->clearanceReason($checks, $modules);
                $ok     = $reason === null;
                $reports[$name] = $checks;
                if ($reason !== null) {
                    $reasons[$name] = $reason;
                }

                $this->repo->addCandidate($sessionId, $name, $iteration, $ok);
                if ($ok) {
                    $kept[$name] = $checks;
                } else {
                    $rejected[$name] = $iteration;
                }
            }
        }

        if (!$created) {
            throw new \RuntimeException('The LLM produced no valid candidate names');
        }

        $this->repo->finalizeSession($sessionId);

        $keptOut = [];
        foreach ($kept as $name => $checks) {
            $keptOut[] = ['name' => $name, 'checks' => $checks];
        }

        $out = [
            'session_id' => $sessionId,
            'iterations' => $iterations,
            'kept'       => $keptOut,
            'candidates' => [],
        ];
        foreach ($reports as $name => $checks) {
            $out['candidates'][] = [
                'name' => $name,
                'checks' => $checks,
                'cleared' => isset($kept[$name]),
                'rejection_reason' => $reasons[$name] ?? null,
                'iteration' => $rejected[$name] ?? null,
            ];
        }
        if ($includeRejects) {
            $out['rejected'] = [];
            foreach ($rejected as $name => $iteration) {
                $out['rejected'][] = [
                    'name' => $name,
                    'iteration' => $iteration,
                    'checks' => $reports[$name] ?? null,
                    'rejection_reason' => $reasons[$name] ?? 'did not clear all requested modules',
                ];
            }
        }
        return $out;
    }

    /**
     * @param string[] $tlds
     * @param string[] $rejected
     * @return array{0:string, 1:list<string>} model alias + candidate names
     */
    private function generateNames(string $description, int $count, array $tlds, array $rejected, float $temperature): array
    {
        $prompts = $this->config['prompts'] ?? [];

        $user = str_replace(
            ['{description}', '{tlds}', '{rejected}', '{count}'],
            [
                $description,
                $tlds !== [] ? implode(', ', $tlds) : 'config default',
                $rejected !== [] ? implode(', ', $rejected) : 'none',
                (string) $count,
            ],
            (string) ($prompts['suggest_user'] ?? '')
        );

        $res = \Bifrost::ai(
            'suggest',
            ['system' => (string) ($prompts['suggest_system'] ?? ''), 'prompt' => $user],
            ['json' => true, 'temperature' => $temperature]
        );

        // The 'suggest' job's prompt asks for {"names": [...]}; fall back to
        // the raw decoded value for a model that returns a bare array.
        $data = $res['json'];
        if (is_array($data) && isset($data['names']) && is_array($data['names'])) {
            $data = $data['names'];
        }
        if (!is_array($data)) {
            throw new \RuntimeException('LLM returned no names');
        }

        $names = [];
        foreach ($data as $n) {
            if (is_string($n) && trim($n) !== '') {
                $names[] = trim($n);
                continue;
            }
            // Small models often return objects ({"name": "…"}) or a
            // name => note map instead of a plain string list.
            if (is_array($n)) {
                foreach (['name', 'candidate', 'domain', 'suggestion', 'value'] as $k) {
                    if (isset($n[$k]) && is_string($n[$k]) && trim($n[$k]) !== '') {
                        $names[] = trim($n[$k]);
                        break;
                    }
                }
            }
        }
        if ($names === []) {
            // Name => note map shape: use the keys as the names.
            $keys = [];
            foreach ($data as $k => $v) {
                if (is_string($k) && preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $k)) {
                    $keys[] = $k;
                }
            }
            $names = $keys;
        }
        $names = array_values(array_unique($names));
        if ($names === []) {
            throw new \RuntimeException('LLM returned no names');
        }
        return [$res['route'] . ':' . $res['model'], $names];
    }

    /**
     * @param array<string, mixed> $checks the Engine::checkAsArray output
     * @param string[] $modules
     */
    private function clearanceReason(array $checks, array $modules): ?string
    {
        foreach ($modules as $module) {
            $rows = $checks['results'][$module] ?? [];
            if ($rows === []) {
                return $module . ' returned no result';
            }
            foreach ($rows as $row) {
                $status = (string) ($row['status'] ?? 'uncertain');
                // Social handles: the four providers currently return `uncertain`
                // for every fresh handle (Instagram/TikTok serve a login/WAF wall
                // with no positive marker, X is intermittently wall-gated). The
                // spec says `uncertain` over guessing, but requiring strict
                // `available` for social makes `/suggest` with the default
                // `domain+social` filter keep nothing (every suggest session in
                // the DB with `["domain","social"]` has kept=0). Treat social
                // `uncertain` as not blocking — only `registered`/`for_sale`/
                // `parked` are real negatives — while domain/marketplace/
                // trademark stay strict (`available` only).
                if ($module === 'social') {
                    if ($status === 'registered' || $status === 'for_sale' || $status === 'parked') {
                        $target = '@' . ($row['platform'] ?? $module);
                        return $target . ' is ' . $status;
                    }
                    continue;
                }
                if ($status !== 'available') {
                    $target = ($row['tld'] ?? '') !== '' ? '.' . $row['tld'] : '@' . ($row['platform'] ?? $module);
                    return $target . ' is ' . $status;
                }
            }
        }
        return null;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}