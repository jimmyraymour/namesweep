# NameSweep — Build Specification

A build spec for a coding agent. Implement exactly what is described here. No extra features, no extra dependencies.

---

## 1. Purpose

NameSweep is a **name-clearance engine**. Given a candidate brand name, it reports whether the matching domain, social handles, marketplace listings, and trademarks are taken, available, for-sale, parked, or uncertain.

It is the first half of a two-tool system. The second tool is **Sigil** (logo generation) — kept in a separate codebase with no shared runtime, no shared data, no shared auth.

**Primary user:** the owner, via the web UI for human use, via the CLI for scripting, and via the REST API so a coding agent working on the brand project can call it programmatically and get a structured JSON response back.

---

## 2. Tech Stack (locked)

| Layer | Choice | Why |
|------|--------|-----|
| Language | **PHP 8.1+** (vanilla, no framework) | Matches the owner's other SaaS; easy to grade line-by-line. |
| Web server | **PHP built-in dev server** in development, **nginx + PHP-FPM** in production | Built-in server is enough for personal use. |
| Database | **MySQL 8.x** | Owner already runs MySQL on other projects; consistent ops. |
| HTTP client | **cURL via a thin wrapper class** | No Guzzle, no Symfony HttpClient. cURL is in PHP core. |
| Autoloading | **Composer autoloader only** | PSR-4, no other Composer packages allowed. |
| Frontend | **Plain JavaScript** (ES6 modules), **plain CSS**, no React/Vue/Svelte/jQuery | DOM is small enough to not need a framework. |
| Icons | **Inline SVG only** | No icon libraries. |
| JSON | `json_encode` / `json_decode` with `JSON_THROW_ON_ERROR` | Standard library. |
| LLM client | **cURL → Ollama HTTP API** (primary), **cURL → OpenRouter** (fallback) | Owner already has OpenRouter key. No SDK. |
| RDAP bootstrap | `data.iana.org/rdap/dns.json`, cached locally, refreshed weekly | Per spec §0 Decision 1. |

**Composer dependencies (composer.json):**
```json
{
    "name": "owner/namesweep",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "ext-curl": "*",
        "ext-pdo": "*",
        "ext-json": "*",
        "ext-mbstring": "*"
    },
    "autoload": {
        "psr-4": {
            "NameSweep\\": "src/"
        }
    }
}
```

**No other Composer packages. Do not add any.**

---

## 3. Directory Structure

```
/workspace/namesweep/
├── public/
│   ├── index.php                 # Web UI entry (front controller)
│   ├── api.php                   # REST API entry (front controller)
│   ├── .htaccess                 # Deny direct access to src/ from web
│   └── assets/
│       ├── css/
│       │   ├── base.css
│       │   ├── report.css
│       │   └── suggest.css
│       └── js/
│           ├── app.js            # UI logic for the main check page
│           ├── suggest.js        # UI logic for the suggest page
│           └── api.js            # Thin fetch wrapper for the API
├── src/
│   ├── Engine.php                # Core orchestrator
│   ├── Modules/
│   │   ├── ModuleInterface.php   # Contract every module implements
│   │   ├── DomainModule.php
│   │   ├── MarketplaceModule.php
│   │   ├── SocialModule.php
│   │   └── TrademarkModule.php
│   ├── Providers/
│   │   ├── Rdap/
│   │   │   ├── RdapBootstrap.php
│   │   │   └── RdapClient.php
│   │   ├── Whois/
│   │   │   └── WhoisClient.php
│   │   ├── Marketplace/
│   │   │   ├── RobotDomainSearchProvider.php
│   │   │   ├── OpenproviderProvider.php
│   │   │   └── ParkedPageDetector.php
│   │   ├── Social/
│   │   │   ├── TwitterProvider.php
│   │   │   ├── InstagramProvider.php
│   │   │   ├── TikTokProvider.php
│   │   │   └── YouTubeProvider.php
│   │   └── Trademark/
│   │       ├── OnapiProvider.php       # Dominican Republic — best-effort / form-scrape
│   │       ├── HtiProvider.php         # Haiti — best-effort / manual assist
│   │       └── UsptoProvider.php       # United States — USPTO TESS
│   ├── Suggest/
│   │   ├── SuggestEngine.php
│   │   └── LlmClient.php
│   ├── Storage/
│   │   ├── Database.php
│   │   ├── CheckRepository.php
│   │   ├── SuggestRepository.php
│   │   └── ApiKeyRepository.php
│   ├── Api/
│   │   ├── Router.php
│   │   ├── Auth.php              # API key auth
│   │   ├── CheckController.php
│   │   └── SuggestController.php
│   ├── Cli/
│   │   └── Command.php           # CLI argument parser + dispatcher
│   ├── Web/
│   │   ├── Pages/
│   │   │   ├── HomePage.php
│   │   │   ├── SuggestPage.php
│   │   │   ├── HistoryPage.php
│   │   │   └── SettingsPage.php
│   │   └── Layout.php
│   └── Util/
│       ├── HttpClient.php        # cURL wrapper
│       ├── Logger.php
│       ├── Env.php               # Reads .env
│       ├── RateLimiter.php
│       └── SummaryWriter.php     # LLM one-line summary at the top of reports
├── bin/
│   └── namesweep                 # CLI entry — `php bin/namesweep ...`
├── config/
│   ├── tlds.php                  # TLD → provider config (RDAP or WHOIS, base URL)
│   ├── markets.php               # DR / HT / US, what providers apply
│   ├── social.php                # Social platforms, profile URL templates
│   └── app.php                   # Cache TTLs, timeouts, default TLDs
├── database/
│   ├── schema.sql                # CREATE TABLE statements
│   └── seed.sql                  # Optional dev seed
├── storage/
│   ├── logs/                     # Rotated daily
│   ├── cache/
│   │   ├── rdap_bootstrap.json   # Cached IANA bootstrap
│   │   └── .gitkeep
│   └── tmp/
├── .env.example
├── .gitignore
├── composer.json
└── README.md                     # Owner-facing notes, not a marketing README
```

