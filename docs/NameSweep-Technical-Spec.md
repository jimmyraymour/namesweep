# NameSweep — Technical Specification Document
## For Coding Agent — Vanilla PHP Build

---

## 1. Overview

NameSweep is a name-clearance engine that checks brand names across four modules: domain availability, marketplace/parked status, social handle availability, and business/trademark registration. It includes an AI-powered "Suggest mode" that generates and auto-clears candidate names.

**Interface modes:** CLI (primary for agent integration), REST API, Web Dashboard.

**Stack:** Vanilla PHP 8.2+, MySQL 8.0+, vanilla JavaScript (frontend), Ollama (local LLM), OpenRouter (fallback LLM).

---

## 2. Project Structure

```
/namesweep/
├── .env                          # Secrets (gitignored)
├── .env.example                  # Template with blank keys
├── config.php                    # Central config loader
├── bootstrap.php                 # Autoloader, DB connection, constants
├── database.sql                  # Full schema dump
├── cli.php                       # CLI entry point
├── api.php                       # REST API entry point
├── index.html                    # Web dashboard (SPA-style, loads JS)
├── /assets/
│   └── app.js                    # Vanilla JS dashboard
├── /src/
│   ├── /Core/
│   │   ├── Database.php          # PDO wrapper
│   │   ├── Cache.php             # MySQL-based result cache
│   │   ├── Config.php            # .env + hard defaults
│   │   ├── Response.php          # Standardized JSON/CLI output formatter
│   │   └── Logger.php            # File-based logging
│   ├── /Modules/
│   │   ├── ModuleInterface.php   # Contract all modules implement
│   │   ├── DomainModule.php      # RDAP + WHOIS checker
│   │   ├── MarketplaceModule.php # Aftermarket aggregator + parked detection
│   │   ├── SocialModule.php      # Social handle availability
│   │   └── TrademarkModule.php   # ONAPI, USPTO, etc. (phase-later)
│   ├── /Suggest/
│   │   ├── NameGenerator.php     # Ollama + OpenRouter wrapper
│   │   └── SuggestEngine.php     # Loop: generate → check → filter → retry
│   └── /API/
│       ├── Router.php            # Simple request router
│       ├── Middleware.php        # Auth, rate limiting, CORS
│       └── Endpoints/
│           ├── CheckEndpoint.php
│           ├── SuggestEndpoint.php
│           └── StatusEndpoint.php
├── /cache/                       # File cache for IANA bootstrap
│   └── rdap_servers.json
├── /logs/                        # Runtime logs
└── /tests/                       # PHPUnit or simple test runner
```

---

## 3. Database Schema (MySQL)

```sql
-- Run this first: database.sql
CREATE DATABASE IF NOT EXISTS namesweep CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE namesweep;

-- Cache for check results (prevents re-hitting registries)
CREATE TABLE check_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,              -- e.g. "mybrand"
    tld VARCHAR(64) NOT NULL,                -- e.g. ".com" or "social:twitter"
    module VARCHAR(32) NOT NULL,             -- domain | marketplace | social | trademark
    status VARCHAR(32) NOT NULL,           -- available | registered | for_sale | parked | uncertain
    source VARCHAR(255),                   -- e.g. "rdap:verisign" or "whois:nic.do"
    detail TEXT,                           -- Raw response or parsed detail
    price_known DECIMAL(12,2) DEFAULT NULL,  -- For marketplace module
    checked_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,            -- 24h for domain/marketplace, 7d for social/trademark
    INDEX idx_lookup (name, tld, module),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Suggestion sessions (tracks AI generation rounds)
CREATE TABLE suggest_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    description TEXT NOT NULL,             -- User's project description
    model_used VARCHAR(64) NOT NULL,         -- e.g. "ollama:llama3.1:8b" or "openrouter:..."
    generated_names JSON,                  -- Array of all names generated
    cleared_names JSON,                    -- Array of names that passed all checks
    rejected_names JSON,                   -- Array of names that failed + reasons
    rounds INT DEFAULT 1,                  -- How many generation loops
    created_at DATETIME DEFAULT NOW(),
    completed_at DATETIME
) ENGINE=InnoDB;

-- API keys / access control (simple token auth)
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_hash VARCHAR(64) NOT NULL,         -- sha256 of the key
    name VARCHAR(100),
    created_at DATETIME DEFAULT NOW(),
    last_used_at DATETIME,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- Configuration overrides (TLD lists, markets, etc.)
CREATE TABLE config_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(128) NOT NULL UNIQUE,
    config_value TEXT,
    updated_at DATETIME DEFAULT NOW() ON UPDATE NOW()
) ENGINE=InnoDB;
```

