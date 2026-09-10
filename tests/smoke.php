<?php

/**
 * NameSweep acceptance smoke test — run manually:
 *
 *   php tests/smoke.php
 *
 * Covers the §15 acceptance checklist items that can be verified without a
 * browser or a production deployment (network-dependent lookups run live but
 * degrade to SKIP on transport errors rather than failing the suite; the
 * LLM section is skipped when Ollama is unreachable). Exits 0 = all green,
 * 1 = one or more FAIL, 2 = run aborted.
 *
 * The API/rate-limit/UI sections spawn the built-in dev server on a random
 * port with RATE_LIMIT_PER_MINUTE lowered so the 61st-request check stays
 * fast. Requires a working DB + the web router (public/router.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NameSweep\Util\Env;

$root = dirname(__DIR__);
Env::load($root . '/.env');

$pass = 0;
$fail = 0;
$skip = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        $failures[] = $label;
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    }
}

function skip(string $label, string $reason): void
{
    global $skip;
    $skip++;
    echo "  SKIP  {$label} ({$reason})" . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . "== {$title} ==" . PHP_EOL;
}

/** @return array{status:int, body:string} */
function httpGet(string $url, array $headers = [], int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        return ['status' => 0, 'body' => curl_error($ch)];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string) $body];
}

/** @return array{status:int, body:string, headers:array<string,string>} */
function httpPost(string $url, string $json, array $headers = [], int $timeout = 60): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['status' => 0, 'body' => curl_error($ch), 'headers' => []];
    }
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerBlock = substr((string) $raw, 0, $headerSize);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $parsed = [];
    foreach (explode("\r\n", $headerBlock) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
        }
    }
    return ['status' => $status, 'body' => substr((string) $raw, $headerSize), 'headers' => $parsed];
}