**Every file listed above is required.** No extra files. No empty stub folders.

---

## 4. Database Schema (MySQL 8)

Database name: `namesweep`. Charset: `utf8mb4`. Collation: `utf8mb4_0900_ai_ci`.

### 4.1 `api_keys`

Auth table for the REST API. Simple shared-secret per client.

```sql
CREATE TABLE api_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,                          -- human label, e.g. "coding-agent"
    key_hash      CHAR(64)     NOT NULL,                          -- sha256 hex of the plaintext key
    key_prefix    CHAR(8)      NOT NULL,                          -- first 8 chars of plaintext, for identification
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    revoked_at    DATETIME     NULL,
    UNIQUE KEY uniq_key_hash (key_hash),
    KEY idx_prefix (key_prefix)
) ENGINE=InnoDB;
```

Plaintext keys are shown **once** at creation and never stored. The CLI command `namesweep key:create <name>` inserts a row and prints the plaintext.

### 4.2 `checks`

One row per `(name, tld, module)` result. This is the cache.

```sql
CREATE TABLE checks (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(63)     NOT NULL,                          -- the bare name without TLD
    tld           VARCHAR(64)     NOT NULL DEFAULT '',                -- empty for modules that aren't domain-shaped
    module        ENUM('domain','marketplace','social','trademark') NOT NULL,
    platform      VARCHAR(32)     NOT NULL DEFAULT '',                -- social or trademark provider key
    status        ENUM('available','registered','for_sale','parked','uncertain') NOT NULL,
    source        VARCHAR(64)     NOT NULL,                          -- which provider produced this
    detail        JSON            NULL,                              -- provider-specific notes (markers seen, etc.)
    price         DECIMAL(10,2)   NULL,                              -- marketplace only
    url           VARCHAR(512)    NULL,                              -- landing page if any
    checked_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME        NOT NULL,                          -- now() + TTL for that module
    UNIQUE KEY uniq_lookup (name, tld, module, platform),
    KEY idx_expires (expires_at),
    KEY idx_checked (checked_at)
) ENGINE=InnoDB;
```

TTL by module (set in `config/app.php`):
- `domain` → 24h
- `marketplace` → 24h
- `social` → 168h (7 days)
- `trademark` → 720h (30 days)

### 4.3 `suggest_sessions`

```sql
CREATE TABLE suggest_sessions (
    id            CHAR(36)        NOT NULL PRIMARY KEY,              -- UUIDv4
    description   TEXT            NOT NULL,
    model         VARCHAR(64)     NOT NULL,                          -- 'ollama:llama3.1:8b' or 'openrouter:...'
    tld_filter    JSON            NULL,                              -- ['com','net','io',...]
    modules_filter JSON           NULL,                              -- ['domain','social',...]
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalized_at  DATETIME        NULL,
    KEY idx_created (created_at)
) ENGINE=InnoDB;
```

### 4.4 `suggest_candidates`

```sql
CREATE TABLE suggest_candidates (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id    CHAR(36)        NOT NULL,
    name          VARCHAR(63)     NOT NULL,
    iteration     INT UNSIGNED    NOT NULL DEFAULT 1,
    kept          TINYINT(1)      NOT NULL DEFAULT 0,                -- 1 = cleared all filters, 0 = failed something
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_session (session_id),
    KEY idx_name (name),
    CONSTRAINT fk_suggest_session FOREIGN KEY (session_id) REFERENCES suggest_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 4.5 `report_summaries`

The LLM-generated one-line summary at the top of a report.

```sql
CREATE TABLE report_summaries (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(63)     NOT NULL,
    summary       VARCHAR(280)    NOT NULL,                          -- tweet-length cap
    model         VARCHAR(64)     NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_name (name)
) ENGINE=InnoDB;
```

---

## 5. Core Engine Contract

`src/Engine.php` is the only class the UI, CLI, and API call. It dispatches to modules and returns a uniform `Report` object.

### 5.1 Module Interface

`src/Modules/ModuleInterface.php`:

```php
namespace NameSweep\Modules;

interface ModuleInterface
{
    /**
     * @param CheckRequest $req
     * @return CheckResult[]
     */
    public function check(CheckRequest $req): array;

