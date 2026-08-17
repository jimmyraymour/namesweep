<?php

declare(strict_types=1);

namespace NameSweep;

use NameSweep\Modules\ModuleInterface;
use NameSweep\Storage\CheckRepository;
use NameSweep\Util\Logger;

/**
 * Core orchestrator — the only class the UI, CLI, and API call.
 *
 * - Runs each requested module in order.
 * - Never stops on failure: a throwing module becomes `uncertain`.
 * - Is the only place that writes to the `checks` table.
 */
final class Engine
{
    /**
     * @param array<string, ModuleInterface> $modules keyed by module name
     * @param array<string, int> $ttl per-module TTL in seconds
     */
    public function __construct(
        private readonly array $modules,
        private readonly CheckRepository $repo,
        private readonly Logger $logger,
        private readonly array $ttl
    ) {
    }

    /**
     * @return array<string, CheckResult[]> keyed by module name
     */
    public function check(CheckRequest $req): array
    {
        $results = [];

        foreach ($this->modules as $name => $module) {
            if ($req->modules !== [] && !in_array($name, $req->modules, true)) {
                continue;
            }

            try {
                $moduleResults = $module->check($req);
            } catch (\Throwable $e) {
                $this->logger->warning("Module {$name} threw", ['error' => $e->getMessage()], $name);
                $moduleResults = [
                    new CheckResult(
                        $req->name,
                        '',
                        'uncertain',
                        'engine',
                        $name,
                        '',
                        $e::class . ': ' . $e->getMessage(),
                        null,
                        null,
                        date('c'),
                        false
                    ),
                ];
            }

            // Engine is the only writer to the checks table.
            $ttl = $this->ttl[$name] ?? 86400;
            foreach ($moduleResults as $result) {
                if (!$result->fromCache) {
                    try {
                        $this->repo->save($result, $ttl);
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to cache check result', ['error' => $e->getMessage()], $name);
                    }
                }
            }

            $results[$name] = $moduleResults;
        }

        return $results;
    }

    /**
     * Convenience for the API: returns the results as a JSON-ready structure.
     *
     * @return array{name:string, checked_at:string, from_cache:bool, summary:null, results:array<string, array<int, array<string, mixed>>>}
     */
    public function checkAsArray(CheckRequest $req): array
    {
        $results = $this->check($req);

        $out = [];
        $allCached = true;
        $any = false;
        foreach ($results as $module => $rows) {
            $out[$module] = array_map(static fn (CheckResult $r) => $r->toArray(), $rows);
            foreach ($rows as $r) {
                $any = true;
                if (!$r->fromCache) {
                    $allCached = false;
                }
            }
        }

        return [
            'name'       => $req->name,
            'checked_at' => date('c'),
            'from_cache' => $any && $allCached,
            'summary'    => null, // SummaryWriter lands in M5
            'results'    => $out,
        ];
    }
}
