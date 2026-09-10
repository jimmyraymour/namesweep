<?php

declare(strict_types=1);

namespace NameSweep;

use NameSweep\Modules\DomainResultsAware;
use NameSweep\Modules\ModuleInterface;
use NameSweep\Providers\SuffixResolver;
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
     * @param string[] $defaultTlds suffixes checked when the request names none
     * @param string[] $alternativeTlds extra suffixes when `alternatives` is on
     */
    public function __construct(
        private readonly array $modules,
        private readonly CheckRepository $repo,
        private readonly Logger $logger,
        private readonly array $ttl,
        private readonly array $defaultTlds = [],
        private readonly array $alternativeTlds = []
    ) {
    }

    /**
     * @return array<string, CheckResult[]> keyed by module name
     */
    public function check(CheckRequest $req): array
    {
        $results = [];
        $domainResults = null;

        // M12 `alternatives`: expand the suffix list before any module runs, so
        // every module (domain, marketplace) sees the same merged set. Requested
        // suffixes keep their order and come first; the alternative set adds only
        // suffixes nobody asked for.
        if ($req->alternatives && $this->alternativeTlds !== []) {
            $merged = $this->expandTlds($req->tlds);
            if ($merged !== $req->tlds) {
                $req = new CheckRequest(
                    $req->name,
                    $merged,
                    $req->modules,
                    $req->markets,
                    $req->useCache,
                    $req->platform,
                    $req->alternatives,
                    $req->budget,
                    $req->currency
                );
            }
        }

        // Modules are singletons that outlive a single request. Clear any
        // injected state before doing anything, so a previous check's domain
        // results can never be mistaken for this one's.
        foreach ($this->modules as $module) {
            if ($module instanceof DomainResultsAware) {
                $module->setDomainResults(null);
            }
        }

        foreach ($this->modules as $name => $module) {
            if ($req->modules !== [] && !in_array($name, $req->modules, true)) {
                continue;
            }

            // Hand the domain findings to modules that need them, so they do
            // not re-run the domain check. Modules run in config order, with
            // 'domain' first, so this is populated by the time it is needed.
            if ($domainResults !== null && $module instanceof DomainResultsAware) {
                $module->setDomainResults($domainResults);
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
                // Never cache `uncertain`: it records our failure to find out,
                // not the domain's state, and would be replayed for a full TTL.
                if (!$result->fromCache && $result->status !== 'uncertain') {
                    try {
                        $this->repo->save($result, $ttl);
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to cache check result', ['error' => $e->getMessage()], $name);
                    }
                }
            }

            if ($name === 'domain') {
                $domainResults = $moduleResults;
            }

            $results[$name] = $moduleResults;
        }

        return $results;
    }

    /**
     * Persists a row produced outside check() — used by the trademark
     * manual-verify endpoint (POST /api/v1/check/trademark/manual) so the
     * "Engine is the only writer to the checks table" rule holds.
     */
    public function saveManual(CheckResult $result): void
    {
        $ttl = $this->ttl[$result->module] ?? 86400;
        $this->repo->save($result, $ttl);
    }

    /**
     * Convenience for the API: returns the results as a JSON-ready structure.
     *
     * Every row carries two M12 metadata fields:
     *   `requested`     — true when the suffix was in the caller's own tlds
     *                     list (non-suffix rows like social/trademark are
     *                     always requested — alternatives change suffixes, not
     *                     whether the name itself was looked up).
     *   `within_budget` — true|false when a price is known in the request's
     *                     currency, null otherwise. A display/ranking input
     *                     only; the complete result set is always returned.
     *
     * @return array{name:string, checked_at:string, from_cache:bool, summary:null, results:array<string, array<int, array<string, mixed>>>}
     */
    public function checkAsArray(CheckRequest $req): array
    {
        // Capture the caller's requested set before `check()` expands it with
        // alternatives. Empty means the configured defaults were requested.
        $requestedTlds = $req->tlds !== [] ? $req->tlds : $this->defaultTlds;
        $results = $this->check($req);
        $out = [];
        $allCached = true;
        $any = false;
        foreach ($results as $module => $rows) {
            $out[$module] = [];
            foreach ($rows as $r) {
                $arr = $r->toArray();
                $arr['requested']     = $this->wasRequested($arr['tld'] ?? '', $requestedTlds);
                $arr['within_budget'] = $this->withinBudget($arr, $req->budget, $req->currency);
                $out[$module][] = $arr;
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
            'summary'    => null, // SummaryWriter fills this in the controller/CLI layer
            'results'    => $out,
        ];
    }

    /**
     * Requested suffixes first (config order preserved — or the config defaults
     * when none were given), then the alternative set minus what was already
     * asked. Deduplicated.
     *
     * @param string[] $requested
     * @return string[]
     */
    private function expandTlds(array $requested): array
    {
        $base   = $requested !== [] ? $requested : $this->defaultTlds;
        $merged = $base;
        $seen   = array_fill_keys($merged, true);
        foreach ($this->alternativeTlds as $suffix) {
            $suffix = SuffixResolver::normalize((string) $suffix);
            if ($suffix === '' || !SuffixResolver::isValid($suffix) || isset($seen[$suffix])) {
                continue;
            }
            $seen[$suffix] = true;
            $merged[] = $suffix;
        }
        return $merged;
    }

    private function wasRequested(string $tld, array $requestedTlds): bool
    {
        return $tld === '' || in_array($tld, $requestedTlds, true);
    }

    /**
     * Budget is a ranking input, never a filter (explicitly asserted in
     * tests/smoke.php). Null means "we can't say": no comparator was given,
     * the row has no price, or the row's price is in a different currency —
     * we never do silent cross-currency math.
     *
     * @param array<string, mixed> $row a serialized CheckResult
     */
    private function withinBudget(array $row, ?float $budget, string $currency): ?bool
    {
        if ($budget === null) {
            return null;
        }

        $price = $row['sale_price'] ?? $row['price'] ?? null;
        if ($price === null) {
            $price = $row['pricing']['registration'] ?? null;
        }
        if ($price === null) {
            return null;
        }

        $rowCurrency = (string) (is_array($row['pricing'] ?? null) ? ($row['pricing']['currency'] ?? 'USD') : 'USD');
        if (strcasecmp($rowCurrency, $currency) !== 0) {
            return null;
        }

        return (float) $price <= $budget;
    }
}