    /**
     * One-word identifier. Must match the `module` ENUM in the checks table.
     */
    public function name(): string;
}
```

### 5.2 Data shapes

`CheckRequest` (immutable, value object):

```php
final class CheckRequest
{
    public function __construct(
        public readonly string $name,           // bare name, no TLD, no spaces
        public readonly array  $tlds = [],      // e.g. ['com','net','io','do','ht']; empty = use config default
        public readonly array  $modules = [],   // empty = run all enabled
        public readonly array  $markets = [],   // ['dr','ht','us']; empty = use config default
        public readonly bool   $useCache = true,
        public readonly ?string $platform = null  // for single-platform social calls
    ) {}
}
```

`CheckResult` (the uniform shape referenced in the source spec — every module must return this shape):

```php
final class CheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $tld,
        public readonly string $status,         // available|registered|for_sale|parked|uncertain
        public readonly string $source,         // provider that produced this
        public readonly string $module,         // domain|marketplace|social|trademark
        public readonly string $platform,       // 'rdap' | 'whois' | 'robotdomainsearch' | 'twitter' | ...
        public readonly ?string $detail = null,
        public readonly ?float $price = null,
        public readonly ?string $url = null,
        public readonly string $checkedAt,      // ISO 8601
        public readonly bool   $fromCache = false
    ) {}
}
```

### 5.3 Engine entry point

```php
final class Engine
{
    public function __construct(
        private readonly array $modules,             // injected, order = execution order
        private readonly CheckRepository $repo,
        private readonly Logger $logger
    ) {}

    /**
     * @return array<string, CheckResult[]>  keyed by module name
     */
    public function check(CheckRequest $req): array;

    /**
     * Convenience for the API: returns the array as JSON-ready structure.
     */
    public function checkAsArray(CheckRequest $req): array;
}
```

Behavior contract:
- For each requested module, Engine calls `$module->check($req)`.
- If `$req->useCache` is true, Engine first calls `$repo->findFresh($name, $tld, $module, $platform, $ttl)`. A fresh cached row short-circuits the provider call and returns with `fromCache=true`.
- All four modules are independent — Engine does not stop on failure. A throwing provider is caught, logged at WARN, and the result for that single `(name, tld, platform)` is returned as `status=uncertain, detail=<exception class + message>`.
- Engine never returns partial shapes. Always `CheckResult[]`, even if empty.
- Engine is the only place that writes to the `checks` table.

---

## 6. Module Specifications

### 6.1 DomainModule

**Goal:** Given a name and a list of TLDs, return registration status for each TLD.

**Providers used (in order):**
1. `RdapClient` for any TLD listed in `config/tlds.php` as `type=rdap` AND present in the IANA bootstrap.
2. `WhoisClient` (port 43) for any TLD listed as `type=whois` OR for RDAP TLDs whose bootstrap entry is missing.
3. If both fail → return `status=uncertain, source=<name>, detail=<reason>`.

**RDAP bootstrap loading (`RdapBootstrap.php`):**
- On first use, fetch `https://data.iana.org/rdap/dns.json` and write to `storage/cache/rdap_bootstrap.json`.
- If the file is older than 7 days, refresh.
- If the fetch fails and the cache file exists, use the cache and log a WARN.
- If the fetch fails and no cache exists, throw — caller will fall back to WHOIS where possible.

**`config/tlds.php` shape (excerpt):**
```php
return [
    'com' => ['type' => 'rdap'],
    'net' => ['type' => 'rdap'],
    'io'  => ['type' => 'rdap'],
    'do'  => ['type' => 'whois', 'host' => 'whois.nic.do', 'port' => 43],
    'ht'  => ['type' => 'whois', 'host' => 'whois.nic.ht', 'port' => 43],
    // add more as needed
];
```

**RdapClient behavior:**
- `GET https://{base}/{name}.{tld}` with `Accept: application/rdap+json`.
- HTTP 200 → parse `status` field. If array contains `server delete prohibited` etc., still treat as `registered`. Default to `registered` on 200 unless `events` shows an explicit expiration past now.
- HTTP 404 → `status=available`.
- HTTP 429 → sleep + retry up to 3 times with exponential backoff (1s, 2s, 4s), then `uncertain`.
- Network/timeout error → throw, caller handles.

**WhoisClient behavior:**
- Open TCP connection to `host:port`, send `{name}.{tld}\r\n`, read up to 4KB or until socket close, with 10s timeout.
- Run marker matchers from `config/tlds.php` per-TLD:
  ```php
  'do' => [
      'type' => 'whois',
      'host' => 'whois.nic.do',
      'port' => 43,
      'markers' => [
          'available' => ['No match', 'NOT FOUND', 'no entries found'],
          'registered' => ['Domain Name:', 'registrar:', 'Creation Date:'],
          'parked' => [],
          'for_sale' => ['This domain is for sale', 'Buy this domain'],
      ],
  ],
  ```
- First match wins in this priority: `registered > for_sale > parked > available > uncertain`.
- No marker matched → `uncertain` with `detail` = first 200 chars of raw response (helps the owner debug).

