<?php

declare(strict_types=1);

namespace NameSweep\Cli;

use NameSweep\CheckRequest;
use NameSweep\Util\HttpClient;

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
        $parsed = $this->parseArgs($args);
        $name   = $parsed['positional'][0] ?? null;
        $tlds   = $this->csv($parsed['flags']['tlds'] ?? null);
        $modules = $this->csv($parsed['flags']['modules'] ?? null);
        $markets = $this->csv($parsed['flags']['markets'] ?? null);
        $useCache = !array_key_exists('no-cache', $parsed['flags']);
        $json    = array_key_exists('json', $parsed['flags']);

        if ($name === null || $name === '') {
            return $this->fail("Usage: php bin/namesweep check <name> [--tlds=com,net,io] [--modules=domain,social] [--markets=dr,us] [--no-cache] [--json]\n", 2);
        }
        if (!preg_match('/^[a-z0-9-]{1,63}$/i', $name)) {
            return $this->fail("Error: name must match [a-z0-9-]{1,63}\n", 2);
        }
        if ($this->app['engine'] === null) {
            return $this->fail("Error: engine unavailable — DB: " . ($this->app['db_error'] ?? 'unknown') . "\n", 3);
        }

        $req = new CheckRequest($name, $tlds, $modules, $markets, $useCache);

        try {
            $data = $this->app['engine']->checkAsArray($req);
        } catch (\Throwable $e) {
            return $this->fail("Error: {$e->getMessage()}\n", 3);
        }

        if ($json) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            $this->renderTable($data);
        }

        foreach ($data['results'] as $rows) {
            foreach ($rows as $r) {
                if ($r['status'] === 'uncertain') {
                    return 1;
                }
            }
        }
        return 0;
    }

    private function suggest(array $args): int
    {
        return $this->fail("Suggest mode is not built yet (milestone M5).\n", 2);
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
        $parsed = $this->parseArgs($args);
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
        $db    = $this->app['db'] !== null ? 'ok' : 'down';
        $ollama = $this->ollamaStatus();
        $openrouter = ($this->app['config']['llm']['openrouter_api_key'] ?? '') !== '' ? 'ok' : 'down';

        $data = ['status' => 'ok', 'db' => $db, 'ollama' => $ollama, 'openrouter' => $openrouter];
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
  check        <name> [--tlds=com,net,io] [--modules=domain,social] [--markets=dr,us] [--no-cache] [--json]
  suggest      <description> [--count=10] [--tlds=...] [--modules=...] [--json]   (coming in M5)
  key:create   <name>          Create an API key (prints plaintext once)
  key:list                      List API keys
  key:revoke   <id>             Revoke an API key
  cache:clear  [--module=domain]  Clear cached checks
  health                        Check DB / Ollama / OpenRouter
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

    private function renderTable(array $data): void
    {
        echo "Name: {$data['name']}\n";
        foreach ($data['results'] as $module => $rows) {
            echo "\n{$module}:\n";
            foreach ($rows as $r) {
                $line = sprintf("  .%-6s %-12s %-8s", $r['tld'], $r['status'], $r['platform']);
                if ($r['detail'] !== null) {
                    $line .= '  — ' . mb_substr($r['detail'], 0, 80);
                }
                if ($r['from_cache']) {
                    $line .= '  (cached)';
                }
                echo $line . "\n";
            }
        }
    }

    private function parseArgs(array $args): array
    {
        $positional = [];
        $flags = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $body = substr($arg, 2);
                if (str_contains($body, '=')) {
                    [$k, $v] = explode('=', $body, 2);
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

    private function ollamaStatus(): string
    {
        $base = rtrim($this->app['config']['llm']['ollama_base_url'] ?? 'http://127.0.0.1:11434', '/');
        try {
            $res = (new HttpClient(3, 'NameSweep/1.0'))->get($base . '/api/tags');
            return $res['status'] === 200 ? 'ok' : 'down';
        } catch (\Throwable) {
            return 'down';
        }
    }
}