function cli(array $args, array $env = []): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/namesweep') . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $out  = [];
    $code = 0;
    if ($env === []) {
        exec($cmd . ' 2>&1', $out, $code);
        return ['code' => $code, 'out' => implode(PHP_EOL, $out)];
    }

    // Windows has no `VAR=x cmd` prefix syntax, so injected environment
    // variables go through proc_open's $env parameter. The old
    // `__PREPEND__ => 'VAR=x cmd'` syntax was Unix-only and is ignored here.
    // NAMESWEEP_ENV_ALLOW_OVERRIDE makes the child honour the injection over
    // the project .env (see NameSweep\Util\Env).
    $fullEnv = getenv();
    foreach ($env as $key => $value) {
        if ($key === '__PREPEND__') {
            continue;
        }
        $fullEnv[$key] = (string) $value;
    }
    $fullEnv['NAMESWEEP_ENV_ALLOW_OVERRIDE'] = '1';

    $pipes = [];
    $proc  = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $fullEnv);
    if (!is_resource($proc)) {
        return ['code' => -1, 'out' => 'could not spawn process'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return ['code' => $code, 'out' => str_replace("\r\n", PHP_EOL, trim($stdout . PHP_EOL . $stderr))];
}

$S = "Smoke-" . substr(bin2hex(random_bytes(4)), 0, 8);

echo "NameSweep acceptance smoke — " . date('c') . PHP_EOL;

// ── 15.1 Structural ────────────────────────────────────────────────────────
section('15.1 Structural');
$missing = [];
foreach (['composer.json', 'database/schema.sql', 'public/index.php', 'public/api.php', 'public/router.php', 'bootstrap.php', 'config/app.php', 'config/tlds.php', 'config/markets.php', 'config/social.php'] as $f) {
    if (!is_file($root . '/' . $f)) {
        $missing[] = $f;
    }
}
check('required files exist', $missing === [], $missing === [] ? '' : implode(', ', $missing));
check('M13/M14 source files exist', is_file($root . '/src/Util/ReportAnalyzer.php') && is_file($root . '/public/assets/js/app.js'));

$evalRefs = [];
foreach (['src', 'public'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!in_array($file->getExtension(), ['php', 'js'], true)) {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if (preg_match('/\beval\s*\(|\bshell_exec\s*\(|\bsystem\s*\(/', (string) $content)) {
            $evalRefs[] = $file->getFilename();
        }
    }
}
check('no eval/shell_exec/system', $evalRefs === [], implode(', ', $evalRefs));

$directSrc = [];
foreach (glob($root . '/public/*.php') as $f) {
    $content = (string) file_get_contents($f);
    if (preg_match('#\.\./src/#', $content)) {
        $directSrc[] = basename($f);
    }
}
check('public/ has no ../src refs', $directSrc === [], implode(', ', $directSrc));

// ── 15.9 CLI ───────────────────────────────────────────────────────────────
section('15.9 CLI');
$help = cli(['help']);
check('help exits 0 and lists commands', $help['code'] === 0, implode(', ', array_map('trim', array_slice(explode(PHP_EOL, $help['out']), 0, 3))));
$bad = cli(['check']);
check('check without name exits 2', $bad['code'] === 2);
$badName = cli(['check', 'Bad_Name!']);
check('invalid name exits 2', $badName['code'] === 2);
$badBatch = cli(['check', 'good,bad_name']);
check('invalid batch name exits 2', $badBatch['code'] === 2);
$emptyBatch = cli(['check']);
check('batch check still requires a name source', $emptyBatch['code'] === 2);
$badKey = cli(['key:list', '--bogus-flag']);
check('unknown flag exits 2', $badKey['code'] === 2);

$created = cli(['key:create', $S]);
preg_match('/key:\s+(ns_[a-f0-9]{32})/', $created['out'], $m);
$plaintext = $m[1] ?? '';
preg_match('/id:\s+(\d+)/', $created['out'], $idm);
$keyId = (int) ($idm[1] ?? 0);
check('key:create prints plaintext exactly once', preg_match_all('/\bns_[a-f0-9]{32}\b/', $created['out']) === 1, $plaintext !== '' ? 'prefix ' . substr($plaintext, 0, 9) : 'no key found');

$listed = cli(['key:list']);
check('key:list never prints plaintext', !str_contains($listed['out'], $plaintext));

// ── 15.2/15.3 Engine + domain ──────────────────────────────────────────────
section('15.3 Domain checks (live)');
$engine = null;
try {
    $app = require $root . '/bootstrap.php';
    $engine = $app['engine'];
} catch (\Throwable $e) {
    $engine = null;
}
if ($engine === null) {
    skip('engine available (DB)', 'bootstrap failed: ' . ($app['db_error'] ?? 'unknown'));
} else {
    check('engine available (DB)', true, 'db ok');

    $probe = 'zqxjkwxyz123';
    $t1 = microtime(true);
    try {
        $res = $engine->checkAsArray(new NameSweep\CheckRequest($probe, ['com'], ['domain'], [], false));
        $elapsed = microtime(true) - $t1;
        $statuses = array_column($res['results']['domain'] ?? [], 'status');
        check('live .com probe is available', in_array('available', $statuses, true), 'in ' . round($elapsed, 1) . 's: ' . implode(',', $statuses));
    } catch (\Throwable $e) {
        check('live .com probe', false, $e->getMessage());
    }

    // RDAP 404 → available is the same path as above; check the WHOIS fallback
    // path for .do/.ht returns uncertain with an empty markers file (spec §15.3).
    if (is_file($root . '/bootstrap.txt') && trim((string) file_get_contents($root . '/bootstrap.txt')) === '') {
        $res = $engine->checkAsArray(new NameSweep\CheckRequest($probe, ['do'], ['domain'], [], false));
        $row = $res['results']['domain'][0] ?? null;
        check('.do with empty markers → uncertain', ($row['status'] ?? '') === 'uncertain', $row['detail'] ?? 'no detail');
    } else {
        skip('.do empty-markers path', 'bootstrap.txt already filled in');
    }
}

// ── M9 Universal suffix resolution ─────────────────────────────────────────
section('M9 Suffix resolution (live)');
if ($engine === null) {
    skip('suffix resolution', 'engine unavailable');
} else {
    $probe = 'zqxjkwxyz123';

    // Any delegated suffix must resolve — no closed allowlist. These span the
    // three resolution tiers: bootstrap RDAP, IANA WHOIS directory, and a
    // multi-label suffix served by its parent registry.
    $suffixes = ['org', 'ai', 'xyz', 'co', 'me', 'com.do'];
    try {
        $res  = $engine->checkAsArray(new NameSweep\CheckRequest($probe, $suffixes, ['domain'], [], false));
        $rows = $res['results']['domain'] ?? [];
        $unresolved = [];
        foreach ($rows as $r) {
            if (($r['status'] ?? '') === 'uncertain') {
                $unresolved[] = $r['tld'] . ':' . substr((string) ($r['detail'] ?? ''), 0, 40);
            }
        }
        check(
            'arbitrary suffixes resolve (no allowlist)',
            count($rows) === count($suffixes) && $unresolved === [],
            $unresolved === [] ? implode(',', $suffixes) : 'unresolved: ' . implode(' | ', $unresolved)
        );

        $byTld = array_column($rows, 'status', 'tld');
        check('multi-label suffix works', ($byTld['com.do'] ?? '') !== '' && ($byTld['com.do'] ?? '') !== 'uncertain', 'com.do → ' . ($byTld['com.do'] ?? 'missing'));
    } catch (\Throwable $e) {
        check('arbitrary suffixes resolve (no allowlist)', false, $e->getMessage());
    }

    // An undelegated suffix is a real answer, not a coverage gap.
    try {
        $res = $engine->checkAsArray(new NameSweep\CheckRequest($probe, ['zzznotatld'], ['domain'], [], false));
        $row = $res['results']['domain'][0] ?? null;
        check(
            'undelegated suffix → uncertain + reason',
            ($row['status'] ?? '') === 'uncertain' && str_contains((string) ($row['detail'] ?? ''), 'not delegated'),
            (string) ($row['detail'] ?? 'no detail')
        );
    } catch (\Throwable $e) {
        check('undelegated suffix → uncertain + reason', false, $e->getMessage());
    }

    // An uncertain result must never be replayed from cache: a momentary
    // failure would otherwise be served as the answer for a whole TTL.
    try {
        $engine->checkAsArray(new NameSweep\CheckRequest($probe, ['zzznotatld'], ['domain'], [], false));
        $res = $engine->checkAsArray(new NameSweep\CheckRequest($probe, ['zzznotatld'], ['domain'], [], true));
        $row = $res['results']['domain'][0] ?? null;
        check('uncertain is never cached', ($row['from_cache'] ?? true) === false, 'from_cache=' . var_export($row['from_cache'] ?? null, true));
    } catch (\Throwable $e) {
        check('uncertain is never cached', false, $e->getMessage());
    }
}

// ── M10 Aftermarket truth ──────────────────────────────────────────────────
section('M10 Aftermarket signals (live)');
if ($engine === null) {
    skip('aftermarket signals', 'engine unavailable');
} else {
    // The module used to return early unless the domain was `available`, so it
    // could never report that a REGISTERED domain is for sale.
    try {
        $res  = $engine->checkAsArray(new NameSweep\CheckRequest('google', ['com'], ['domain','marketplace'], [], false));
        $rows = $res['results']['marketplace'] ?? [];
        check('marketplace runs for registered domains', count($rows) === 1, 'rows=' . count($rows));

        // ...and must not flag a live business as buyable.
        $status = $rows[0]['status'] ?? '';
        check('live business not flagged for_sale/parked', !in_array($status, ['for_sale','parked'], true), 'google.com → ' . $status);
    } catch (\Throwable $e) {
        check('marketplace runs for registered domains', false, $e->getMessage());
    }

    // AdSense flagged nepal.com, a real travel guide, as parked. It runs on
    // millions of ordinary sites and must never be a parking signature.
    $detectorSrc = (string) file_get_contents($root . '/src/Providers/Marketplace/ParkedPageDetector.php');
    $sigBlock = substr($detectorSrc, (int) strpos($detectorSrc, 'const SIGNATURES'), 1400);
    check(
        'AdSense is not a parked signature',
        !str_contains($sigBlock, 'googlesyndication'),
        'ParkedPageDetector::SIGNATURES'
    );

    // Domain lookups must happen once per suffix, not twice. Before M10 the
    // marketplace module re-ran DomainModule internally.
    $logFile = $root . '/storage/logs/app-' . date('Y-m-d') . '.log';
    $before  = is_file($logFile) ? filesize($logFile) : 0;
    try {
        $engine->checkAsArray(new NameSweep\CheckRequest('zqxjkwsmoke7', ['com','net'], ['domain','marketplace'], [], false));
        $written = is_file($logFile) ? (string) file_get_contents($logFile, false, null, $before) : '';
        $lookups = preg_match_all('/\[domain\] checked zqxjkwsmoke7\./', $written);
        check('domain looked up once per suffix', $lookups === 2, "2 suffixes → {$lookups} lookups");
    } catch (\Throwable $e) {
        check('domain looked up once per suffix', false, $e->getMessage());
    }

    // Modules are singletons, so injected domain results must not survive into
    // the next request. Caught in the M10 battery: a registered domain checked
    // first made a following marketplace-only check read the stale status and
    // report an available name as `registered`.
    try {
        $engine->checkAsArray(new NameSweep\CheckRequest('kofer', ['com'], ['domain','marketplace'], [], false));
        $res = $engine->checkAsArray(new NameSweep\CheckRequest('zqxjkwxyz123', ['com'], ['marketplace'], [], false));
        $row = $res['results']['marketplace'][0] ?? null;
        check(
            'domain results do not leak between requests',
            ($row['status'] ?? '') === 'available',
            'available name after a registered one → ' . ($row['status'] ?? 'none') . ' (' . ($row['source'] ?? '-') . ')'
        );
    } catch (\Throwable $e) {
        check('domain results do not leak between requests', false, $e->getMessage());
    }

    // Live delegation signal. Individual domains can sell, so the assertion is
    // that the mechanism fires across a small set, not on one fixed name.
    $forSale = 0; $probed = 0;
    foreach (['lolev', 'zubok', 'fecob', 'ronib', 'kofer'] as $candidate) {
        try {
            $res = $engine->checkAsArray(new NameSweep\CheckRequest($candidate, ['com'], ['domain','marketplace'], [], false));
            $probed++;
            if (in_array($res['results']['marketplace'][0]['status'] ?? '', ['for_sale','parked'], true)) {
                $forSale++;
            }
        } catch (\Throwable) {
            // transport hiccup — ignore this candidate
        }
    }
    if ($probed === 0) {
        skip('DNS delegation flags brokered domains', 'no candidate could be probed');
    } else {
        check('DNS delegation flags brokered domains', $forSale > 0, "{$forSale}/{$probed} flagged for_sale or parked");
    }
}

// ── M11 Pricing ────────────────────────────────────────────────────────────
section('M11 Registration pricing (live)');
if ($engine === null) {
    skip('pricing', 'engine unavailable');
} else {
    try {
        $res  = $engine->checkAsArray(new NameSweep\CheckRequest('zqxjkwprice9', ['com','org','io','do'], ['domain'], [], false));
        $rows = [];
        foreach ($res['results']['domain'] as $r) {
            $rows[$r['tld']] = $r;
        }

        $com = $rows['com']['pricing'] ?? null;
        check(
            'priced suffix carries registration + renewal',
            is_array($com) && is_float($com['registration'] ?? null) && $com['source'] !== null,
            '.com → ' . json_encode($com['registration'] ?? null) . ' via ' . ($com['source'] ?? 'none')
        );

        // Different suffixes must not all report the same number.
        $prices = array_filter(array_map(static fn ($t) => $rows[$t]['pricing']['registration'] ?? null, ['com','org','io']));
        check('prices differ per suffix', count(array_unique($prices)) === count($prices) && count($prices) === 3, implode(' / ', $prices));

        // An unpriced suffix must say so rather than stay silent.
        $do = $rows['do']['pricing'] ?? null;
        check(
            'unpriced suffix reports a reason',
            is_array($do) && ($do['registration'] ?? null) === null && ($do['note'] ?? null) !== null,
            '.do → ' . substr((string) ($do['note'] ?? 'no pricing block'), 0, 48)
        );

        // Registration price and aftermarket asking price must stay separate.
        check(
            'registration price is not the sale price',
            array_key_exists('sale_price', $rows['com']) && $rows['com']['sale_price'] === null,
            'sale_price on an unlisted name → ' . json_encode($rows['com']['sale_price'])
        );
    } catch (\Throwable $e) {
        check('priced suffix carries registration + renewal', false, $e->getMessage());
    }

    // Pricing must never be stored on the check row: a 30-day trademark row
    // would otherwise carry a figure that went stale weeks ago.
    try {
        $cols = $app['db']->pdo()->query('SHOW COLUMNS FROM checks')->fetchAll(\PDO::FETCH_COLUMN);
        check(
            'pricing is derived, not stored on check rows',
            !in_array('registration_price', $cols, true),
            'checks columns: ' . count($cols)
        );
    } catch (\Throwable $e) {
        skip('pricing is derived, not stored on check rows', $e->getMessage());
    }
}

// ── 15.4/15.5 Marketplace + Social (live + provider contract) ──────────────
section('15.4/15.5 Marketplace + Social');
if ($engine !== null) {
    $t1 = microtime(true);
    try {
        $res = $engine->checkAsArray(new NameSweep\CheckRequest($probe, ['com'], ['marketplace', 'social'], [], false));
        $mp  = $res['results']['marketplace'] ?? [];
        $soc = $res['results']['social'] ?? [];
        check('marketplace ran for available .com', $mp !== [] && !str_contains($mp[0]['status'] ?? '', 'engine'), '→ ' . $mp[0]['status'] . ' (' . $mp[0]['source'] . ') in ' . round(microtime(true) - $t1, 1) . 's');
        check('social covers 4 platforms', count($soc) === 4, implode(',', array_map(static fn ($r) => $r['platform'], $soc)));
        check('social returned no transport crashes', !in_array('engine', array_column($soc, 'source'), true));
    } catch (\Throwable $e) {
        check('marketplace+social live', false, $e->getMessage());
    }

    // §15.5 contract: 200 with no positive signal → uncertain, NEVER available.
    // Provider-level, deterministic (no network).
    $twitter = new NameSweep\Providers\Social\TwitterProvider();
    $noSignal = $twitter->classify(200, '<html><body>This profile does not exist or the page is broken.</body></html>');
    check('200 without signal → uncertain (never available)', ($noSignal['status'] ?? '') === 'uncertain', 'status=' . ($noSignal['status'] ?? '?'));
    $gone = $twitter->classify(404, '<html><body>404</body></html>');
    check('404 → available', ($gone['status'] ?? '') === 'available');
}

// ── 15.6 Trademark ─────────────────────────────────────────────────────────
section('15.6 Trademark');
if ($engine !== null) {
    $ht = $engine->checkAsArray(new NameSweep\CheckRequest($probe, [], ['trademark'], ['ht'], false));
    $htRow = $ht['results']['trademark'][0] ?? [];
    check('HT always uncertain + exact message', ($htRow['status'] ?? '') === 'uncertain' && ($htRow['detail'] ?? '') === 'Haiti trademark check requires manual verification — no clean API available');

    $dr = $engine->checkAsArray(new NameSweep\CheckRequest($probe, [], ['trademark'], ['dr'], false));
    $drRow = $dr['results']['trademark'][0] ?? [];
    check('DR parse failure → exact message', ($drRow['status'] ?? '') === 'uncertain' && ($drRow['detail'] ?? '') === 'ONAPI page unparseable — manual assist required');

    $us = $engine->checkAsArray(new NameSweep\CheckRequest($probe, [], ['trademark'], ['us'], false));
    $usRow = $us['results']['trademark'][0] ?? [];
    if (($usRow['status'] ?? '') === '') {
        skip('USPTO no-hit → available', 'no row');
    } else {
        check('USPTO no-hit → available', ($usRow['status'] ?? '') === 'available', $usRow['detail'] ?? '');
    }
}

// ── 15.8 REST API + rate limit (spawned dev server) ────────────────────────
section('15.8 REST API');
$port = random_int(18000, 20000);
// Windows has no `VAR=x cmd` prefix syntax — the old command string made
// CreateProcess look for an executable literally named
// "RATE_LIMIT_PER_MINUTE=10", so the server never started and this whole
// section skipped. The injected variables go through proc_open's $env
// parameter instead; NAMESWEEP_ENV_ALLOW_OVERRIDE lets the child honour
// them over the project .env (see NameSweep\Util\Env).
$serverEnv = array_merge(getenv(), [
    'RATE_LIMIT_PER_MINUTE'        => '10',
    'NAMESWEEP_ENV_ALLOW_OVERRIDE' => '1',
]);
$server = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root . '/public') . ' ' . escapeshellarg($root . '/public/router.php'),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    $serverEnv
);
if (!is_resource($server)) {
    skip('API section', 'could not spawn dev server');
} else {
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($i = 0; $i < 20; $i++) {
        usleep(150000);
        $r = httpGet($base . '/api/v1/health', [], 2);
        if ($r['status'] !== 0) {
            $ready = true;
            break;
        }
    }
    if (!$ready) {
        skip('API section', 'dev server never became ready');
    } else {
        $health = json_decode(httpGet($base . '/api/v1/health')['body'], true);
        check('GET /health shape', ($health['status'] ?? '') === 'ok', 'db=' . ($health['db'] ?? '?'));

        $noAuth = httpPost($base . '/api/v1/check', '{"name":"x","modules":[]}');
        $noAuthJson = json_decode($noAuth['body'], true);
        check('no auth → 401 error shape', $noAuth['status'] === 401 && isset($noAuthJson['error']['code']), $noAuthJson['error']['code'] ?? '?');

        if ($plaintext === '') {
            skip('API auth calls', 'no key');
        } else {
            $auth = ['Authorization: Bearer ' . $plaintext];

            // API vs CLI results shape (spec §15.8): both from cache.
            $cliShape = cli(['check', $probe, '--modules=domain,marketplace,social,trademark', '--json']);
            $cliJson  = json_decode($cliShape['out'], true);
            $apiRes   = httpPost($base . '/api/v1/check', json_encode(['name' => $probe, 'use_cache' => true, 'include_summary' => false]), $auth, 90);
            $apiJson  = json_decode($apiRes['body'], true);
            $sameKeys = isset($cliJson['results'], $apiJson['results']) && array_keys($cliJson['results']) === array_keys($apiJson['results']);
            check('CLI and API same results shape', $sameKeys, $sameKeys ? implode(',', array_keys($apiJson['results'])) : 'mismatch');
            check('single check includes deterministic verdict', is_array($apiJson['verdict'] ?? null) && array_key_exists('recommendation', $apiJson['verdict']), 'verdict keys=' . implode(',', array_keys($apiJson['verdict'] ?? [])));

            $bulk = httpPost($base . '/api/v1/check', json_encode(['names' => [$probe, 'lumen'], 'tlds' => ['com'], 'modules' => ['domain'], 'include_summary' => false]), $auth, 90);
            $bulkJson = json_decode($bulk['body'], true);
            check('bulk API returns keyed reports', $bulk['status'] === 200 && ($bulkJson['names'] ?? []) === [$probe, 'lumen'] && isset($bulkJson['results'][$probe]['verdict']), 'names=' . json_encode($bulkJson['names'] ?? null));

            $badBulk = httpPost($base . '/api/v1/check', json_encode(['names' => [$probe, 'bad_name'], 'modules' => ['domain'], 'include_summary' => false]), $auth, 10);
            $badBulkJson = json_decode($badBulk['body'], true);
            check('bulk API rejects mixed invalid names', $badBulk['status'] === 400 && isset($badBulkJson['error']['code']), $badBulkJson['error']['code'] ?? '?');

            $tlds = httpGet($base . '/api/v1/tlds', $auth, 30);
            $tldsJson = json_decode($tlds['body'], true);
            check('TLD discovery endpoint returns list', $tlds['status'] === 200 && is_array($tldsJson['tlds'] ?? null) && ($tldsJson['count'] ?? 0) > 0, 'count=' . ($tldsJson['count'] ?? 0));

            $pricing = httpGet($base . '/api/v1/pricing', $auth, 30);
            $pricingJson = json_decode($pricing['body'], true);
            check('pricing discovery endpoint returns rows', $pricing['status'] === 200 && is_array($pricingJson['pricing'] ?? null) && ($pricingJson['count'] ?? 0) > 0, 'count=' . ($pricingJson['count'] ?? 0));

            // Manual trademark verify (§15.6).
            $manual = httpPost($base . '/api/v1/check/trademark/manual', json_encode(['name' => $probe, 'jurisdiction' => 'ht', 'status' => 'registered', 'note' => 'smoke manual verify']), $auth);
            $manualJson = json_decode($manual['body'], true);
            check('manual verify → 201 source=manual', $manual['status'] === 201 && ($manualJson['source'] ?? '') === 'manual', $manualJson['detail'] ?? '');
            if (str_contains((string) ($manualJson['detail'] ?? ''), 'smoke manual verify')) {
                check('manual detail contains user note', true);
            } else {
                check('manual detail contains user note', false, 'note missing');
            }

            // Rate limit: 11 requests on an AUTHED endpoint (limit lowered to
            // 10 via RATE_LIMIT_PER_MINUTE on the spawned server) → 429.
            $got429 = false;
            $retryAfter = null;
            $last = null;
            $rateBody = json_encode(['name' => $probe, 'modules' => ['domain'], 'use_cache' => true, 'include_summary' => false]);
            for ($i = 0; $i < 11; $i++) {
                $last = httpPost($base . '/api/v1/check', $rateBody, $auth, 10);
                if ($last['status'] === 429) {
                    $got429 = true;
                    $retryAfter = $last['headers']['retry-after'] ?? null;
                    break;
                }
            }
            if ($got429) {
                check('rate limit → 429 + Retry-After', $retryAfter !== null, 'Retry-After: ' . ($retryAfter ?? 'missing'));
            } else {
                check('rate limit → 429', false, 'never rate limited (last status ' . $last['status'] . ')');
            }

            if ($keyId > 0) {
                cli(['key:revoke', (string) $keyId]);
            }
        }
    }
    proc_terminate($server);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($server);
}