---

## 4. Core Contracts

### 4.1 Module Interface (ALL modules MUST implement this exactly)

```php
<?php
// src/Modules/ModuleInterface.php

interface ModuleInterface {
    /**
     * Check a single name.
     *
     * @param string $name   The brand name (e.g. "mybrand")
     * @param string $tld    Contextual TLD or platform identifier (e.g. ".com", "twitter", "onapi")
     * @return array         MUST return exactly this shape:
     *
     * [
     *   'name'          => (string) $name,
     *   'tld'           => (string) $tld,
     *   'status'        => (string) one of: 'available', 'registered', 'for_sale', 'parked', 'uncertain',
     *   'source'        => (string) e.g. 'rdap:verisign', 'whois:nic.do', 'robotdomainsearch',
     *   'detail'        => (string) human-readable or raw response snippet,
     *   'price_if_known'=> (float|null) only for marketplace module, null otherwise,
     *   'checked_at'    => (string) ISO 8601 datetime
     * ]
     */
    public function check(string $name, string $tld): array;

    /**
     * Module display name
     */
    public function getName(): string;

    /**
     * Supported TLDs / platforms this module handles
     * @return array
     */
    public function getSupportedTargets(): array;
}
```

### 4.2 Standard Response Format (ALL outputs — API, CLI, Web — use this)

```php
<?php
// src/Core/Response.php

class Response {
    public static function success(array $data, string $message = ''): array {
        return [
            'ok'      => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => ['timestamp' => date('c'), 'version' => '1.0.0']
        ];
    }

    public static function error(string $message, int $code = 400, array $context = []): array {
        return [
            'ok'      => false,
            'message' => $message,
            'code'    => $code,
            'context' => $context,
            'meta'    => ['timestamp' => date('c')]
        ];
    }

    // CLI: pretty-print to stdout
    public static function toCli(array $payload): void {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    // API: emit HTTP headers + JSON
    public static function toHttp(array $payload, int $httpCode = 200): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
```

---

## 5. Configuration (config.php + .env)

```php
<?php
// config.php — loads .env, provides defaults

require_once __DIR__ . '/vendor/autoload.php'; // if you add composer later, else skip

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

return [
    'db' => [
        'host'     => $_ENV['DB_HOST']     ?? 'localhost',
        'name'     => $_ENV['DB_NAME']     ?? 'namesweep',
        'user'     => $_ENV['DB_USER']     ?? 'namesweep',
        'pass'     => $_ENV['DB_PASS']     ?? '',
        'charset'  => 'utf8mb4'
    ],
    'ollama' => [
        'url'      => $_ENV['OLLAMA_URL']  ?? 'http://localhost:11434',
        'model'    => $_ENV['OLLAMA_MODEL'] ?? 'llama3.1:8b',
        'timeout'  => (int)($_ENV['OLLAMA_TIMEOUT'] ?? 120)
    ],
    'openrouter' => [
        'key'      => $_ENV['OPENROUTER_KEY'] ?? '',
        'model'    => $_ENV['OPENROUTER_MODEL'] ?? 'meta-llama/llama-3.1-8b-instruct',
        'url'      => 'https://openrouter.ai/api/v1/chat/completions'
    ],
    'api' => [
        'rate_limit_per_min' => (int)($_ENV['RATE_LIMIT'] ?? 60),
        'require_key'        => filter_var($_ENV['API_REQUIRE_KEY'] ?? 'false', FILTER_VALIDATE_BOOLEAN)
    ],
    'cache' => [
        'domain_ttl_hours'     => 24,
        'marketplace_ttl_hours' => 24,
        'social_ttl_hours'      => 168,   // 7 days
        'trademark_ttl_hours'   => 168
    ],
    'modules' => [
        'domain'      => ['enabled' => true,  'priority' => 1],
        'marketplace' => ['enabled' => true,  'priority' => 2],
        'social'      => ['enabled' => true,  'priority' => 3],
        'trademark'   => ['enabled' => false, 'priority' => 4] // phase-later
    ],
    'tlds' => [
        'primary'   => ['.com', '.net', '.io', '.co', '.app', '.dev'],
        'caribbean' => ['.do', '.ht', '.jm', '.ag', '.lc', '.tt'],
        'social'    => ['twitter', 'instagram', 'tiktok', 'youtube']
    ],
    'paths' => [
        'cache_dir' => __DIR__ . '/cache',
        'log_dir'   => __DIR__ . '/logs'
    ]
];
```