**`.do` and `.ht` open items** (the owner will validate against real responses on the VPS — see "Open items" in the source spec):
- The `markers` arrays in `config/tlds.php` for `.do` and `.ht` MUST be left as empty arrays by default.
- Add a `bootstrap.txt` at the repo root with the literal expected response for one known-registered and one known-available domain on each of `.do` and `.ht`. The owner will fill this in after running a one-off test on the VPS and commit it back. The coding agent's job is to wire the marker matchers to read from this file when the in-config array is empty.

### 6.2 MarketplaceModule

**Goal:** For each domain the DomainModule just returned as `available`, check whether it's actually for sale on the aftermarket and at what price.

**Provider priority (`config/app.php`):**
1. `RobotDomainSearchProvider` (keyless in beta, 60 req/min limit). If 200 → use it.
2. `OpenproviderProvider` with `provider=sedo` then `provider=afternic`. Requires `OPENPROVIDER_API_KEY` and `OPENPROVIDER_API_URL` in env.
3. `ParkedPageDetector` as final backstop — fetches `http://{name}.{tld}/` (and `https://`) and looks for known parked-page signatures in the HTML: meta description containing "this domain", "buy this domain", ad-network script names (`sedoparking.com/js/`, `parkingcrew.net`, `bodis.com`), and `<title>` patterns. Brittle — return `uncertain` if ambiguous.

**`RobotDomainSearchProvider` contract:**
- Endpoint: `GET https://api.robotdomainsearch.com/availability?domain={name}.{tld}` (verify against the live API; the coding agent must confirm the exact URL and JSON shape and document the actual response in the README — do not guess).
- If `available=true` in response → return `status=available, source=robotdomainsearch`.
- If `available=false` AND `for_sale=true` AND `price != null` → return `status=for_sale, source=robotdomainsearch, price=<value>, url=<landing_page>`.
- If `available=false` AND `for_sale=false` → return `status=registered, source=robotdomainsearch`.
- Any HTTP error → throw, next provider takes over.

**`OpenproviderProvider` contract:**
- Endpoint and auth: per Openprovider docs. Auth via `Authorization: Bearer <key>` or basic auth — confirm in code and document. Use `?provider=sedo` and `?provider=afternic` query params.
- If response includes a non-null `buy_now_price` or `min_price` → return `for_sale` with the lower of the two as `price`.
- If response says registered/sold → `registered`.
- If `available=true` → `available`.

**Concurrency:** this module is allowed to fan out all TLDs in parallel using `curl_multi_*` from the same `HttpClient` wrapper. Cap concurrency at 5.

**Cache:** results cached under the `marketplace` module enum with the same TTL as domain (24h). The cache key is `(name, tld, 'marketplace', 'robotdomainsearch'|'openprovider'|'parkedpagedetector')` — different providers don't share a row.

### 6.3 SocialModule

**Goal:** For a given bare name, check whether the handle is taken on each platform defined in `config/social.php`.

**`config/social.php` shape:**
```php
return [
    'twitter'   => ['url' => 'https://x.com/{handle}', 'method' => 'http_get'],
    'instagram' => ['url' => 'https://www.instagram.com/{handle}/', 'method' => 'http_get'],
    'tiktok'    => ['url' => 'https://www.tiktok.com/@{handle}', 'method' => 'http_get'],
    'youtube'   => ['url' => 'https://www.youtube.com/@{handle}', 'method' => 'http_get'],
];
```

The coding agent should expand this as needed but never fewer than these four.

**Each provider:**
- HTTP GET to the URL, follow up to 3 redirects, 8s timeout.
- Look for a **positive signal** that the profile exists:
  - HTTP 200 AND response body contains platform-specific known markers (e.g. for X: `<meta property="og:title"` non-empty and a follower count in JSON-LD or a known data-* attribute).
  - For Instagram/TikTok/YouTube, the bare 200-vs-404 distinction is unreliable. Use a small set of marker strings the coding agent must discover and document. If unsure → return `uncertain` with `detail` containing the markers searched for.
- HTTP 404 → `available`.
- HTTP 429 / rate limit → `uncertain, detail="rate limited"`.
- Network error → `uncertain, detail=<exception>`.

**No auth required for the baseline check.** If a platform later needs auth, add it as an env var and document in README. Never hardcode tokens.

**Note on the "light auth / rate limits" caveat in the source spec:** the design is `uncertain` over guessing. This is non-negotiable. Do not return `available` when the response was 200 with no positive signal — return `uncertain` and log a WARN.

### 6.4 TrademarkModule

**Goal:** For a given bare name, check trademarks in the markets listed in `config/markets.php`.

**`config/markets.php` shape:**
```php
return [
    'dr' => ['provider' => 'onapi', 'jurisdiction' => 'Dominican Republic'],
    'ht' => ['provider' => 'hti',  'jurisdiction' => 'Haiti'],
    'us' => ['provider' => 'uspto', 'jurisdiction' => 'United States'],
];
```