// ── M12 Alternatives + budget ──────────────────────────────────────────────
section('M12 Alternatives + budget');
// One live run with `--alternatives` (com + the config alternative set).
// `--budget` is a ranking input: the suite validates it never filters below.
// Rows may legitimately be `uncertain` (e.g. .do/.ht from this network), so
// exit codes 0 and 1 are both pass; what matters is the shape.
$m12 = cli(['check', 'lumen', '--tlds=com', '--modules=domain', '--alternatives', '--budget=50', '--json']);
$m12Json = json_decode($m12['out'], true);
$m12Rows = is_array($m12Json) ? ($m12Json['results']['domain'] ?? []) : [];
check('--alternatives/--budget accepted', in_array($m12['code'], [0, 1], true) && $m12Rows !== [], 'rows=' . count($m12Rows) . ' code=' . $m12['code']);

$carryMeta   = $m12Rows !== [] && array_key_exists('requested', $m12Rows[0]) && array_key_exists('within_budget', $m12Rows[0]);
$hasReq      = false;
$hasAlt      = false;
$wbShape     = true;
foreach ($m12Rows as $r) {
    if ($r['requested'] === true)  { $hasReq = true; }
    if ($r['requested'] === false) { $hasAlt = true; }
    if ($r['within_budget'] !== null && !is_bool($r['within_budget'])) {
        $wbShape = false;
    }
}
check('rows carry requested + within_budget', $carryMeta && $wbShape);
check('requested vs alternative split', $hasReq && $hasAlt, 'requested=' . ($hasReq ? 'yes' : 'no') . ' alternatives=' . ($hasAlt ? 'yes' : 'no'));

