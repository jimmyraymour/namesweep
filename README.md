# NameSweep

NameSweep is a **name-clearance engine**. Given a candidate brand name it reports whether
the matching domains, social handles, marketplace listings, and trademarks are taken,
available, for-sale, parked, or uncertain. It is vanilla PHP + MySQL with no framework —
built so it can be read, debugged, and extended line by line.

> This README is for the owner (you). It is not a marketing page.

> **Working on this repo as an AI agent? Read [`AGENT_RULES.md`](AGENT_RULES.md) first.**
> It covers the mandatory Meridian tracker workflow, where the project's memory lives, and the
> assumptions that have already been proven wrong here. This project is agent-agnostic — several
> different agents have worked on it, and none can see another's private memory.

---

## Requirements

- PHP **8.1+** (tested on 8.3) with `curl`, `pdo_mysql`, `json`, `mbstring`
- MySQL **8.x**
- Composer (autoloader only — no other packages)
- Suggest mode's LLM calls go through Bifrost (`Bifrost::ai()`, AI routes
  redesign) — no local Ollama/OpenRouter config in this app; see root
  `CLAUDE.md` and Bifrost's AI & Keys admin UI

## Setup (development)

```bash
# 1. Install the autoloader (PSR-4: NameSweep\ → src/)
composer install

# 2. Configure
cp .env.example .env
# edit .env: DB_PASS, and optionally OPENPROVIDER_USERNAME + OPENPROVIDER_PASSWORD_HASH

# 3. Database
mysql -u root -p -e "CREATE DATABASE namesweep CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p namesweep < database/schema.sql

# 4. Web server (dev) — the router script makes /api/* hit api.php
php -S 127.0.0.1:8000 -t public public/router.php

# 5. Shared-host Apache (subdirectory, e.g. /namesweep/public) — works as-is:
#    public/.htaccess rewrites /api/v1/* to api.php and everything else to
#    index.php; asset/nav URLs are base-relative, so the app also runs at the
#    domain root. Requires mod_rewrite + mod_setenvif (default on XAMPP).
```

## Smoke test

```bash
php bin/namesweep health
php bin/namesweep key:create "my-coding-agent"
php bin/namesweep check lumen --tlds=com,net,io,org,com.do --json

curl -H "Authorization: Bearer <key>" http://127.0.0.1:8000/api/v1/health
curl -H "Authorization: Bearer <key>" -H "Content-Type: application/json" \
     -d '{"name":"lumen","tlds":["com","net","io","org","com.do"]}' \
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
  Suggest/       SuggestEngine — calls Bifrost::ai('suggest', ...)
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

## Suffixes (M9)

**Any delegated suffix is checkable.** There is no allowlist. `config/tlds.php` holds only
overrides — hand-verified WHOIS marker sets and pinned behaviour — and `SuffixResolver`
works out the rest:

1. **`config/tlds.php`** — an explicit entry wins outright.
2. **IANA RDAP bootstrap** (`data.iana.org/rdap/dns.json`, ~1200 TLDs, cached 7 days). A
   multi-label suffix is looked up whole first, then by its last label, since a registry
   serves its entire namespace — this is how `.co.uk` resolves via `rdap.nominet.uk`.
3. **IANA WHOIS directory** — `whois.iana.org:43` names the authoritative WHOIS host for
   every remaining delegated TLD (`.io`, `.co`, `.me`, `.do`, ...). Cached 30 days in
   `storage/cache/whois_servers.json`, negative answers included.
4. **Nothing** — the suffix is not in the root zone. Reported as `uncertain` with
   `"Suffix '.x' is not delegated"`, which is a real answer rather than a coverage gap.

Multi-label suffixes (`com.do`, `co.uk`) are first-class everywhere: API, CLI `--tlds=`, the
check form's free-text box, and the Settings page.

### Generic WHOIS markers

A suffix discovered through the IANA directory has no hand-written marker set, so it uses
`_default` in `config/tlds.php`. That set checks **`available` before `registered`**, the
reverse of the per-suffix sets, because several registries echo the query back in a
not-found response — `.do` answers an unregistered domain with
`Domain Name: foo.do / Domain Status: No Object Found`, which the generic `Domain Name:`
marker would otherwise read as registered.

Rate-limit detection runs **only after every marker misses**: `.co` serves
`"Access to the Whois and RDAP services is rate limited."` as boilerplate inside every
successful response, so checking it first would break that TLD entirely.

Verified 2026-08-19 against live registered + available responses for `.co .me .io .do
.com.do .tv .ai .us .nl` — 17/17 classified correctly, plus a live availability sweep of
`.com .org .ai .co .xyz .com.do .do .ht .io .me .tv .us` — 12/12 correct.

### Uncertain results are never cached

An `uncertain` row records our failure to determine the state — a rate limit, a network
blip, an unreachable provider — not the domain's state. Caching it would replay a momentary
failure as the answer for a full TTL (24h for domains, 30d for trademarks). `Engine` refuses
to write them and `CheckRepository::findFresh()` refuses to read them, so pre-M9 rows are
filtered out too.

## Registration pricing (M11)

Every domain row carries what the suffix costs, whatever its status — a taken `.com` still
shows what it would cost if it frees up.

**Source:** Porkbun's public pricing endpoint
(`https://api.porkbun.com/api/json/v3/pricing/get`) — no key, no account, **907 suffixes**
including multi-label ones like `com.mx` (verified 2026-08-19). Cached 24h in
`storage/cache/tld_pricing.json`; a stale table is preferred over no prices if a refresh
fails. These are one registrar's retail prices, not a market average — every row says so via
`pricing.source`.

