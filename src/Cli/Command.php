<?php

declare(strict_types=1);

namespace NameSweep\Cli;

use NameSweep\CheckRequest;
use NameSweep\Util\HttpClient;
use NameSweep\Util\ReportAnalyzer;

/**
 * CLI command parser + dispatcher. No interactive prompts — everything is a
 * flag so the CLI stays scriptable.
 *
 * Exit codes: 0 ok · 1 any uncertain result · 2 bad usage · 3 internal error.
 */
final class Command
{
    public function __construct(private readonly array $app)
    {
    }

    public function run(array $argv): int
    {
        $args    = array_slice($argv, 1);
        $command = $args[0] ?? 'help';
        $rest    = array_slice($args, 1);

        // Flag-less commands reject any flag up front (usage error, exit 2).
        if (in_array($command, ['key:create', 'key:list', 'key:revoke', 'health', 'rdap:refresh'], true)) {
            foreach ($rest as $arg) {
                if (str_starts_with($arg, '--')) {
                    return $this->fail("Unknown flag: {$arg}\nRun 'php bin/namesweep help' for usage.\n", 2);
                }
            }
        }

        return match ($command) {
            'help', '-h', '--help' => $this->help(),
            'check'                => $this->check($rest),
            'suggest'              => $this->suggest($rest),
            'key:create'           => $this->keyCreate($rest),
            'key:list'             => $this->keyList(),
            'key:revoke'           => $this->keyRevoke($rest),
            'cache:clear'          => $this->cacheClear($rest),
            'health'               => $this->health(),
            'rdap:refresh'         => $this->rdapRefresh(),
            default                => $this->fail("Unknown command: {$command}\nRun 'php bin/namesweep help' for usage.\n", 2),
        };
    }

    // ── commands ────────────────────────────────────────────────────────────