// Budget never filters: the exact same request with and without a budget must
// return the same row set. (com+ai are cached, so this stays fast.)
$nb = cli(['check', 'lumen', '--tlds=com,ai', '--modules=domain', '--json']);
$wb = cli(['check', 'lumen', '--tlds=com,ai', '--modules=domain', '--budget=50', '--json']);
$nbRows = is_array(json_decode($nb['out'], true)) ? (json_decode($nb['out'], true)['results']['domain'] ?? []) : [];
$wbRows = is_array(json_decode($wb['out'], true)) ? (json_decode($wb['out'], true)['results']['domain'] ?? []) : [];
check('budget never filters', $wbRows !== [] && count($wbRows) === count($nbRows), 'rows without=' . count($nbRows) . ' with=' . count($wbRows));

$noBudgetAllNull = true;
foreach ($nbRows as $r) {
    if ($r['within_budget'] !== null) {
        $noBudgetAllNull = false;
    }
}
check('no budget → within_budget null everywhere', $noBudgetAllNull);

$pricedRows   = 0;
$pricedDecided = 0;
foreach ($wbRows as $r) {
    $reg = $r['pricing']['registration'] ?? null;
    if (is_numeric($reg)) {
        $pricedRows++;
        if ($r['within_budget'] === true || $r['within_budget'] === false) {
            $pricedDecided++;
        }
    }
}
if ($pricedRows === 0) {
    skip('priced USD rows get a within_budget verdict', 'pricing endpoint unavailable');
} else {
    check('priced USD rows get a within_budget verdict', $pricedDecided === $pricedRows, 'priced=' . $pricedRows . ' decided=' . $pricedDecided);
}