**Two different kinds of money**, deliberately kept apart:

| Field | Meaning | Set by |
|---|---|---|
| `pricing.registration` / `.renewal` / `.transfer` | What a registrar charges per year | every domain row |
| `sale_price` (alias: `price`) | What a **seller** is asking on the aftermarket | marketplace module, listed domains only |

Conflating them would be the easiest way to mislead a reader, so the UI labels them
separately ("asking $X" vs "$Y/yr") and the CLI shows `ask $X` or `$Y/yr`.

**Suffixes Porkbun doesn't sell** (`.do`, `.ht`, `.com.do`) fall through to
`config/pricing_overrides.php`. Those entries currently have **null prices**: neither registry
publishes a machine-readable price list — `nic.do` serves no pricing section and
`registro.do` does not resolve — so the numbers are left empty rather than invented. The
report says `no price` with a note explaining why, which is useful; a fabricated figure would
not be. To fill one in, set `registration`/`renewal` with a `currency`, the `source`, and a
`verified_on` date — the date is surfaced in the report (`nic.do (as of 2026-08-19)`) so a
stale hand-entered number always says how old it is. Same pattern as `bootstrap.txt` for
WHOIS markers.

**No database migration.** The planned `002_pricing.sql` turned out to be unnecessary and was
dropped: a price is a property of the *suffix*, not of a particular lookup. Copying it onto
cached check rows would let a 30-day trademark row carry a figure that went stale weeks ago.
Prices are read fresh from the table on every report instead, and a smoke assertion keeps
`checks` free of price columns.

## Alternatives & budget (M12)