**OnapiProvider (DR):**
- ONAPI (`onapi.gob.do`) — there is no clean public API as of the source spec's date. Implement as a form-scrape stub:
  - `GET https://onapi.gob.do/busqueda-de-marcas` with the search term in the query string.
  - If a 200 with a results table is found, count rows. 0 → `available`, >0 → `registered` with `detail` containing the row count and the first 200 chars of the first row's text.
  - If the page structure can't be parsed (404, login wall, captcha, error page) → `uncertain, detail="ONAPI page unparseable — manual assist required"`.
- Document this clearly in the README: results from this module in DR are best-effort. The owner will be told in the UI when manual verification is recommended.

**HtiProvider (HT):**
- Haiti has thinner online registry presence. Implement as a manual-assist stub: always return `uncertain, detail="Haiti trademark check requires manual verification — no clean API available"`. UI shows a "Mark verified manually" button that flips the row to `registered` and records a `detail` of `"manually verified by <user> at <timestamp>"`. Implementation: a `POST /api/v1/check/trademark/manual` endpoint that requires the API key and a body like `{name, jurisdiction, status, note}`.

**UsptoProvider (US):**
- USPTO TESS (`https://tmsearch.uspto.gov/`). The free TESS web search has a JSON-ish API. Coding agent must confirm the live endpoint and document it.
- 0 results → `available`. >0 results → `registered` with the first result's serial number and a snippet in `detail`.
- If parsing fails → `uncertain`.

**Build order:** the source spec puts this last. The coding agent must still implement it fully, but `TrademarkModule` constructor should accept a config flag `enabled` and skip the round-trip when `false`. Default `enabled=true` in `config/app.php`, but the CLI gets a `--no-trademark` flag and the API accepts `"trademark": false` in the request body.

---

## 7. Suggest Mode

`src/Suggest/SuggestEngine.php` is the orchestrator. It loops:

```
1. Send description + (on iteration > 1) "these were taken: [...]" to LlmClient.
2. Receive N candidate names.
3. For each candidate, run Engine::check(...) with a module filter = ['domain', 'social'].
4. Keep only those with status=available for ALL requested TLDs AND all platforms.
5. If kept >= target count, return.
6. Else, loop back to step 1 with the rejected list as additional context.
7. Hard cap: max 5 iterations. If target not met, return whatever was kept across all iterations.
```

**Defaults (overridable via config or CLI flags):**
- `count` = 10
- `tlds` = config default
- `markets` = config default
- LLM model = `ollama:llama3.1:8b` primary, OpenRouter fallback if Ollama returns empty, errors, or 3 consecutive identical outputs (model is stuck).

**LlmClient (`src/Suggest/LlmClient.php`):**
- Single class with two backends: `Ollama` and `OpenRouter`.
- Method `generate(string $systemPrompt, string $userPrompt, string $modelAlias): string`.
- `modelAlias` is `ollama:llama3.1:8b` or `openrouter:<model-id>`.
- Prompt template for candidate generation is at `config/app.php` under `prompts.suggest_user` and `prompts.suggest_system` — make them editable from the Settings page in the web UI (read on each call, not cached).
- Parse model output strictly. The model is asked to return JSON: `{"names": ["name1", "name2", ...]}`. If the response isn't valid JSON, retry once with the same prompt. If still bad, fall back to the other backend.

**Persist:**
- One row in `suggest_sessions` per call.
- One row in `suggest_candidates` per (session, name) — including rejects, with `kept=0`. `iteration` shows which loop produced it.

---

## 8. Summary Writer

`src/Util/SummaryWriter.php` — given a `Report` (the aggregated CheckResult[]), produce a single tweet-length English sentence. Example output: *"The name 'Lumen' is available on .com, .net, .io and across all major social platforms. No trademark conflicts found in the US."*

- Uses the same LlmClient.
- Prompt is short, deterministic. Set `temperature=0.2` for this call.
- Cached in `report_summaries` keyed by name. Re-generated if the underlying check results have changed since `summary.created_at`.
- Only the LLM touches this string. The rest of the report is fully deterministic.

---

## 9. REST API

Base path: `/api/v1/`. All responses are JSON. All endpoints require the `Authorization: Bearer <api-key>` header EXCEPT `/api/v1/health`.

### 9.1 Error shape

```json
{
  "error": {
    "code": "invalid_input",
    "message": "name must match [a-z0-9-]{1,63}",
    "details": { "field": "name" }
  }
}
```

Standard codes: `invalid_input`, `unauthorized`, `not_found`, `rate_limited`, `upstream_error`, `internal_error`. HTTP status codes follow: 400, 401, 404, 429, 502, 500.

### 9.2 Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET  | `/health` | `{"status":"ok","db":"ok","ollama":"ok\|down","openrouter":"ok\|down"}` |
| POST | `/check` | Run a check. Body below. |
| POST | `/check/domain` | Domain module only. |
| POST | `/check/marketplace` | Marketplace only. |
| POST | `/check/social` | Social only. |
| POST | `/check/trademark` | Trademark only. |
| POST | `/check/trademark/manual` | Manually set a trademark result (see §6.4). |
| POST | `/suggest` | Generate suggestions. |
| GET  | `/suggest/{sessionId}` | Get a session's results. |
| POST | `/summary` | Force-regenerate the one-line summary for a name. |

### 9.3 POST /check