// ── 15.7 Suggest ───────────────────────────────────────────────────────────
section('15.7 Suggest');
$ollama = httpGet('http://127.0.0.1:11434/api/tags', [], 3);
if ($ollama['status'] !== 200) {
    skip('suggest live run', 'Ollama unreachable');
} else {
    // Domain-only filter: the social platforms are network-dependent in this
    // environment (Instagram can bot-wall → uncertain), which would reject
    // every candidate. §15.7's "all requested modules available" rule still
    // applies to whatever modules were requested.
    $s = cli(['suggest', 'A one-word brand for a coffee shop in Port-au-Prince', '--count=3', '--modules=domain', '--json']);
    $sJson = json_decode($s['out'], true);
    $kept = isset($sJson['kept']) && is_array($sJson['kept']) ? $sJson['kept'] : [];
    check('suggest returns kept names', $s['code'] === 0 && $kept !== [], 'kept=' . count($kept) . ' candidates');
    $allAvailable = true;
    foreach ($kept as $rows) {
        foreach (($rows['results'] ?? []) as $rows2) {
            foreach ($rows2 as $r) {
                if (($r['status'] ?? '') !== 'available') {
                    $allAvailable = false;
                }
            }
        }
    }
    check('every kept name available on all requested modules', $allAvailable, 'checked ' . count($kept) . ' names');
}

