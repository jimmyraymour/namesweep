# NameSweep

NameSweep is a **name-clearance engine**. Given a candidate brand name it reports whether
the matching domains, social handles, marketplace listings, and trademarks are taken,
available, for-sale, parked, or uncertain. It is vanilla PHP + MySQL with no framework —
built so it can be read, debugged, and extended line by line.

> This README is for the owner (you). It is not a marketing page.

---

## Requirements

- PHP **8.1+** (tested on 8.3) with `curl`, `pdo_mysql`, `json`, `mbstring`
- MySQL **8.x**
- Composer (autoloader only — no other packages)
- Optional: Ollama (local LLM for Suggest mode) and/or an OpenRouter API key

## Setup (development)

```bash
# 1. Install the autoloader (PSR-4: NameSweep\ → src/)
composer install

# 2. Configure
cp .env.example .env
# edit .env: DB_PASS, and optionally OPENROUTER_API_KEY / OPENPROVIDER_API_KEY

# 3. Database
mysql -u root -p -e "CREATE DATABASE namesweep CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p namesweep < database/schema.sql

# 4. Web server (dev)
php -S 127.0.0.1:8000 -t public
```

## Smoke test

```bash
php bin/namesweep health
php bin/namesweep key:create "my-coding-agent"
php bin/namesweep check lumen --tlds=com,net,io --json

curl -H "Authorization: Bearer <key>" http://127.0.0.1:8000/api/v1/health
curl -H "Authorization: Bearer <key>" -H "Content-Type: application/json" \
     -d '{"name":"lumen","tlds":["com","net","io"]}' \
     http://127.0.0.1:8000/api/v1/check
```

(The CLI and API land in milestone M3; until then `health` is the only command.)

## Project layout

```
public/          Web roots: index.php (UI), api.php (REST), assets/
src/             PSR-4 root (NameSweep\)
  Engine.php     Core orchestrator — the only class UI/CLI/API call
  Modules/       Domain, Marketplace, Social, Trademark (ModuleInterface)
  Providers/     RDAP, WHOIS, aftermarket, social, trademark providers
  Suggest/       SuggestEngine + LlmClient (Ollama → OpenRouter)
  Storage/       Database (PDO), Check/Suggest/ApiKey repositories
  Api/           Router, Auth, controllers
  Cli/           CLI command parser/dispatcher
  Web/           Server-rendered pages + layout
  Util/          HttpClient, Logger, Env, RateLimiter, SummaryWriter
bin/namesweep    CLI entry — `php bin/namesweep <command>`
config/          tlds.php, markets.php, social.php, app.php
database/        schema.sql (+ optional seed.sql)
storage/         logs/, cache/, tmp/ (gitignored)
bootstrap.php    Shared wiring for every entry point (see below)
docs/            The two source specs this build is based on
PLAN.md          Build plan, milestones, and open items
```

### bootstrap.php

A small addition to the spec's file list: it loads the autoloader, `.env`, and the
`config/` files, then creates the shared `Logger` and `Database`. Entry points do
`$app = require __DIR__ . '/../bootstrap.php';` instead of duplicating that wiring.
It returns an array: `['root', 'config', 'logger', 'db', 'db_error']`.

## Configuration

Everything lives in `.env` (see `.env.example`). Config files under `config/` read those
values with sensible defaults. TTLs, default TLDs/modules/markets, LLM prompts, and the
RDAP bootstrap are all configurable there.

## Logging

`src/Util/Logger.php` writes one file per day to `storage/logs/app-YYYY-MM-DD.log`:

```
[2026-08-17T03:20:00+00:00] [INFO] [domain] checked lumen.com → registered {"fromCache":false}
```

Levels: DEBUG < INFO < WARN < ERROR (set `LOG_LEVEL` in `.env`). Rotation is handled by
logrotate in production (see `deploy/logrotate.namesweep`, added in M8).

## Open items (need you)

- **`.do` / `.ht` WHOIS markers** — run a one-off WHOIS test on the VPS and fill in
  `bootstrap.txt` at the repo root (template added in M3). Until then these TLDs return
  `uncertain` with the raw response logged.
- **Ollama** — run it locally or on the VPS for Suggest mode (`OLLAMA_BASE_URL`).
- **`OPENROUTER_API_KEY`** — LLM fallback when Ollama is down.
- **`OPENPROVIDER_API_KEY`** — optional aftermarket tier (Sedo/Afternic).
- **Deploy** — nginx + PHP-FPM notes land in M8; you wire up logrotate.

## Status

Milestones tracked in `PLAN.md` and reported to the Meridian tracker (project `namesweep`).
Current: **M2 Foundation** — bootstrap, config, DB schema, core classes.