**.env.example:**
```
DB_HOST=localhost
DB_NAME=namesweep
DB_USER=namesweep
DB_PASS=your_mysql_password

OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=llama3.1:8b
OLLAMA_TIMEOUT=120

OPENROUTER_KEY=sk-or-v1-...
OPENROUTER_MODEL=meta-llama/llama-3.1-8b-instruct

API_REQUIRE_KEY=false
RATE_LIMIT=60
```

---

## 6. Module 1 — Domain Checker (src/Modules/DomainModule.php)

### Logic Flow
1. Check cache first (MySQL `check_cache` table).
2. If miss: fetch IANA RDAP bootstrap `data.iana.org/rdap/dns.json` → cache locally for 7 days.
3. Look up TLD in bootstrap → get RDAP base URL.
4. Query RDAP: `GET {base_url}/domain/{name}.{tld}`
5. If RDAP returns 404 → `available`. If 200 → `registered`. Other → `uncertain`.
6. If TLD not in RDAP bootstrap (ccTLD like `.do`, `.ht`) → WHOIS fallback on TCP 43.
7. Parse WHOIS raw text for markers:
   - `.do` (`whois.nic.do`): look for "No match for", "NOT FOUND", "Domain Status: free"
   - `.ht` (`whois.nic.ht`): similar free-domain markers
   - If ambiguous → `uncertain`
8. Store result in cache with 24h TTL.