    private function check(array $args): int
    {
        $parsed = $this->parseArgs($args, ['tlds', 'modules', 'markets', 'no-trademark', 'no-cache', 'json', 'csv', 'names-file', 'alternatives', 'budget', 'currency']);
        $tlds   = $this->csv($parsed['flags']['tlds'] ?? null);
        $modules = $this->csv($parsed['flags']['modules'] ?? null);
        if (array_key_exists('no-trademark', $parsed['flags'])) {
            $defaults = $this->app['config']['default_modules'] ?? ['domain', 'marketplace', 'social', 'trademark'];
            $modules  = $modules === []
                ? array_values(array_diff($defaults, ['trademark']))
                : array_values(array_diff($modules, ['trademark']));
        }
        $markets = $this->csv($parsed['flags']['markets'] ?? null);
        $useCache = !array_key_exists('no-cache', $parsed['flags']);
        $json    = array_key_exists('json', $parsed['flags']);
        $csv     = array_key_exists('csv', $parsed['flags']);

        // M12 — opt-in alternatives + a budget that ranks but never filters.
        $alternatives = array_key_exists('alternatives', $parsed['flags']);
        $budget  = isset($parsed['flags']['budget']) && is_numeric($parsed['flags']['budget'])
            ? (float) $parsed['flags']['budget']
            : null;
        $currency = strtoupper((string) ($parsed['flags']['currency'] ?? 'USD'));

        try {
            $names = $this->batchNames($parsed['positional'], $parsed['flags']['names-file'] ?? null);
        } catch (\Throwable $e) {
            return $this->fail("Error: {$e->getMessage()}\n", 2);
        }
        if ($names === []) {
            return $this->fail("Usage: php bin/namesweep check <name[,name2,...]> [--names-file=path|-] [--tlds=com,org,com.do] [--modules=domain,social] [--markets=dr,us] [--no-trademark] [--no-cache] [--json|--csv] [--alternatives] [--budget=50] [--currency=USD]\n", 2);
        }
        if ($this->app['engine'] === null) {
            return $this->fail("Error: engine unavailable — DB: " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }

        $reports = [];
        try {
            foreach ($names as $name) {
                $req = new CheckRequest($name, $tlds, $modules, $markets, $useCache, null, $alternatives, $budget, $currency);
                $data = $this->app['engine']->checkAsArray($req);
                $data['verdict'] = ReportAnalyzer::verdict($data['results'], $budget, $currency);
                if (count($names) === 1 && $this->app['summary_writer'] !== null) {
                    $data['summary'] = $this->app['summary_writer']->forReport($data);
                }
                $reports[$name] = $data;
            }
        } catch (\Throwable $e) {
            return $this->fail("Error: {$e->getMessage()}\n", 3);
        }

        if ($json) {
            if (count($reports) === 1) {
                echo json_encode(reset($reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            } else {
                echo json_encode(['names' => array_keys($reports), 'checked_at' => date('c'), 'results' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            }
        } elseif ($csv) {
            $this->renderCsv($reports);
        } elseif (count($reports) === 1) {
            $this->renderTable(reset($reports));
        } else {
            $this->renderMatrix($reports);
        }

        foreach ($reports as $data) {
            foreach ($data['results'] as $rows) {
                foreach ($rows as $r) {
                    if (($r['status'] ?? '') === 'uncertain') {
                        return 1;
                    }
                }
            }
        }
        return 0;
    }

    private function suggest(array $args): int
    {
        $parsed = $this->parseArgs($args, ['count', 'tlds', 'modules', 'json']);
        $description = $parsed['positional'][0] ?? null;
        if ($description === null || $description === '') {
            return $this->fail("Usage: php bin/namesweep suggest \"<description>\" [--count=10] [--tlds=com,org,com.do] [--modules=domain,social] [--json]\n", 2);
        }
        if ($this->app['suggest_engine'] === null) {
            return $this->fail("Error: suggest engine unavailable — DB: " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }

        $count   = (int) ($parsed['flags']['count'] ?? 10);
        $tlds    = $this->csv($parsed['flags']['tlds'] ?? null);
        $modules = $this->csv($parsed['flags']['modules'] ?? null);
        $json    = array_key_exists('json', $parsed['flags']);

        try {
            $result = $this->app['suggest_engine']->suggest($description, $count, $tlds, $modules, true);
        } catch (\Throwable $e) {
            return $this->fail("Error: {$e->getMessage()}\n", 3);
        }

        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "Session: {$result['session_id']}\n";
            echo "Iterations: {$result['iterations']}\n\n";
            echo "Kept (available):\n";
            foreach ($result['kept'] as $k) {
                echo "  {$k['name']}\n";
            }
            if ($result['kept'] === []) {
                echo "  (none — increase iterations or widen the tlds)\n";
            }
            if (isset($result['rejected'])) {
                echo "\nRejected (taken/unclear):\n";
                foreach ($result['rejected'] as $r) {
                    echo "  {$r['name']} (iteration {$r['iteration']})\n";
                }
            }
        }
        return 0;
    }

    private function keyCreate(array $args): int
    {
        $name = $args[0] ?? null;
        if ($name === null || $name === '' || strlen($name) > 64) {
            return $this->fail("Usage: php bin/namesweep key:create <name>\n", 2);
        }
        if ($this->app['api_keys'] === null) {
            return $this->fail("Error: DB unavailable — " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }

        $plaintext = 'ns_' . bin2hex(random_bytes(16));
        $row = $this->app['api_keys']->create($name, $plaintext);

        echo "API key created:\n";
        echo "  name:   {$row['name']}\n";
        echo "  id:     {$row['id']}\n";
        echo "  prefix: {$row['prefix']}\n";
        echo "  key:    {$row['key']}\n";
        echo "\nStore this key now — it will not be shown again.\n";
        return 0;
    }

    private function keyList(): int
    {
        if ($this->app['api_keys'] === null) {
            return $this->fail("Error: DB unavailable — " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }
        $rows = $this->app['api_keys']->all();
        if ($rows === []) {
            echo "No API keys.\n";
            return 0;
        }
        printf("%-5s %-20s %-12s %-20s %-20s %s\n", 'ID', 'NAME', 'PREFIX', 'CREATED', 'LAST USED', 'STATUS');
        foreach ($rows as $r) {
            $status = $r['revoked_at'] !== null ? 'revoked' : 'active';
            printf(
                "%-5d %-20s %-12s %-20s %-20s %s\n",
                (int) $r['id'],
                mb_substr($r['name'], 0, 20),
                $r['key_prefix'],
                $r['created_at'],
                $r['last_used_at'] ?? '-',
                $status
            );
        }
        return 0;
    }

    private function keyRevoke(array $args): int
    {
        $id = (int) ($args[0] ?? 0);
        if ($id <= 0) {
            return $this->fail("Usage: php bin/namesweep key:revoke <id>\n", 2);
        }
        if ($this->app['api_keys'] === null) {
            return $this->fail("Error: DB unavailable — " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }
        $ok = $this->app['api_keys']->revoke($id);
        echo $ok ? "Key {$id} revoked.\n" : "Key {$id} not found or already revoked.\n";
        return $ok ? 0 : 2;
    }

    private function cacheClear(array $args): int
    {
        $parsed = $this->parseArgs($args, ['module']);
        $module = $parsed['flags']['module'] ?? null;
        if ($this->app['checks'] === null) {
            return $this->fail("Error: DB unavailable — " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }
        $count = $module !== null
            ? $this->app['checks']->clearModule($module)
            : $this->app['checks']->clearAll();
        echo "Cleared {$count} cached check(s)" . ($module !== null ? " for module '{$module}'" : '') . ".\n";
        return 0;
    }

    private function health(): int
    {
        $db = $this->app['db'] !== null ? 'ok' : 'down';
        $ai = $this->aiStatus();

        $data = ['status' => 'ok', 'db' => $db] + $ai;
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        return $db === 'ok' ? 0 : 3;
    }

    private function rdapRefresh(): int
    {
        $config = $this->app['config'];
        $cache  = $config['rdap']['bootstrap_cache'];

        if (is_file($cache)) {
            unlink($cache);
        }

        try {
            $http = new HttpClient($config['http']['timeout'], $config['http']['user_agent']);
            $bootstrap = new \NameSweep\Providers\Rdap\RdapBootstrap(
                $http,
                $cache,
                $config['rdap']['bootstrap_url'],
                $this->app['logger']
            );
            $bootstrap->baseUrlFor('com'); // triggers a load/fetch
        } catch (\Throwable $e) {
            return $this->fail("Error refreshing RDAP bootstrap: {$e->getMessage()}\n", 3);
        }

        echo "RDAP bootstrap refreshed.\n";
        return 0;
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function help(): int
    {
        echo <<<TXT
NameSweep CLI

Usage: php bin/namesweep <command> [args]

Commands:
  check        <name[,name2,...]> [--names-file=path|-] [--tlds=com,org,com.do] [--modules=domain,social] [--markets=dr,us] [--no-trademark] [--no-cache] [--json|--csv] [--alternatives] [--budget=50] [--currency=USD]
  suggest      "<description>" [--count=10] [--tlds=com,org,com.do] [--modules=domain,social] [--json]
  key:create   <name>          Create an API key (prints plaintext once)
  key:list                      List API keys
  key:revoke   <id>             Revoke an API key
  cache:clear  [--module=domain]  Clear cached checks
  health                        Check DB / Bifrost AI routes
  rdap:refresh                  Re-fetch the IANA RDAP bootstrap
  help                          Show this help

Exit codes: 0 ok · 1 any uncertain result · 2 bad usage · 3 internal error

TXT;
        return 0;
    }

    private function fail(string $message, int $code): int
    {
        fwrite(STDERR, $message);
        return $code;
    }

    /** @param string[] $positional */
    private function batchNames(array $positional, ?string $file): array
    {
        $raw = implode(',', $positional);
        if ($file !== null) {
            $raw = $file === '-' ? (string) file_get_contents('php://stdin') : (string) @file_get_contents($file);
            if ($raw === '') {
                throw new \RuntimeException($file === '-' ? 'stdin contained no names' : "Could not read names file: {$file}");
            }
        }
        $names = preg_split('/[\\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($names as $name) {
            $name = strtolower(trim($name));
            if (!preg_match('/^[a-z0-9-]{1,63}$/', $name)) {
                throw new \RuntimeException("name must match [a-z0-9-]{1,63}: {$name}");
            }
            if (!in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    private function renderMatrix(array $reports): void
    {
        $suffixes = [];
        foreach ($reports as $data) {
            foreach (($data['results']['domain'] ?? []) as $row) {
                if (($row['tld'] ?? '') !== '' && !in_array($row['tld'], $suffixes, true)) {
                    $suffixes[] = $row['tld'];
                }
            }
        }
        echo 'name';
        foreach ($suffixes as $tld) {
            echo "\\t." . $tld;
        }
        echo "\\n";
        foreach ($reports as $name => $data) {
            $byTld = [];
            foreach (($data['results']['domain'] ?? []) as $row) {
                $byTld[$row['tld']] = $row;
            }
            echo $name;
            foreach ($suffixes as $tld) {
                $row = $byTld[$tld] ?? [];
                $cell = (string) ($row['status'] ?? 'missing');
                if (($row['within_budget'] ?? null) === true) {
                    $cell .= ' ✓';
                }
                $price = $row['sale_price'] ?? $row['pricing']['registration'] ?? null;
                if (is_numeric($price)) {
                    $cell .= ' ' . number_format((float) $price, 2);
                }
                echo "\\t" . $cell;
            }
            echo "\\n";
        }
    }

    private function renderCsv(array $reports): void
    {
        $out = fopen('php://output', 'wb');
        fputcsv($out, ['name', 'module', 'tld', 'platform', 'status', 'requested', 'within_budget', 'registration_price', 'sale_price', 'currency', 'source', 'detail']);
        foreach ($reports as $name => $data) {
            foreach ($data['results'] as $module => $rows) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $name,
                        $module,
                        $row['tld'] ?? '',
                        $row['platform'] ?? '',
                        $row['status'] ?? '',
                        ($row['requested'] ?? true) ? 'true' : 'false',
                        $row['within_budget'] ?? '',
                        $row['pricing']['registration'] ?? '',
                        $row['sale_price'] ?? '',
                        $row['pricing']['currency'] ?? '',
                        $row['source'] ?? '',
                        $row['detail'] ?? '',
                    ]);
                }
            }
        }
        fclose($out);
    }

    private function renderTable(array $data): void
    {
        echo "Name: {$data['name']}\n";
        if (($data['summary'] ?? null) !== null) {
            echo "Summary: {$data['summary']}\n";
        }
        foreach ($data['results'] as $module => $rows) {
            echo "\n{$module}:\n";
            foreach ($rows as $r) {
                $line = sprintf("  .%-8s %-12s %-8s %-10s", $r['tld'], $r['status'], $r['platform'], $this->priceCell($r));
                if (($r['within_budget'] ?? null) === true) {
                    $line .= '  ✓ within budget';
                }
                if (($r['requested'] ?? true) === false) {
                    $line .= '  (alternative)';
                }
                if ($r['detail'] !== null) {
                    $line .= '  — ' . mb_substr($r['detail'], 0, 70);
                }
                if ($r['from_cache']) {
                    $line .= '  (cached)';
                }
                echo $line . "\n";
            }
        }
    }

    /**
     * One compact money cell: the aftermarket asking price when a seller has
     * named one, otherwise the yearly registration price for the suffix.
     */
    private function priceCell(array $r): string
    {
        if (($r['sale_price'] ?? $r['price'] ?? null) !== null) {
            return 'ask ' . $this->money((float) ($r['sale_price'] ?? $r['price']), $r['pricing']['currency'] ?? 'USD');
        }

        $reg = $r['pricing']['registration'] ?? null;
        if ($reg !== null) {
            return $this->money((float) $reg, $r['pricing']['currency'] ?? 'USD') . '/yr';
        }

        return ($r['tld'] ?? '') !== '' && ($r['pricing'] ?? null) !== null ? 'no price' : '';
    }

    private function money(float $amount, string $currency): string
    {
        $prefix = $currency === 'USD' ? '$' : $currency . ' ';
        return $prefix . number_format($amount, 2);
    }

    /**
     * @param string[] $allowedFlags flags this command accepts (empty = none)
     */
    private function parseArgs(array $args, array $allowedFlags = []): array
    {
        $positional = [];
        $flags = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                $k = str_contains($body, '=') ? explode('=', $body, 2)[0] : $body;
                if ($allowedFlags !== [] && !in_array($k, $allowedFlags, true)) {
                    $this->fail("Unknown flag: --{$k}\nRun 'php bin/namesweep help' for usage.\n", 2);
                    return ['positional' => [], 'flags' => []];
                }
                if (str_contains($body, '=')) {
                    [, $v] = explode('=', $body, 2);
                    $flags[$k] = $v;
                } else {
                    $flags[$body] = true;
                }
            } else {
                $positional[] = $arg;
            }
        }
        return ['positional' => $positional, 'flags' => $flags];
    }

    private function csv(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($v) => $v !== ''));
    }

    /**
     * AI status via Bifrost (AI routes redesign) — this app no longer knows
     * about Ollama/OpenRouter directly, only whether Bifrost has granted it
     * any routes to call.
     *
     * @return array{ai:string,ai_routes:int}
     */
    private function aiStatus(): array
    {
        if (!class_exists('Bifrost', false)) {
            return ['ai' => 'unavailable', 'ai_routes' => 0];
        }
        try {
            $routes = \Bifrost::aiRoutes();
            return ['ai' => $routes !== [] ? 'ok' : 'no routes granted', 'ai_routes' => count($routes)];
        } catch (\Throwable) {
            return ['ai' => 'down', 'ai_routes' => 0];
        }
    }
}