// ── 15.10 Web UI ───────────────────────────────────────────────────────────
section('15.10 Web UI');
// Since Phase 1 (Bifrost SSO), every UI page is behind Bifrost::requireLogin():
// an anonymous request redirects to the Bifrost login, so the old "GET = 200"
// assertions no longer hold against a bare dev server. Two things are still
// verifiable here: (a) the SSO gate actually engages, and (b) the page markup
// — rendered directly through Layout::page(), bypassing the auth gate — has
// well-formed asset URLs (regression guard: dirname() on Windows PHP once
// produced '\/assets/...' links, which browsers resolve to https://assets/).
$uiPort = random_int(20001, 22000);
$ui = proc_open(
    escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $uiPort . ' -t ' . escapeshellarg($root . '/public') . ' ' . escapeshellarg($root . '/public/router.php'),
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $uiPipes,
    null,
    null,
    // bypass_shell: on Windows, proc_terminate would otherwise kill only the
    // cmd.exe wrapper and orphan the real `php -S` child on a random port.
    ['bypass_shell' => true]
);
if (!is_resource($ui)) {
    skip('UI section', 'could not spawn dev server');
} else {
    $uiBase = 'http://127.0.0.1:' . $uiPort;
    usleep(400000);
    // GET with headers so the Location target can be asserted (the shared
    // httpGet() deliberately returns only status + body).
    $uiGet = static function (string $url) use ($uiBase): array {
        $ch = curl_init($uiBase . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HEADER => true]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $loc = '';
        foreach (explode("\r\n", substr($raw, 0, $headSize)) as $line) {
            if (stripos($line, 'location:') === 0) {
                $loc = trim(substr($line, 9));
            }
        }
        return ['status' => $status, 'location' => $loc];
    };
    $redirects = [];
    foreach (['/', '/suggest', '/history', '/settings'] as $path) {
        $r = $uiGet($path);
        if ($r['status'] !== 302 || !str_contains($r['location'], '/login')) {
            $redirects[] = $path . '=' . $r['status'] . '->' . $r['location'];
        }
    }
    check('anonymous visits redirect to Bifrost login (SSO gate)', $redirects === [], $redirects === [] ? '' : implode(', ', $redirects));
    proc_terminate($ui);
    foreach ($uiPipes as $pipe) {
        fclose($pipe);
    }
    proc_close($ui);
}