### RDAP Response Handling
- HTTP 404 = domain available
- HTTP 200 with `events` containing `registration` = registered
- HTTP 200 with `notices` about redelegation = uncertain
- Any network timeout = uncertain (don't cache uncertain; retry next time)

### WHOIS TCP 43 Implementation
```php
// Pseudocode for WHOIS fallback
$socket = fsockopen("whois.nic.do", 43, $errno, $errstr, 10);
if (!$socket) return uncertain;
fwrite($socket, "$domain\r\n");
$response = stream_get_contents($socket, 4096);
fclose($socket);
// Best-effort marker matching (case-insensitive)
if (stripos($response, 'No match') !== false || stripos($response, 'NOT FOUND') !== false) {
    return available;
}
if (stripos($response, 'Domain Status:') !== false || stripos($response, 'Registrar:') !== false) {
    return registered;
}
return uncertain;
```

### Class Skeleton
```php
class DomainModule implements ModuleInterface {
    private array $config;
    private Cache $cache;
    private string $rdapBootstrapUrl = 'https://data.iana.org/rdap/dns.json';
    private string $rdapCacheFile;

    public function __construct(array $config, Cache $cache) {
        $this->config = $config;
        $this->cache = $cache;
        $this->rdapCacheFile = $config['paths']['cache_dir'] . '/rdap_servers.json';
    }

    public function check(string $name, string $tld): array { /* ... */ }
    public function getName(): string { return 'Domain'; }
    public function getSupportedTargets(): array { return $this->config['tlds']['primary'] + $this->config['tlds']['caribbean']; }

    private function loadRdapBootstrap(): array { /* fetch or read cache */ }
    private function queryRdap(string $domain, string $rdapUrl): array { /* curl */ }
    private function queryWhois(string $domain, string $server): array { /* fsockopen */ }
    private function parseWhoisResponse(string $raw, string $tld): string { /* marker matching */ }
}
```

---

## 7. Module 2 — Marketplace / Parked (src/Modules/MarketplaceModule.php)

### Logic Flow
1. Check cache first.
2. **Primary:** Query RobotDomainSearch aftermarket API (free beta, no key, 60 req/min).
   - Endpoint: `https://api.robotdomainsearch.com/v1/aftermarket?domain={name}.{tld}`
   - If response has `buy_now_price` or `min_price` → `for_sale` with price.
   - If response has `sellable: true` but no price → `for_sale` with `price_if_known: null`.
3. **Secondary:** If RobotDomainSearch miss, query Openprovider `CheckDomain` with `provider=sedo|afternic` (if you have key).
4. **Backstop:** Parked-page fingerprinting (only if aggregators return nothing):
   - HTTP GET `http://{name}.{tld}` (follow redirects, timeout 10s).
   - Check for: `<meta name="description" content="buy this domain">`, known ad-network scripts (Google AdSense `googlesyndication`, Sedo parking `sedoparking.com`), "This domain is for sale" boilerplate.
   - If detected → `parked`.
5. If all paths return nothing definitive → `available` (no aftermarket data = likely not listed).
6. Cache 24h.

### Important
- This module is the "$27k catcher" — it changes `available` → `for_sale`. Build it right after DomainModule.
- Respect rate limits. If 429 returned → mark `uncertain` and don't cache.

---

## 8. Module 3 — Social Handle (src/Modules/SocialModule.php)

### Logic Flow
1. Check cache first.
2. For each platform, construct profile URL and HEAD/GET it:
   - X/Twitter: `https://x.com/{name}` → 404 = available, 200 = registered
   - Instagram: `https://instagram.com/{name}` → 404 = available
   - TikTok: `https://tiktok.com/@{name}` → 404 = available
   - YouTube: `https://youtube.com/@{name}` → 404 = available
3. If rate-limited (403 with challenge, 429) → `uncertain`.
4. Some platforms return 200 for non-existent but with "This page doesn't exist" in body — parse body for that string.
5. Cache 7 days (social handles don't change as fast as domains).

---

## 9. Module 4 — Business / Trademark (src/Modules/TrademarkModule.php)

### Phase-Later. Skeleton only for now.

```php
class TrademarkModule implements ModuleInterface {
    public function check(string $name, string $tld): array {
        // tld maps to registry: 'onapi' = DR, 'uspto' = US, 'haiti' = manual
        return [
            'name'           => $name,
            'tld'            => $tld,
            'status'         => 'uncertain',
            'source'         => 'manual:pending',
            'detail'         => 'Trademark module not yet implemented. Check ONAPI (DR), USPTO TESS (US), or local registry manually.',
            'price_if_known' => null,
            'checked_at'     => date('c')
        ];
    }
    public function getName(): string { return 'Trademark'; }
    public function getSupportedTargets(): array { return ['onapi', 'uspto', 'haiti']; }
}
```

---

## 10. Suggest Mode (src/Suggest/)

### 10.1 NameGenerator.php

Two backends: Ollama (primary) → OpenRouter (fallback).

**Prompt template:**
```
You are a brand naming assistant. Generate {count} unique, memorable brand names for this project:

Description: {description}

Requirements:
- Each name must be 4-12 characters, easy to spell, no hyphens
- Avoid these taken names: {rejected_list}
- Prefer names that feel: {vibe}
- Return ONLY a JSON array of strings, nothing else

Example output: ["nexus", "velora", "kryptic"]
```

**Ollama call:**
```bash
curl -X POST http://localhost:11434/api/generate   -d '{"model":"llama3.1:8b","prompt":"...","stream":false,"format":"json"}'
```

**OpenRouter call:**
```bash
curl -X POST https://openrouter.ai/api/v1/chat/completions   -H "Authorization: Bearer $OPENROUTER_KEY"   -d '{"model":"meta-llama/llama-3.1-8b-instruct","messages":[{"role":"user","content":"..."}]}'
```

**Fallback logic:**
- Try Ollama first. If timeout (>120s) or response is garbled/non-JSON → log warning, try OpenRouter.
- If OpenRouter also fails → return error: "Name generation unavailable. Check Ollama status or OpenRouter credits."

### 10.2 SuggestEngine.php

```php
class SuggestEngine {
    private NameGenerator $generator;
    private array $modules; // All enabled ModuleInterface instances
    private Cache $cache;

    /**
     * Main loop
     *
     * @param string $description  User's project description
     * @param int    $target       How many cleared names to return (default 5)
     * @param int    $maxRounds    Max generation loops (default 3)
     * @return array               ['cleared' => [...], 'rejected' => [...], 'rounds' => N]
     */
    public function run(string $description, int $target = 5, int $maxRounds = 3): array {
        $cleared = [];
        $rejected = [];
        $rounds = 0;

        while (count($cleared) < $target && $rounds < $maxRounds) {
            $rounds++;
            $names = $this->generator->generate(
                description: $description,
                count: $target * 3, // overshoot because many will fail
                rejected: array_column($rejected, 'name')
            );

            foreach ($names as $name) {
                $allClear = true;
                $failures = [];

                foreach ($this->modules as $module) {
                    foreach ($module->getSupportedTargets() as $tld) {
                        $result = $module->check($name, $tld);
                        if ($result['status'] !== 'available') {
                            $allClear = false;
                            $failures[] = ['module' => $module->getName(), 'tld' => $tld, 'status' => $result['status']];
                        }
                    }
                }

                if ($allClear) {
                    $cleared[] = $name;
                } else {
                    $rejected[] = ['name' => $name, 'reasons' => $failures];
                }

                if (count($cleared) >= $target) break 2;
            }
        }

        return [
            'cleared'  => array_slice($cleared, 0, $target),
            'rejected' => $rejected,
            'rounds'   => $rounds,
            'message'  => count($cleared) >= $target
                ? "Found $target cleared names in $rounds round(s)."
                : "Only found " . count($cleared) . " cleared name(s) after $rounds round(s). Try broadening your description."
        ];
    }
}
```

---

## 11. CLI Interface (cli.php)

```bash
# Check a single name across all modules
php cli.php check --name="mybrand" --tlds=".com,.net,.io,.do"

# Check specific module only
php cli.php check --name="mybrand" --module="domain" --tlds=".com,.do"

# Suggest mode
php cli.php suggest --description="A Caribbean fintech app for remittances" --target=5

# Suggest with vibe override
php cli.php suggest --description="..." --vibe="modern,trustworthy,short" --target=10

# Clear cache for a name
php cli.php cache:clear --name="mybrand"

# Show cache entry
php cli.php cache:show --name="mybrand"

# Run database migrations
php cli.php migrate

# Health check (tests DB, Ollama, port 43 egress)
php cli.php health
```

**CLI output:** Always JSON to stdout (machine-parseable). Use `--pretty` for human-readable.

---

## 12. REST API (api.php)

### Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/v1/check` | Optional key | Check name(s) across modules |
| POST | `/api/v1/suggest` | Optional key | AI name generation + clearance |
| GET | `/api/v1/status` | None | Health check |
| GET | `/api/v1/cache/{name}` | Key | Show cached results for name |
| DELETE | `/api/v1/cache/{name}` | Key | Clear cache for name |

### Request/Response Examples

**POST /api/v1/check**
```json
// Request
{
  "names": ["mybrand", "velora"],
  "tlds": [".com", ".net", ".io", ".do"],
  "modules": ["domain", "marketplace", "social"],
  "skip_cache": false
}

// Response
{
  "ok": true,
  "message": "",
  "data": {
    "mybrand": {
      ".com": {
        "domain": { "status": "registered", "source": "rdap:verisign", "detail": "..." },
        "marketplace": { "status": "for_sale", "source": "robotdomainsearch", "price_if_known": 27000.00 },
        "social": { "twitter": { "status": "registered" }, "instagram": { "status": "available" } }
      },
      ".do": {
        "domain": { "status": "available", "source": "whois:nic.do", "detail": "No match for MYBRAND.DO." }
      }
    }
  },
  "meta": { "timestamp": "2026-08-16T20:00:00-04:00", "cached": true }
}
```

**POST /api/v1/suggest**
```json
// Request
{
  "description": "A Caribbean fintech app for remittances",
  "target": 5,
  "vibe": "modern,trustworthy",
  "tlds": [".com", ".co", ".do"]
}

// Response
{
  "ok": true,
  "data": {
    "cleared": ["remitlydo", "carifund", "velora"],
    "rejected": [
      { "name": "nexus", "reasons": [{"module":"Domain","tld":".com","status":"registered"}] }
    ],
    "rounds": 2,
    "message": "Found 3 cleared names in 2 round(s)."
  }
}
```

### Simple Router (no framework)
```php
<?php
// api.php
require 'bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Auth middleware (if enabled)
if ($config['api']['require_key']) {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!validateApiKey($key)) {
        Response::toHttp(Response::error('Invalid or missing API key', 401), 401);
    }
}

// Route
if ($uri === '/api/v1/check' && $method === 'POST') {
    $endpoint = new CheckEndpoint($modules, $cache);
    Response::toHttp($endpoint->handle($input));
} elseif ($uri === '/api/v1/suggest' && $method === 'POST') {
    $endpoint = new SuggestEndpoint($suggestEngine);
    Response::toHttp($endpoint->handle($input));
} elseif ($uri === '/api/v1/status' && $method === 'GET') {
    Response::toHttp(['ok' => true, 'data' => healthCheck()]);
} else {
    Response::toHttp(Response::error('Not found', 404), 404);
}
```

---

## 13. Web Dashboard (index.html + assets/app.js)

### Features
- Paste names → bulk check across selected TLDs/modules
- Suggest tab: textarea for description → "Generate" → see cleared names
- Results table: color-coded status (green=available, red=registered, yellow=for_sale, gray=uncertain)
- "Suggest more" button loops back to AI with rejected context
- No frameworks. Vanilla JS. Fetch API for backend calls.

### Key JS Functions
```javascript
// assets/app.js skeleton

async function checkNames(names, tlds, modules) {
    const res = await fetch('/api/v1/check', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({names, tlds, modules})
    });
    return await res.json();
}

async function suggestNames(description, target, vibe) {
    const res = await fetch('/api/v1/suggest', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({description, target, vibe})
    });
    return await res.json();
}

function renderResults(data) { /* build DOM table */ }
```

---

## 14. Error Handling & Edge Cases

| Scenario | Behavior |
|----------|----------|
| RDAP server timeout | Return `uncertain`, do NOT cache |
| WHOIS port 43 blocked | Return `uncertain`, log warning |
| Rate limited by aggregator | Return `uncertain`, do NOT cache |
| Ollama down / slow | Fallback to OpenRouter; if both fail, return error |
| Invalid JSON from LLM | Retry once with stricter prompt; if still bad, return error |
| MySQL down | CLI returns JSON error; API returns 503 |
| Empty name input | Validate: reject with 400 "Name cannot be empty" |

---

## 15. Build Order (DO NOT SKIP)

1. **Bootstrap:** `config.php`, `bootstrap.php`, `.env`, MySQL schema, `database.sql`
2. **Core:** `Database.php`, `Cache.php`, `Response.php`, `Logger.php`
3. **Module 1:** `DomainModule.php` — RDAP + WHOIS, test `.do` and `.ht` on VPS
4. **CLI:** `cli.php` with `check` command — agent can use this immediately
5. **API:** `api.php` + `/api/v1/check` endpoint
6. **Module 2:** `MarketplaceModule.php` — the $27k catcher
7. **Suggest:** `NameGenerator.php` + `SuggestEngine.php` + `cli.php suggest` + `/api/v1/suggest`
8. **Web Dashboard:** `index.html` + `assets/app.js`
9. **Module 3:** `SocialModule.php`
10. **Module 4:** `TrademarkModule.php` (skeleton only for now)

---

## 16. Testing Checklist (Validate Before Marking Done)

- [ ] `php cli.php check --name="google" --tlds=".com"` returns `registered`
- [ ] `php cli.php check --name="xyzqwerty12345fake" --tlds=".com"` returns `available`
- [ ] `.do` WHOIS returns correct status for known-registered and known-available domains
- [ ] `.ht` WHOIS returns correct status for known-registered and known-available domains
- [ ] Marketplace module catches at least one `for_sale` domain with price
- [ ] Suggest mode returns 5 cleared names in ≤3 rounds for "Caribbean fintech app"
- [ ] API returns valid JSON for all endpoints
- [ ] Dashboard renders results table without errors
- [ ] `php cli.php health` reports DB connected, Ollama reachable, port 43 open

---

## 17. Agent Integration Notes

The coding agent (or any external tool) should call NameSweep via:

**CLI (preferred for local/agent use):**
```bash
php /path/to/namesweep/cli.php check --name="mybrand" --tlds=".com,.do" --pretty
```

**API (preferred for remote/services):**
```bash
curl -X POST http://namesweep.local/api/v1/check   -H "Content-Type: application/json"   -d '{"names":["mybrand"],"tlds":[".com",".do"]}'
```

Both return the same JSON structure. The agent parses `data.*.*.status` to determine availability.
