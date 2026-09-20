<?php

/**
 * OpenproviderProvider unit tests — run offline:
 *
 *   php tests/openprovider.php
 *
 * No network, no credentials. A fake HttpClient replays the response shapes
 * published in Openprovider's CheckDomainResponse schema (captured from
 * developer.openprovider.com on 2026-09-17), so the login handshake, the token
 * cache, the 401 re-login and every status mapping are covered without an
 * account.
 *
 * Exits 0 = all green, 1 = one or more FAIL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use NameSweep\Providers\Marketplace\OpenproviderProvider;
use NameSweep\Util\HttpClientInterface;
use NameSweep\Util\Logger;

$pass = 0;
$fail = 0;
$failures = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $failures;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
        echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . PHP_EOL;
    }
}

/**
 * Replays queued responses and records every request it was given.
 */
final class FakeHttp implements HttpClientInterface
{
    /** @var list<array{status:int, body:string}> */
    private array $queue;

    /** @var list<array{url:string, body:string, headers:string[]}> */
    public array $calls = [];

    /** @param list<array{status:int, body:string}> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function get(string $url, array $headers = [], int $maxRedirects = 3): array
    {
        throw new \LogicException('OpenproviderProvider must not use GET');
    }

    public function postJson(string $url, string $jsonBody, int $timeout = 60, array $headers = []): array
    {
        $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'headers' => $headers];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttp ran out of queued responses for ' . $url);
        }
        return $next;
    }

    public function getMulti(array $jobs, int $concurrency = 5): array
    {
        throw new \LogicException('not used');
    }
}

function loginOk(string $token = 'tok-abc'): array
{
    return ['status' => 200, 'body' => json_encode([
        'code' => 0,
        'data' => ['token' => $token, 'reseller_id' => 100001],
        'desc' => '',
    ], JSON_THROW_ON_ERROR)];
}

/** @param array<string, mixed> $row */
function checkOk(array $row): array
{
    return ['status' => 200, 'body' => json_encode([
        'code' => 0,
        'data' => ['results' => [$row]],
        'desc' => '',
    ], JSON_THROW_ON_ERROR)];
}

$logger = new Logger(sys_get_temp_dir(), 'ERROR');

/** Builds a provider with no token cache, so each test logs in fresh. */
function provider(FakeHttp $http, Logger $logger): OpenproviderProvider
{
    return new OpenproviderProvider('https://api.openprovider.eu/v1', 'user@example.com', 'hash-value', $http, $logger, null, 'SEDO');
}

echo PHP_EOL . 'Openprovider — login handshake' . PHP_EOL;

$http = new FakeHttp([loginOk(), checkOk(['domain' => 'example.com', 'status' => 'free', 'is_premium' => false])]);
$result = provider($http, $logger)->check('example', 'com');

check('logs in before checking', count($http->calls) === 2, count($http->calls) . ' request(s)');
check(
    'login hits /auth/login on the .eu v1 host',
    ($http->calls[0]['url'] ?? '') === 'https://api.openprovider.eu/v1/auth/login',
    $http->calls[0]['url'] ?? '(none)'
);
$loginBody = json_decode($http->calls[0]['body'] ?? '{}', true);
check('login sends the hash as the password', ($loginBody['password'] ?? null) === 'hash-value');
check('login sends the username', ($loginBody['username'] ?? null) === 'user@example.com');
check('login carries no Authorization header', ($http->calls[0]['headers'] ?? []) === []);

check(
    'check hits /domains/check',
    ($http->calls[1]['url'] ?? '') === 'https://api.openprovider.eu/v1/domains/check',
    $http->calls[1]['url'] ?? '(none)'
);
check(
    'check presents the bearer token from login',
    in_array('Authorization: Bearer tok-abc', $http->calls[1]['headers'] ?? [], true),
    implode(', ', $http->calls[1]['headers'] ?? [])
);

$checkBody = json_decode($http->calls[1]['body'] ?? '{}', true);
check('check actually sends the domain', ($checkBody['domains'][0]['name'] ?? null) === 'example');
check('check sends the extension', ($checkBody['domains'][0]['extension'] ?? null) === 'com');
check('check asks for prices', ($checkBody['with_price'] ?? null) === true);
check('check names the aftermarket provider', ($checkBody['provider'] ?? null) === 'SEDO');

echo PHP_EOL . 'Openprovider — status mapping' . PHP_EOL;

check('status free → available', $result !== null && $result->status === 'available', $result->status ?? 'null');

$http = new FakeHttp([loginOk(), checkOk(['domain' => 'example.com', 'status' => 'active', 'reason' => 'Domain exists', 'is_premium' => false])]);
$r = provider($http, $logger)->check('example', 'com');
check('status active → registered', $r !== null && $r->status === 'registered', $r->status ?? 'null');
check('registered carries the reason as detail', $r !== null && $r->detail === 'Domain exists', $r->detail ?? 'null');

$http = new FakeHttp([loginOk(), checkOk(['domain' => 'example.com', 'status' => 'inuse', 'is_premium' => false])]);
$r = provider($http, $logger)->check('example', 'com');
check('status inuse → registered', $r !== null && $r->status === 'registered', $r->status ?? 'null');

$http = new FakeHttp([loginOk(), checkOk([
    'domain' => 'brand.com',
    'status' => 'free',
    'is_premium' => true,
    'premium' => ['currency' => 'USD', 'price' => ['create' => 2500.0]],
])]);
$r = provider($http, $logger)->check('brand', 'com');
check('premium name → for_sale', $r !== null && $r->status === 'for_sale', $r->status ?? 'null');
check('premium price is read from premium.price.create', $r !== null && $r->price === 2500.0, var_export($r->price ?? null, true));
check('premium currency is carried', $r !== null && $r->currency === 'USD', $r->currency ?? 'null');

// is_premium true but a zero price is NOT a sale listing — it is the schema's
// own default, and treating it as for_sale at $0 would be a lie.
$http = new FakeHttp([loginOk(), checkOk([
    'domain' => 'example.com',
    'status' => 'free',
    'is_premium' => true,
    'premium' => ['currency' => 'USD', 'price' => ['create' => 0]],
])]);
$r = provider($http, $logger)->check('example', 'com');
check('is_premium with zero price is not for_sale', $r !== null && $r->status === 'available', $r->status ?? 'null');

$http = new FakeHttp([loginOk(), checkOk(['domain' => 'example.com', 'status' => 'something-new', 'is_premium' => false])]);
$r = provider($http, $logger)->check('example', 'com');
check('unknown status → uncertain, never a wrong answer', $r !== null && $r->status === 'uncertain', $r->status ?? 'null');
check('uncertain names the raw status', $r !== null && str_contains((string) $r->detail, 'something-new'), $r->detail ?? 'null');

echo PHP_EOL . 'Openprovider — misses and failures' . PHP_EOL;

$http = new FakeHttp([loginOk(), ['status' => 200, 'body' => json_encode(['code' => 0, 'data' => ['results' => []], 'desc' => ''])]]);
check('empty results → miss (null), falls through to parked', provider($http, $logger)->check('example', 'com') === null);

$http = new FakeHttp([loginOk(), ['status' => 404, 'body' => '']]);
check('404 → miss (null)', provider($http, $logger)->check('example', 'com') === null);

// The regression that mattered: a bad credential used to be swallowed as a
// miss, so a whole sweep silently produced nothing. It must shout instead.
$http = new FakeHttp([loginOk(), ['status' => 401, 'body' => ''], loginOk('tok-2'), ['status' => 401, 'body' => '']]);
$threw = false;
$message = '';
try {
    provider($http, $logger)->check('example', 'com');
} catch (\RuntimeException $e) {
    $threw = true;
    $message = $e->getMessage();
}
check('a persistent 401 throws rather than reporting a miss', $threw);
check('the error names the variables to fix', str_contains($message, 'OPENPROVIDER_PASSWORD_HASH'), $message);
check('it re-authenticated exactly once before giving up', count($http->calls) === 4, count($http->calls) . ' request(s)');

// A token that expires mid-sweep must recover silently.
$http = new FakeHttp([
    loginOk('stale'),
    ['status' => 401, 'body' => ''],
    loginOk('fresh'),
    checkOk(['domain' => 'example.com', 'status' => 'free', 'is_premium' => false]),
]);
$r = provider($http, $logger)->check('example', 'com');
check('an expired token is refreshed and the check succeeds', $r !== null && $r->status === 'available', $r->status ?? 'null');
check(
    'the retry presents the NEW token',
    in_array('Authorization: Bearer fresh', $http->calls[3]['headers'] ?? [], true),
    implode(', ', $http->calls[3]['headers'] ?? [])
);

$http = new FakeHttp([['status' => 401, 'body' => '']]);
$threw = false;
try {
    provider($http, $logger)->check('example', 'com');
} catch (\RuntimeException $e) {
    $threw = true;
    $message = $e->getMessage();
}
check('a rejected login throws', $threw);
check('the login error does not echo the response body', $threw && !str_contains($message, 'hash-value'), $message);

$http = new FakeHttp([loginOk(), ['status' => 200, 'body' => 'not json']]);
$threw = false;
try {
    provider($http, $logger)->check('example', 'com');
} catch (\RuntimeException $e) {
    $threw = true;
}
check('invalid JSON throws', $threw);

$http = new FakeHttp([loginOk(), ['status' => 200, 'body' => json_encode(['maintenance' => true, 'data' => ['results' => []]])]]);
$threw = false;
try {
    provider($http, $logger)->check('example', 'com');
} catch (\RuntimeException $e) {
    $threw = true;
}
check('a maintenance response throws rather than reading as a miss', $threw);

echo PHP_EOL . 'Openprovider — token cache' . PHP_EOL;

$cachePath = sys_get_temp_dir() . '/ns_op_token_' . getmypid() . '.json';
@unlink($cachePath);

$http = new FakeHttp([
    loginOk('cached-tok'),
    checkOk(['domain' => 'a.com', 'status' => 'free', 'is_premium' => false]),
    checkOk(['domain' => 'b.com', 'status' => 'free', 'is_premium' => false]),
]);
$p = new OpenproviderProvider('https://api.openprovider.eu/v1', 'user@example.com', 'hash-value', $http, $logger, $cachePath, 'SEDO');
$p->check('a', 'com');
$p->check('b', 'com');
check('a sweep logs in once, not once per domain', count($http->calls) === 3, count($http->calls) . ' request(s)');
check('the token cache file was written', is_file($cachePath));

// A second provider (a fresh CLI run) reuses the cached token without logging in.
$http2 = new FakeHttp([checkOk(['domain' => 'c.com', 'status' => 'free', 'is_premium' => false])]);
$p2 = new OpenproviderProvider('https://api.openprovider.eu/v1', 'user@example.com', 'hash-value', $http2, $logger, $cachePath, 'SEDO');
$p2->check('c', 'com');
check('a later run reuses the cached token', count($http2->calls) === 1, count($http2->calls) . ' request(s)');
check(
    'the reused token is the cached one',
    in_array('Authorization: Bearer cached-tok', $http2->calls[0]['headers'] ?? [], true),
    implode(', ', $http2->calls[0]['headers'] ?? [])
);

// An expired cache entry must not be trusted.
file_put_contents($cachePath, json_encode(['token' => 'expired-tok', 'expires_at' => time() - 60]));
$http3 = new FakeHttp([loginOk('re-issued'), checkOk(['domain' => 'd.com', 'status' => 'free', 'is_premium' => false])]);
$p3 = new OpenproviderProvider('https://api.openprovider.eu/v1', 'user@example.com', 'hash-value', $http3, $logger, $cachePath, 'SEDO');
$p3->check('d', 'com');
check('an expired cached token triggers a fresh login', count($http3->calls) === 2, count($http3->calls) . ' request(s)');

@unlink($cachePath);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo "  {$pass} passed, {$fail} failed" . PHP_EOL;
if ($failures !== []) {
    echo PHP_EOL . 'Failures:' . PHP_EOL;
    foreach ($failures as $f) {
        echo "  - {$f}" . PHP_EOL;
    }
}
exit($fail > 0 ? 1 : 0);