// Page markup via a direct render (no auth gate involved). Asset URLs must be
// root-relative and well-formed; theme links may point at the shared origin.
// Runs the snippet as a temp FILE, not `php -r`: on Windows the inline-code
// form gets mangled by shell quoting (proven: "Parse error: Unclosed '('").
$runPhp = static function (string $code): string {
    $tmp = tempnam(sys_get_temp_dir(), 'ns_smoke_') . '.php';
    file_put_contents($tmp, "<?php\n" . $code);
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
    unlink($tmp);
    return $out;
};
$probe = static function (string $title, string $body, array $styles, array $scripts) use ($root, $runPhp): string {
    $env = "\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/'; \$_SERVER['SCRIPT_NAME']='/index.php';\n"
        . 'require ' . var_export($root . '/bootstrap.php', true) . ";\n"
        . 'echo NameSweep\\Web\\Layout::page('
        . var_export($title, true) . ', '
        . var_export($body, true) . ', '
        . var_export($styles, true) . ', '
        . var_export($scripts, true) . ');';
    return $runPhp($env);
};
$probeHtml = $probe('Check', '<p>x</p>', ['report'], ['app']);
check('layout renders page css/js with well-formed URLs',
    str_contains($probeHtml, 'href="/assets/css/report.css"') && str_contains($probeHtml, 'src="/assets/js/app.js"')
    && !str_contains($probeHtml, 'href="\\/assets') && !str_contains($probeHtml, 'src="\\/assets'),
    'root-relative asset links, no backslash-mangled URLs');