Request:
```json
{
  "name": "lumen",
  "tlds": ["com", "net", "io", "do", "ht"],
  "modules": ["domain", "marketplace", "social", "trademark"],
  "markets": ["dr", "ht", "us"],
  "platforms": ["twitter", "instagram", "tiktok", "youtube"],
  "use_cache": true,
  "include_summary": true
}
```

All fields except `name` are optional. Defaults from `config/app.php`.

Response:
```json
{
  "name": "lumen",
  "checked_at": "2026-08-16T20:31:15Z",
  "from_cache": false,
  "summary": "The name 'Lumen' is available on .com, .net, .io and across all major social platforms. No trademark conflicts found in the US.",
  "results": {
    "domain": [
      { "name": "lumen", "tld": "com", "status": "for_sale", "source": "robotdomainsearch", "detail": null, "price": 1200.00, "url": "https://sedo.com/...", "checked_at": "2026-08-16T20:31:15Z" }
    ],
    "marketplace": [
      { "name": "lumen", "tld": "io", "status": "for_sale", "source": "openprovider", "price": 450.00, "url": "https://...", "checked_at": "..." }
    ],
    "social": [
      { "name": "lumen", "tld": "", "status": "registered", "platform": "twitter", "url": "https://x.com/lumen", "checked_at": "..." }
    ],
    "trademark": [
      { "name": "lumen", "tld": "", "status": "uncertain", "platform": "onapi", "detail": "ONAPI page unparseable — manual assist required", "checked_at": "..." }
    ]
  }
}
```

The `tld` field is always present, even when empty for non-domain modules. The `platform` field is always present, even when empty for the domain module. This is so the JSON shape is consistent.

### 9.4 POST /suggest

Request:
```json
{
  "description": "A fintech app for Haitian small businesses, focused on mobile money.",
  "count": 10,
  "tlds": ["com", "net", "io"],
  "modules": ["domain", "social"],
  "include_rejects": false
}
```

Response:
```json
{
  "session_id": "uuid-v4",
  "iterations": 3,
  "kept": [
    { "name": "kasav", "checks": { /* same shape as /check response, scoped to this name */ } }
  ],
  "rejected": []            // omitted if include_rejects=false
}
```

### 9.5 Auth (`src/Api/Auth.php`)

- Read `Authorization: Bearer <key>` header.
- sha256 the key, look up in `api_keys` where `revoked_at IS NULL`.
- If found, set `last_used_at = NOW()` (best-effort, no transaction).
- If not found → 401 `unauthorized`.
- Per-key rate limit: 60 req/min, sliding window in `RateLimiter.php` (in-memory is fine for single-user). Returns 429 `rate_limited` with a `Retry-After` header.

---

## 10. CLI

Entry: `php bin/namesweep <command> [args]`. Dispatched by `src/Cli/Command.php`. Help on `php bin/namesweep help` and `php bin/namesweep <command> --help`.

| Command | Args | Output |
|---------|------|--------|
| `check` | `<name> [--tlds=com,net,io] [--modules=domain,social] [--markets=dr,us] [--no-cache] [--json]` | Human-readable table by default; `--json` for machine. |
| `suggest` | `<description> [--count=10] [--tlds=...] [--modules=...] [--json]` | Same as API response. |
| `key:create` | `<name>` | Prints plaintext key once, then exits. |
| `key:list` | — | Table of keys. |
| `key:revoke` | `<id>` | Marks revoked. |
| `cache:clear` | `[--module=domain]` | Truncates `checks` rows for that module. |
| `health` | — | Same as `/health`. |
| `rdap:refresh` | — | Re-fetches the IANA bootstrap. |

**No interactive prompts.** The CLI is for scripting. Anything that would require a prompt in a TTY is a flag instead.

**Exit codes:** 0 on success, 1 on any module returning `uncertain` for at least one TLD/platform, 2 on user error (bad flags), 3 on internal error (DB down, etc.).

---

## 11. Web UI

Pages (rendered server-side by `src/Web/Pages/*.php` via `Layout.php`):

- `/` — Home. Form: name input, TLD checkboxes (pre-checked: com, net, io, do, ht), module checkboxes, "Check" button. On submit, POST to `/` and render the report inline. Report shows the summary line at the top, then one section per module. Color-coded rows: green=available, red=registered, amber=for_sale, grey=parked, blue=uncertain.
- `/suggest` — Form: project description textarea, count input, "Suggest" button. On submit, render the result list with one card per kept name.
- `/history` — Last 50 checks, paginated.
- `/settings` — Edit prompts, view API keys, manage TLD list.

**No client-side framework.** All JS is plain ES6 modules in `public/assets/js/`. `app.js` handles form submission via `fetch('/api/v1/check', ...)` and renders results by building DOM directly. No virtual DOM, no template engine.

**No build step.** CSS and JS are served as-is. If the owner later wants minification, that's a separate task.

**Mobile-responsive.** The CSS uses flexbox and CSS grid only. No media query framework, no Tailwind.

---

## 12. Environment & Configuration

### 12.1 `.env.example`

