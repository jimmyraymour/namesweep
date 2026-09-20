<?php

/**
 * Shared bootstrap for every entry point (CLI, REST API, Web UI).
 *
 * Loads the Composer autoloader and .env, merges the config/ files, and
 * creates the shared services (logger, database, engine). Returns an array
 * of services; each entry point consumes what it needs.
 *
 * Usage:
 *   $app = require __DIR__ . '/bootstrap.php';
 *
 * Note: this is a small addition over the spec's file list so the three
 * entry points don't duplicate wiring. It is documented in README.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use NameSweep\Engine;
use NameSweep\Modules\DomainModule;
use NameSweep\Modules\MarketplaceModule;
use NameSweep\Modules\SocialModule;
use NameSweep\Modules\TrademarkModule;
use NameSweep\Providers\Marketplace\DnsSignalProvider;
use NameSweep\Providers\Marketplace\OpenproviderProvider;
use NameSweep\Providers\Marketplace\ParkedPageDetector;
use NameSweep\Providers\Marketplace\RobotDomainSearchProvider;
use NameSweep\Providers\Pricing\TldPricing;
use NameSweep\Providers\Rdap\RdapBootstrap;
use NameSweep\Providers\Rdap\RdapClient;
use NameSweep\Providers\Social\InstagramProvider;
use NameSweep\Providers\Social\TikTokProvider;
use NameSweep\Providers\Social\TwitterProvider;
use NameSweep\Providers\Social\YouTubeProvider;
use NameSweep\Providers\Trademark\HtiProvider;
use NameSweep\Providers\Trademark\OnapiProvider;
use NameSweep\Providers\Trademark\UsptoProvider;
use NameSweep\Providers\SuffixResolver;
use NameSweep\Providers\Whois\IanaWhoisDirectory;
use NameSweep\Providers\Whois\WhoisClient;
use NameSweep\Storage\ApiKeyRepository;
use NameSweep\Storage\CheckRepository;
use NameSweep\Storage\Database;
use NameSweep\Storage\SettingsStore;
use NameSweep\Suggest\SuggestEngine;
use NameSweep\Suggest\SuggestRepository;
use NameSweep\Util\Env;
use NameSweep\Util\HttpClient;
use NameSweep\Util\Logger;
use NameSweep\Util\RateLimiter;
use NameSweep\Util\SummaryWriter;

$root = __DIR__;

// Shared portfolio theme (Metronic demo8 look) — lives once at the workspace
// root and is mandatory (no per-app fallback); shared/inc/layout.php pulls in
// theme.php + apps.php. NameSweep\Web\Layout is the thin adapter over it.
require_once dirname($root) . '/shared/inc/layout.php';

// 1. Environment — must load before any config file reads getenv().
Env::load($root . '/.env');

// Bifrost client (AI routes redesign + SSO): required here, shared by every
// entry point (CLI, REST API, Web UI), so Bifrost::ai()/requireLogin() work
// everywhere without each entry point requiring it separately.
require_once dirname($root) . '/bifrost/shared/php/BifrostClient.php';

// 2. Config — app.php is the main array; the rest are merged in.
$config = require $root . '/config/app.php';
$config['tlds']    = require $root . '/config/tlds.php';

// `_default` holds the generic WHOIS markers, not a suffix — lift it out so
// everything downstream can treat $config['tlds'] as pure per-suffix config.
$config['whois_defaults'] = $config['tlds']['_default'] ?? [];
unset($config['tlds']['_default']);
$config['markets'] = require $root . '/config/markets.php';
$config['social']  = require $root . '/config/social.php';
$config['parking'] = require $root . '/config/parking.php';
$config['pricing_overrides'] = require $root . '/config/pricing_overrides.php';

// The alternative-suffix set is the base list from config/app.php plus the
// markets' ccTLDs — if a market is worth a trademark check its suffix is worth
// an alternatives row too. cc_tld, not the market key: DR's market id is `dr`
// but its suffix is `.do`.
$marketSuffixes = [];
foreach ($config['markets'] as $market) {
    $suffix = is_array($market) && isset($market['cc_tld']) && is_string($market['cc_tld'])
        ? trim(strtolower($market['cc_tld']))
        : '';
    if ($suffix !== '') {
        $marketSuffixes[] = $suffix;
    }
}
$config['alternative_tlds'] = array_values(array_unique(array_merge(
    (array) ($config['alternative_tlds'] ?? []),
    $marketSuffixes
)));

// 2b. Runtime settings (Settings page → storage/settings.json, gitignored).
//     Merged on every process start, so edits take effect immediately.
$settings = new SettingsStore($root . '/storage/settings.json');
$config['prompts'] = $settings->prompts($config['prompts'] ?? []);
// Extra suffixes are additive: since M9 any delegated suffix is checkable, so
// these only need an entry to show up in the UI's suffix list.
foreach ($settings->extraTlds() as $extra) {
    if (!isset($config['tlds'][$extra])) {
        $config['tlds'][$extra] = ['type' => 'rdap'];
    }
}

$enabledTlds = $settings->tlds();
if ($enabledTlds !== null) {
    // Keep suffixes the owner ticked, plus any they typed in as extras.
    $keep = array_flip(array_merge($enabledTlds, $settings->extraTlds()));
    $config['tlds'] = array_intersect_key($config['tlds'], $keep);
    $config['default_tlds'] = array_values(array_intersect($config['default_tlds'], array_keys($keep)));
}
if ($config['default_tlds'] === []) {
    $config['default_tlds'] = array_slice(array_keys($config['tlds']), 0, 3);
}

// 3. Locale.
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

// A full check (domain + marketplace + social + trademark across the
// requested and alternative suffixes) is network-bound and routinely runs
// past PHP's 120s default, dying mid-report. CLI is unaffected (0 = unlimited).
if (PHP_SAPI !== 'cli' && (int) ini_get('max_execution_time') < 600) {
    ini_set('max_execution_time', '600');
}

// 4. Shared services.
$logger = new Logger($root . '/storage/logs', $config['app']['log_level'] ?? 'INFO');

$db = null;
$dbError = null;
try {
    $db = new Database($config['db'], $config['app']['timezone'] ?? null);
} catch (\Throwable $e) {
    $dbError = $e->getMessage();
    $logger->error('Database connection failed', ['error' => $dbError]);
}

// 5. Engine wiring (only built when the DB is available).
$engine = null;
$tldPricing = null;
$apiKeys = null;
$checks = null;
$llm = null;
$suggestRepo = null;
$suggestEngine = null;
$summaryWriter = null;
if ($db !== null) {
    $checks  = new CheckRepository($db);
    $apiKeys = new ApiKeyRepository($db);

    $http = new HttpClient($config['http']['timeout'], $config['http']['user_agent']);
    $rdapBootstrap = new RdapBootstrap($http, $config['rdap']['bootstrap_cache'], $config['rdap']['bootstrap_url'], $logger);
    $rdapClient    = new RdapClient($http, $logger);
    $whoisClient   = new WhoisClient($logger, $root . '/bootstrap.txt', $config['whois_defaults']);
    $ianaWhois     = new IanaWhoisDirectory($root . '/storage/cache/whois_servers.json', $logger);

    // Resolves ANY delegated suffix: config override -> RDAP bootstrap ->
    // IANA WHOIS directory. Replaces the old closed allowlist.
    $suffixResolver = new SuffixResolver($config['tlds'], $rdapBootstrap, $ianaWhois, $logger);

    // Registration pricing: Porkbun's keyless public table, with a
    // hand-maintained override file for suffixes it does not sell.
    $tldPricing = new TldPricing(
        new HttpClient(30, $config['http']['user_agent']),
        $config['pricing']['cache'] ?? ($root . '/storage/cache/tld_pricing.json'),
        $config['pricing']['endpoint'] ?? 'https://api.porkbun.com/api/json/v3/pricing/get',
        $config['pricing_overrides'],
        $logger
    );

    // Domain module is shared: the MarketplaceModule reuses it to learn which
    // TLDs are available before checking the aftermarket.
    $domainModule = new DomainModule(
        $suffixResolver,
        $config['default_tlds'],
        $config['ttl']['domain'],
        $rdapClient,
        $whoisClient,
        $checks,
        $logger,
        $tldPricing
    );

    // Marketplace 3-tier aftermarket: RobotDomainSearch → Openprovider → parked.
    $robot = new RobotDomainSearchProvider(
        $config['aftermarket']['robotdomainsearch_availability'] ?? 'https://api.robotdomainsearch.com/availability?domain={domain}',
        $config['aftermarket']['robotdomainsearch_aftermarket'] ?? 'https://api.robotdomainsearch.com/v1/aftermarket?domain={domain}',
        $http,
        $logger
    );
    $parked = new ParkedPageDetector($http, $logger);
    $dnsSignal = new DnsSignalProvider($config['parking'], $logger);

    // Registered names: read the delegation first. It is the only keyless
    // signal that sees Afternic/DAN/NameFind landers, which are JS-rendered
    // and invisible to the page fetch.
    $registeredChain = [$dnsSignal, $parked];

    // Available names have no delegation to read — only an aftermarket API can
    // report a registry premium or reserved name.
    $availableChain = [];
    // Both halves are required: Openprovider authenticates with a username and
    // a control-panel password hash, not a single static key.
    if (($config['aftermarket']['openprovider_username'] ?? '') !== ''
        && ($config['aftermarket']['openprovider_password_hash'] ?? '') !== '') {
        $availableChain[] = new OpenproviderProvider(
            $config['aftermarket']['openprovider_api_url'] ?? 'https://api.openprovider.eu/v1',
            $config['aftermarket']['openprovider_username'],
            $config['aftermarket']['openprovider_password_hash'],
            $http,
            $logger,
            $config['aftermarket']['openprovider_token_cache'] ?? null,
            $config['aftermarket']['openprovider_provider'] ?? 'SEDO'
        );
    }
    $availableChain[] = $parked;

    $marketplaceModule = new MarketplaceModule(
        $domainModule,
        $robot,
        $registeredChain,
        $availableChain,
        (int) ($config['aftermarket']['concurrency'] ?? 5),
        $config['ttl']['marketplace'],
        $checks,
        $logger,
        $config['default_tlds']
    );

    // Social handles — one provider per platform in config/social.php.
    $socialProviders = [
        'twitter'   => new TwitterProvider(),
        'instagram' => new InstagramProvider(),
        'tiktok'    => new TikTokProvider(),
        'youtube'   => new YouTubeProvider(),
    ];
    $socialModule = new SocialModule(
        $socialProviders,
        $config['social'],
        $http,
        $config['ttl']['social'],
        $checks,
        $logger
    );

    // Trademark — one provider per market (config/markets.php).
    $trademarkProviders = [
        'onapi' => new OnapiProvider(
            $config['trademark']['onapi_url'] ?? 'https://onapi.gob.do/busqueda-de-marcas',
            $http,
            $logger
        ),
        'hti'   => new HtiProvider(),
        'uspto' => new UsptoProvider(
            $config['trademark']['uspto_url'] ?? 'https://tmsearch.uspto.gov/prod-v1-0-0/tmsearch',
            $http,
            $logger
        ),
    ];
    $trademarkModule = new TrademarkModule(
        $config['markets'],
        $trademarkProviders,
        (bool) ($config['trademark']['enabled'] ?? true),
        $config['default_markets'] ?? ['us'],
        $config['ttl']['trademark'],
        $checks,
        $logger
    );

    $modules = [
        'domain'      => $domainModule,
        'marketplace' => $marketplaceModule,
        'social'      => $socialModule,
        'trademark'   => $trademarkModule,
    ];

    $engine = new Engine(
        $modules,
        $checks,
        $logger,
        $config['ttl'],
        $config['default_tlds'] ?? [],
        $config['alternative_tlds'] ?? []
    );

    // M5 — Suggest mode + report summaries. Both call Bifrost::ai() directly
    // (AI routes redesign) — no local LLM client, no provider config here.
    $suggestRepo    = new SuggestRepository($db);
    $suggestEngine  = new SuggestEngine($engine, $suggestRepo, $logger, $config);
    $summaryWriter  = new SummaryWriter($db, $logger, $config);
}

return [
    'root'           => $root,
    'config'         => $config,
    'logger'         => $logger,
    'db'             => $db,
    'db_error'       => $dbError,
    'engine'         => $engine,
    'checks'         => $checks,
    'tld_pricing'    => $tldPricing,
    'rdap_bootstrap' => $db !== null ? $rdapBootstrap : null,
    'api_keys'       => $apiKeys,
    'rate_limiter'   => new RateLimiter($config['rate_limit']['per_minute'], $root . '/storage/tmp'),
    'suggest_repo'   => $suggestRepo,
    'suggest_engine' => $suggestEngine,
    'summary_writer' => $summaryWriter,
    'settings'       => $settings,
];