check('home markup has check form + shared shell',
    (static function () use ($root, $runPhp): bool {
        // HomePage::render() on a bare GET = form + placeholder, through Layout.
        $code = "\$_SERVER['REQUEST_METHOD']='GET'; \$_SERVER['REQUEST_URI']='/'; \$_SERVER['SCRIPT_NAME']='/index.php';\n"
            . '$app = require ' . var_export($root . '/bootstrap.php', true) . ";\n"
            . 'echo (new NameSweep\\Web\\Pages\\HomePage(\$app))->render();';
        $html = $runPhp($code);
        return str_contains($html, 'name="modules[]"') && str_contains($html, 'name="names"')
            && str_contains($html, 'name="extra_tlds"') && str_contains($html, 'kt-menu');
    })(),
    'names/modules/extra_tlds fields present inside the themed shell');

// ── 15.7 (b) LLM down → clear exit ─────────────────────────────────────────
$dead = cli(['suggest', 'anything'], ['OLLAMA_BASE_URL' => 'http://127.0.0.1:59999']);
check('suggest with LLM down exits 3 cleanly', $dead['code'] === 3 && !str_contains($dead['out'], 'Fatal error'), 'code=' . $dead['code']);

// ── Summary ────────────────────────────────────────────────────────────────
echo PHP_EOL . str_repeat('-', 50) . PHP_EOL;
echo sprintf('PASS %d · FAIL %d · SKIP %d', $pass, $fail, $skip) . PHP_EOL;
if ($failures !== []) {
    echo 'Failures:' . PHP_EOL;
    foreach ($failures as $f) {
        echo '  - ' . $f . PHP_EOL;
    }
    exit(1);
}
exit(0);