```dotenv
# App
APP_ENV=development
APP_DEBUG=true
APP_BASE_URL=http://localhost:8000
APP_TIMEZONE=America/Santo_Domingo

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=namesweep
DB_USER=namesweep
DB_PASS=changeme

# Cache TTLs (hours)
TTL_DOMAIN=24
TTL_MARKETPLACE=24
TTL_SOCIAL=168
TTL_TRADEMARK=720

# HTTP
HTTP_TIMEOUT_SECONDS=8
HTTP_USER_AGENT=NameSweep/1.0 (+https://example.com)

# LLM
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.1:8b
OPENROUTER_API_KEY=
OPENROUTER_MODEL=meta-llama/llama-3.1-8b-instruct:free

# Aftermarket
OPENPROVIDER_API_URL=https://api.openprovider.com/v1beta
OPENPROVIDER_API_KEY=

# RDAP
RDAP_BOOTSTRAP_URL=https://data.iana.org/rdap/dns.json
RDAP_BOOTSTRAP_CACHE=storage/cache/rdap_bootstrap.json

# Rate limiting
RATE_LIMIT_PER_MINUTE=60
```

### 12.2 `.gitignore`

```
.env
/vendor/
storage/logs/*
storage/cache/rdap_bootstrap.json
storage/tmp/*
!storage/logs/.gitkeep
!storage/cache/.gitkeep
!storage/tmp/.gitkeep
```

### 12.3 `config/app.php` excerpt

```php
return [
    'default_tlds' => ['com', 'net', 'io'],
    'default_modules' => ['domain', 'marketplace', 'social', 'trademark'],
    'default_markets' => ['us'],
    'ttl' => [
        'domain' => (int)(getenv('TTL_DOMAIN') ?: 24) * 3600,
        'marketplace' => (int)(getenv('TTL_MARKETPLACE') ?: 24) * 3600,
        'social' => (int)(getenv('TTL_SOCIAL') ?: 168) * 3600,
        'trademark' => (int)(getenv('TTL_TRADEMARK') ?: 720) * 3600,
    ],
    'prompts' => [
        'suggest_system' => 'You are a naming assistant. Return only valid JSON: {"names": [...]}',
        'suggest_user'   => "Project: {description}\nTLDs: {tlds}\nNames to avoid (already taken): {rejected}\nReturn {count} short, brandable names as JSON.",
        'summary_system' => 'You write one-sentence English summaries of name-availability reports. Max 280 chars.',
        'summary_user'   => "Name: {name}\nResults: {results_json}\nWrite one sentence summarizing availability.",
    ],
];
```

---

## 13. Setup & Run

Document this in the README. The coding agent must verify each step works end-to-end before declaring the build done.

```bash
# 1. Clone and install
cd /workspace/namesweep
composer install --no-dev

# 2. Configure
cp .env.example .env
# edit .env, set DB_PASS, OPENROUTER_API_KEY, etc.

# 3. Database
mysql -u root -p -e "CREATE DATABASE namesweep CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p namesweep < database/schema.sql

# 4. Web server (dev)
php -S 127.0.0.1:8000 -t public

# 5. CLI smoke
php bin/namesweep health
php bin/namesweep key:create "my-coding-agent"
php bin/namesweep check lumen --tlds=com,net,io --json

# 6. API smoke
curl -H "Authorization: Bearer <key>" http://127.0.0.1:8000/api/v1/health
curl -H "Authorization: Bearer <key>" -H "Content-Type: application/json" \
     -d '{"name":"lumen","tlds":["com","net","io"]}' \
     http://127.0.0.1:8000/api/v1/check
```

Production: nginx with PHP-FPM, `try_files $uri $uri/ /index.php?$query_string;`, deny all to `/src/`, `/config/`, `/storage/`, `/vendor/` (except the public assets), and the CLI is run via `php-cli` from a systemd unit or cron, never from the web.

---

## 14. Logging

`src/Util/Logger.php` writes to `storage/logs/app-YYYY-MM-DD.log`. Levels: DEBUG, INFO, WARN, ERROR. Format: `[ISO8601] [LEVEL] [module] message {context_json}`.

- INFO: every check, with `name`, `tld`, `module`, `status`, `fromCache`.
- WARN: provider threw, marker missed, `uncertain` produced.
- ERROR: DB failure, LLM unreachable on both backends, fatal.

Logs are rotated by the file system (logrotate config in `deploy/logrotate.namesweep` — the coding agent writes a basic config; the owner wires it up).

---

## 15. Acceptance Criteria

The coding agent's work is graded against these. Every item must pass.

### 15.1 Structural
- [ ] All files in §3 exist and are non-empty.
- [ ] `composer.json` matches §2 exactly. No extra packages.
- [ ] `database/schema.sql` runs cleanly on a fresh MySQL 8 instance.
- [ ] No file under `public/` references `../src/` directly — all goes through front controllers.
- [ ] No use of `eval`, no `shell_exec` with user input, no `system` calls anywhere.

### 15.2 Engine contract
- [ ] All four modules implement `ModuleInterface` and return `CheckResult[]`.
- [ ] Engine catches module exceptions, logs at WARN, returns the failing entry as `status=uncertain`.
- [ ] `fromCache=true` is set on results served from the `checks` table within TTL.
- [ ] Cache miss → fresh fetch → row written with correct `expires_at`.