**`alternatives`** — when set, the engine checks the requested suffixes **plus**
`config/app.php` → `alternative_tlds` (`com net org io co app dev xyz ai`, merged in
`bootstrap.php` with the trademark markets' ccTLDs). Each market entry in `config/markets.php`
carries an explicit `cc_tld` — DR's market id is `dr` but its suffix is `.do`, so the market
key itself is never used as a suffix. Every row carries a `requested` flag (`true` = the
caller asked for that suffix, `false` = "also worth knowing"), so the UI can group *"You asked
for"* vs *"Also worth knowing"*. Requested suffixes keep their order and come first;
alternatives are appended, deduplicated. **Default on in the web UI, opt-in for API and CLI.**

**`budget` + `currency`** — a display and ranking input, **never a filter**. Every row carries
`within_budget`: `true`/`false` when a price is known in the budget's currency, `null`
otherwise. A missing price, a missing budget, or a currency mismatch is "don't know" — we
never do silent cross-currency conversions. The complete result set is always returned; the
smoke suite pins this so no future change can quietly reintroduce filtering. The price
compared is the one a buyer would actually pay: the seller's asking price when a domain is
listed, else the yearly registration price.

Surfaces:

| Surface | How |
|---|---|
| CLI | `--alternatives --budget=50 --currency=USD` (table rows show `✓ within budget` / `(alternative)`) |
| API | `{"alternatives": true, "budget": 50, "currency": "USD"}` |
| Web UI | "Also check alternative suffixes" toggle (default on) + budget number field + currency select |

## Marketplace & Social modules (M4, aftermarket rebuilt in M10)

**Marketplace** (`MarketplaceModule`) — answers "is this domain actually obtainable, and from
whom?". Which chain runs depends on what the Domain module found, because the two cases have
nothing in common.

**Registered (or parked / for_sale)** — the interesting case, and the one the module skipped
entirely before M10:

1. **`DnsSignalProvider`** (keyless, primary) — reads the delegation. Nameservers matching a
   known marketplace or broker → `for_sale`; a known ad-parking operator → `parked`; a
   registered domain with no nameservers at all → `registered`, dormant. Operator list in
   `config/parking.php`.
2. **`ParkedPageDetector`** — the page fetch, as a backstop.

**Available** — nothing owns it, so there is no delegation to read. Only an aftermarket API
can report a registry premium or reserved name:

1. **RobotDomainSearch** (keyless) — fan-out across candidate TLDs in parallel via
   `HttpClient::getMulti` (capped at `AFTERMARKET_CONCURRENCY`, default 5).
2. **Openprovider** (Sedo/Afternic) — only wired when `OPENPROVIDER_USERNAME` and
   `OPENPROVIDER_PASSWORD_HASH` are both set. Openprovider issues no static API key: the
   control-panel hash is a password exchanged at `POST /auth/login` for a short-lived
   bearer token, cached in `storage/cache/openprovider_token.json`.
3. **`ParkedPageDetector`**.

A TLD the Domain module returned as `uncertain` is skipped: there is no honest aftermarket
answer for a domain whose registration state is unknown. Results cache under the
`marketplace` module enum with the domain TTL (24h).

### Why DNS rather than page fetching

The landing pages that matter are JavaScript-rendered. Afternic, DAN and NameFind all answer
a bare HTTPS request with 114 bytes:

```html
<!DOCTYPE html><html><head><script>window.onload=function(){window.location.href="/lander"}</script></head></html>
```

There is nothing in that for a signature matcher to find, so the page tier returned "no
parked signature" and the domain was reported **available** while actively listed for sale.
The delegation says plainly who holds it, and costs one DNS query.

Measured on a 149-domain sample of randomly generated, registered 5–6 letter `.com` names
(2026-08-19): about 40% sit on aftermarket infrastructure — Afternic 20, HugeDomains/NameBright
22, Efty 7, Above.com 5, Sedo 3, ParkingCrew 1, Atom 2.

`config/parking.php` **deliberately excludes** general-purpose registrar and CDN nameservers —
`domaincontrol.com` (GoDaddy), `googledomains.com`, `cloudflare.com`,
`registrar-servers.com` (Namecheap), `name.com`, `porkbun.com`, `spaceship.net`. Parked
domains do use them, but so do millions of live businesses, and reporting a working site as
for sale is the one error this module must never make.

### Signals that were tried and rejected

- **RDAP registrar identity.** The plan expected this to be a free strong signal, since the
  RDAP response is already fetched. It is not: Afternic, DAN and NameFind domains all report
  `GoDaddy.com, LLC`, because RDAP names the registrar of record rather than the broker.
  Deciding for-sale from that field would flag millions of operating businesses. The registrar
  is now surfaced in the domain result's `detail` as useful context only.
- **AdSense as a parking signature.** `googlesyndication.com` was in
  `ParkedPageDetector::SIGNATURES` and flagged `nepal.com` — a real travel guide running
  ordinary ads — as parked. Removed 2026-08-19; parking operators are still caught by their
  own script hosts and by the DNS tier. A smoke assertion now prevents it coming back.

Validated 2026-08-19 against 20 hand-labelled domains (12 confirmed for-sale/parked across
Afternic, DAN, NameFind, DomainMarket, HugeDomains, Efty, Above.com, ParkingCrew; 8 live
businesses as negative controls) — **20/20 correct**.

> **RobotDomainSearch live status (verified 2026-08-17):** `api.robotdomainsearch.com`
> DNS-resolves (165.227.69.224) and the site responds, but the API **endpoint would not
> accept connections** (timeout on both http and https). It now serves only the `available`
> chain, where it falls through to Openprovider/parked detector as designed. The endpoint is
> configurable: `ROBOTDOMAINSEARCH_AVAILABILITY_URL` and `ROBOTDOMAINSEARCH_AFTERMARKET_URL`
> (both use `{domain}` as the placeholder). If the endpoint ever returns, set
> these and the tier goes live — no code change needed.

**Social** (`SocialModule`) — checks the platforms in `config/social.php` (X, Instagram,
TikTok, YouTube). Each GET is classified by a per-platform provider (`src/Providers/Social/`)
that follows **uncertain-over-guessing**: 404 (or an explicit not-found body) → `available`;
a positive profile marker → `registered`; a 200 with no positive signal (e.g. a login/bot
wall) → `uncertain`. The marker strings are documented in each provider file. Handles cache
for 7 days.

## Suggest & Summary modules (M5)

**Suggest** (`SuggestEngine`) — LLM loop via `Bifrost::ai('suggest', ...)` for N candidate
names → run `Engine::check` on each (domain + social) → keep only names fully `available` on
every requested TLD and platform → repeat with the rejected list as context, max 5 iterations.
Every name is persisted per session (`suggest_sessions` + `suggest_candidates`) with its
iteration and kept flag.

- `php bin/namesweep suggest "A fintech app for Haiti" --count=10`
- `POST /api/v1/suggest` `{"description": "...", "count": 10}` · `GET /api/v1/suggest/{sessionId}`
- Provider/model, and the Local Ollama → OpenRouter fallback order, are configured on the
  `namesweep` app's `suggest` job in Bifrost's AI & Keys admin UI — not in this app. A step
  that returns unparseable JSON is a failed attempt; Bifrost tries the job's next step.
- Prompts are in `config/app.php` (`prompts.*`); `SUGGEST_MAX_ITERATIONS` and
  `SUGGEST_TEMPERATURE` stay env-tunable here (app-level behaviour, not provider config).

**Summary** (`SummaryWriter`) — one tweet-length LLM sentence (temp 0.2) at the top of each
check report, cached in `report_summaries` and regenerated only when the newest check result is
newer than the cached summary. `POST /api/v1/summary` force-regenerates. Checks default to
`include_summary=true`; set `false` to skip the LLM call entirely.

## Web UI (M7)

Four server-rendered pages (`public/index.php` + `src/Web/`), no framework:

- **`/`** — check form (name, suffix checkboxes plus a free-text box for any other suffix,
  module/market checkboxes, cache toggle). Submits by POST and renders the color-coded
  report inline with the LLM summary on top.
- **`/suggest`** — project description + count + filters; renders kept names as cards with
  per-TLD/platform availability tags, plus the rejected list. A run takes a minute or two.
- **`/history`** — last checks grouped by name with status-count badges, 25 per page.
- **`/settings`** — edit the LLM prompts and the suffix list, including a free-text field for
  extra suffixes (saved to `storage/settings.json`, gitignored, merged into config at
  bootstrap), plus a read-only API key table. These boxes only choose what the check form
  offers by default — they do not limit what can be checked.

`app.js` / `suggest.js` progressively enhance the forms via `fetch` (same endpoints,
`X-Requested-With: XMLHttpRequest` → the server returns just the results fragment), so the
pages work with JS off. Colors: green=available, red=registered, amber=for_sale, grey=parked,
blue=uncertain. Dev server: `php -S 127.0.0.1:8000 -t public public/router.php`.

## Trademark module (M6)

One row per market in `config/markets.php` (`dr`→onapi, `ht`→hti, `us`→uspto). Default market
is `us` (`default_markets`); pass `markets` per request or uncheck markets in the UI. Module
can be skipped entirely: CLI `--no-trademark`, API `"trademark": false`. Cache TTL 720 h
(`TTL_TRADEMARK`).

- **USPTO (`us`)** — live, keyless. `POST https://tmsearch.uspto.gov/prod-v1-0-0/tmsearch`
  (Elasticsearch-style body, `term.WM.value` **lowercase**, `_source` requested as such but
  returned as `source`, count in `hits.totalValue`). Verified live 2026-08-17: `lumen` → 408
  records, `zxqkw` → 0. 0 hits → `available`; >0 → `registered` (count + first record's
  wordmark/serial/owner). Unparseable response → `uncertain`. Behind AWS WAF; may 403 without
  notice. Endpoint override: `USPTO_URL`.
- **ONAPI (`dr`)** — best-effort form-scrape **stub**. GETs the search URL with `?q=<name>` and
  counts a results table when one exists (0 → available, >0 → registered). As of 2026-08-17
  the spec URL 404s and the live site only offers a Joomla site search (no server-side results
  table), so in practice it reports `uncertain` ("ONAPI page unparseable — manual assist
  required"). Treat DR results as best-effort and verify manually in the UI when recommended.
  Endpoint override: `ONAPI_URL`.
- **Haiti (`ht`)** — manual-assist stub by design: always `uncertain` ("Haiti trademark check
  requires manual verification — no clean API available"). The owner flips it:
  `POST /api/v1/check/trademark/manual` `{"name","jurisdiction","status":"available|registered","note"}` →
  writes a `source='manual'` row ("manually verified by <key name> at <timestamp> — <note>")
  that later checks read back from cache.

## Deploy

Reference only — where NameSweep runs (this machine, a VPS, anywhere) is an
operational choice, not outstanding work. Assets provided in `deploy/` if you want them:

- **nginx** — `deploy/nginx.namesweep.conf` (server block; adjust `server_name`,
  `root`, and the PHP-FPM socket). Routing mirrors the `.htaccess`: `/api/v1/*` → `api.php`,
  everything else → `index.php`, real files as-is, dotfiles denied, `/assets/` cached 7d.
  The app's base-path logic handles subdirectory mounts automatically — set `root` to the
  subpath if you host under one.
- **PHP-FPM** — plain `fastcgi_pass`; nginx passes the `Authorization` header natively
  (Apache needed the `SetEnvIf` in `.htaccess`; nginx does not).
- **logrotate** — `deploy/logrotate.namesweep` → copy to `/etc/logrotate.d/namesweep`
  (daily, 14 kept, compressed; `copytruncate` because the app appends per-process).
- **Ollama** — the shared portfolio route ("Local Ollama 01") in Bifrost's AI & Keys admin UI
  points at wherever it runs; nothing to configure in this app's `.env`.
- **DB** — MySQL 8 with `database/schema.sql`; `.env` `DB_*` vars.
- Sanity after wiring: `php bin/namesweep health`, `php tests/smoke.php`,
  `curl https://<host>/api/v1/health`.

## Open items (need you)

- ~~**`.do` / `.ht` markers**~~ — **done 2026-08-17**: `.do` WHOIS markers live in
  `bootstrap.txt` (verified registered + available); `.ht` is served by RDAP
  (`rdap.nic.ht`) — its WHOIS server is IPv6-only with port 43 closed from our
  networks, so RDAP is both the registry's own protocol and the working one.
- **AI routes** — the `suggest`/`summary` jobs' route + model steps are configured in
  Bifrost's AI & Keys admin UI (verified `ai: ok` in `GET /api/v1/health`), not in this app.
- **`OPENPROVIDER_USERNAME` + `OPENPROVIDER_PASSWORD_HASH`** — optional aftermarket tier
  (Sedo/Afternic), against `https://api.openprovider.eu/v1`.

## Calling NameSweep from a coding agent

Use the REST API for machine actions. Every endpoint except `/health` requires a bearer key.
Bulk checks accept either the single-name `name` field or a `names` array; single-name responses
keep the report shape, while bulk responses key the same report by name.

```bash
curl -sS -H "Authorization: Bearer $NAMESWEEP_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"names":["lumen","kasav"],"tlds":["com","org"],"modules":["domain"],"alternatives":true,"budget":50,"currency":"USD","include_summary":false}' \
  https://example.test/api/v1/check

curl -sS -H "Authorization: Bearer $NAMESWEEP_KEY" https://example.test/api/v1/tlds
curl -sS -H "Authorization: Bearer $NAMESWEEP_KEY" https://example.test/api/v1/pricing
```

A single check response has this shape:

```json
{
  "name": "lumen",
  "results": {"domain": [{"tld": "com", "status": "registered", "pricing": {"registration": 11.08}}]},
  "verdict": {
    "best_available": null,
    "cheapest": {"tld": "com", "status": "registered", "price": 11.08},
    "cheapest_within_budget": null,
    "blocked_by": ["domain"],
    "recommendation": "No domain is fully available; the cheapest priced option is .com."
  }
}
```

The deterministic `verdict` block is intended for branching by an AI agent. `summary` remains the
optional prose layer. The equivalent CLI commands are:

```bash
php bin/namesweep check lumen,kasav --tlds=com,org --modules=domain --json
php bin/namesweep check --names-file=- --tlds=com --modules=domain --csv < names.txt
```

Tool definition schema:

```json
{"name":"namesweep_check","description":"Check one or more candidate names across domains and clearance modules","parameters":{"type":"object","properties":{"name":{"type":"string"},"names":{"type":"array","items":{"type":"string"}},"tlds":{"type":"array","items":{"type":"string"}},"modules":{"type":"array","items":{"type":"string"}},"alternatives":{"type":"boolean"},"budget":{"type":"number"},"currency":{"type":"string"}},"additionalProperties":false}}
```

## Status

Milestones tracked in `PLAN.md` and reported to the Meridian tracker (project `namesweep`).
Current: **M1–M14 implemented**. M12 adds alternatives and budget (default-on in the UI, opt-in
API/CLI; budget ranks and never filters); M13 adds CLI/UI batch matrix reports and report-style
Suggest output; M14 adds deterministic verdicts and TLD/pricing discovery. Earlier phases were
verified live locally (`http://localhost/namesweep/public/`). Run `php tests/smoke.php`
for the acceptance battery (structure, CLI, engine, marketplace/social contracts, trademark,
pricing, M12-M14, REST API incl. rate limiting, suggest, and web UI).