### 15.3 DomainModule
- [ ] `lumen.com` returns `registered` (real lookup) within 5s.
- [ ] `definitely-not-a-real-domain-xyz123.com` returns `available`.
- [ ] RDAP 404 → `available`. RDAP 200 → `registered`. RDAP network error → `uncertain`, not crash.
- [ ] `.do` and `.ht` go through `WhoisClient`. With empty `markers` arrays, the WHOIS response is logged and `uncertain` is returned. (Marker strings get filled in by the owner later from `bootstrap.txt`.)

### 15.4 MarketplaceModule
- [ ] Calls at least one provider per TLD returned as `available` by DomainModule.
- [ ] If a `for_sale` result is found, `price` and `url` are populated.
- [ ] Provider error does not crash — next provider is tried; final fallback returns `uncertain`.

### 15.5 SocialModule
- [ ] All four platforms in `config/social.php` are checked by default.
- [ ] `lumen` on `twitter` returns `registered` (real profile) — confirm by hand and document the marker.
- [ ] 200 with no positive signal → `uncertain`, NEVER `available`.

### 15.6 TrademarkModule
- [ ] ONAPI returns `uncertain` with the exact detail string `"ONAPI page unparseable — manual assist required"` on parse failure.
- [ ] Haiti always returns `uncertain` with the manual-assist message.
- [ ] USPTO returns `available` for a name with no live TESS hit.
- [ ] `POST /check/trademark/manual` writes a row with `source='manual'` and `detail` containing the user note.

### 15.7 Suggest mode
- [ ] With Ollama running, `php bin/namesweep suggest "A fintech app for Haiti" --count=10` returns ≥1 name within 30s.
- [ ] Every returned `kept` name has a `domain` and `social` result with `status=available` for every requested TLD/platform.
- [ ] If Ollama is down and OpenRouter has no key, the CLI exits with code 3 and a clear error, not a stack trace.

### 15.8 REST API
- [ ] All endpoints in §9.2 respond with the documented shape.
- [ ] Missing/invalid `Authorization` header → 401 with the error shape from §9.1.
- [ ] Rate limit: 61st request in a minute from the same key returns 429 with `Retry-After`.
- [ ] `php bin/namesweep check lumen --tlds=com,net,io --json` and `curl /api/v1/check` with the same args return the same `results` shape (modulo `checked_at` and `fromCache`).

### 15.9 CLI
- [ ] `php bin/namesweep help` lists every command in §10.
- [ ] `php bin/namesweep key:create foo` prints the plaintext key exactly once and refuses to print it again on `key:list`.
- [ ] Exit codes match §10.

### 15.10 Web UI
- [ ] `/` renders on mobile (375px wide) without horizontal scroll.
- [ ] Submitting the home form returns a rendered report on the same page (no full reload needed if JS is on; the no-JS fallback also works).
- [ ] No JS errors in the browser console on `/`, `/suggest`, `/history`, `/settings`.
- [ ] No external CDN requests — all assets local.

### 15.11 Security
- [ ] `.env` is in `.gitignore` and the real file is never committed.
- [ ] API keys are stored only as sha256 hashes; plaintext is shown once.
- [ ] No `SELECT *` queries with user-supplied identifiers. All queries use PDO prepared statements.
- [ ] No output that includes raw user input without `htmlspecialchars` in the PHP templates.
- [ ] No CORS wildcard — `Access-Control-Allow-Origin` is either omitted (same-origin only) or set to a specific allowlist from `.env`.

---

## 16. Out of Scope

The coding agent must NOT add any of the following unless the owner explicitly asks:

- User accounts, login flows, OAuth, password reset.
- Email sending.
- Payment integration.
- Webhooks.
- GraphQL.
- A Docker setup. (If the owner wants one later, that's a separate task.)
- CI/CD config. (The owner will wire this up.)
- Test framework setup beyond a single `tests/smoke.php` script the agent can run manually. No PHPUnit, no Pest. Smoke test covers: `health`, `check lumen.com`, `check lumen --modules=trademark --markets=ht` returns `uncertain` with the Haiti message.
- A README written for the public. The repo's README is for the owner.

---

## 17. Handoff Checklist

When the coding agent is done, the owner should be able to:

1. `cd /workspace/namesweep && cp .env.example .env`, edit DB credentials.
2. `composer install --no-dev`.
3. `mysql ... < database/schema.sql`.
4. `php -S 127.0.0.1:8000 -t public`.
5. Open `http://localhost:8000`, paste a name, click Check, see a report.
6. Open a terminal, `php bin/namesweep key:create my-agent`, get a key.
7. `curl -H "Authorization: Bearer <key>" -d '{"name":"lumen"}' -H "Content-Type: application/json" http://localhost:8000/api/v1/check` and get a JSON report.
8. Hand that key to a coding agent working on the brand project; the agent calls the API, gets structured availability data, and reasons about it.
9. Run `php bin/namesweep suggest "A fintech app for Haiti" --count=10 --json` and pipe the result into the next step of the project.

If any of these nine steps fails, the build is not done.
