# NameSweep — Build Plan

> **Status:** M1–M14 ✅ complete · **Remaining:** deployment/acceptance and optional owner inputs · **Design:** hybrid (see §1) · **Tracking:** Meridian project `namesweep`
> Sources: `docs/NameSweep-Spec.md` (primary) + `docs/NameSweep-Technical-Spec.md` (borrowed bits)

---

## 1. Design decision

**Primary blueprint: `docs/NameSweep-Spec.md` (Spec A).** It is the complete, internally
consistent spec — full directory layout, database schema, engine contract, module/provider
specs, REST API, CLI, web UI, logging, security rules, and an acceptance checklist. Following
it keeps the app easy to read, debug, and extend in vanilla PHP, which is the whole point.

**Borrowed from `docs/NameSweep-Technical-Spec.md` (Spec B) — two things only:**

1. **Bulk name checking.** The API and CLI accept a list of names
   (`"names": ["lumen","kasav"]` / `php bin/namesweep check lumen,kasav`). The engine still
   processes one name at a time internally, so this adds no complexity to the core.
2. **Response envelope.** Every API/CLI response carries a `meta` field with `timestamp`
   (and `version`). Spec A's documented shapes are kept exactly, including its error shape
   `{"error": {"code", "message", "details"}}`.

**Rejected from Spec B, with reasons:**

| Spec B idea | Why rejected |
|---|---|
| SPA dashboard (`index.html` + one big `app.js`) | Server-rendered PHP pages are far easier to read/debug in vanilla PHP; no build step, no client framework |
| Optional API auth (off by default) | You hand keys to coding agents — auth is on by default (only `/health` is open) |
| `config_overrides` table | `config/` files are simpler and versioned in git |
| Trademark as "phase-later skeleton" | Spec A's full trademark spec (DR/HT/US) is implemented |

---

## 2. Tech stack (locked)

| Layer | Choice |
|---|---|
| Language | **PHP 8.1+ (vanilla, no framework)** |
| Database | **MySQL 8.x** — db `namesweep`, charset `utf8mb4` |
| HTTP | **cURL** via a thin `HttpClient` wrapper (no Guzzle) |
| Autoloading | **Composer, PSR-4** `NameSweep\` → `src/` — zero other packages |
| Frontend | Plain ES6 modules + CSS, inline SVG, no build step, no CDN |
| LLM | cURL → **Ollama** (primary), cURL → **OpenRouter** (fallback) |
| RDAP | IANA bootstrap (`data.iana.org/rdap/dns.json`), cached 7 days |

---

## 3. Directory structure

```
namesweep/
├── public/                    # Web roots (front controllers only)
│   ├── index.php              # Web UI entry
│   ├── api.php                # REST API entry
│   ├── .htaccess              # deny direct access to src/
│   └── assets/{css,js}/       # base.css, report.css, suggest.css, app.js, suggest.js, api.js
├── src/                       # PSR-4 root (NameSweep\)
│   ├── Engine.php             # core orchestrator
│   ├── Modules/               # ModuleInterface, Domain, Marketplace, Social, Trademark
│   ├── Providers/             # Rdap/, Whois/, Marketplace/, Social/, Trademark/
│   ├── Suggest/               # SuggestEngine, LlmClient
│   ├── Storage/               # Database, CheckRepository, SuggestRepository, ApiKeyRepository
│   ├── Api/                   # Router, Auth, CheckController, SuggestController
│   ├── Cli/                   # Command.php
│   ├── Web/                   # Layout.php, Pages/{Home,Suggest,History,Settings}.php
│   └── Util/                  # HttpClient, Logger, Env, RateLimiter, SummaryWriter
├── bin/namesweep              # CLI entry: php bin/namesweep <cmd>
├── config/                    # tlds.php, markets.php, social.php, app.php
├── database/schema.sql        # CREATE TABLE statements
├── storage/{logs,cache,tmp}/  # runtime (gitignored except .gitkeep)
├── docs/                      # the two source specs
├── bootstrap.txt              # .do/.ht WHOIS expected responses (you fill in — §6.1)
├── .env.example / .env        # config (gitignored)
├── composer.json
└── README.md                  # owner-facing notes
```

Full per-file breakdown: `docs/NameSweep-Spec.md` §3.

---

## 4. Database (5 tables, MySQL 8)

| Table | Purpose |
|---|---|
| `api_keys` | REST API keys — sha256 hash only, plaintext shown once at creation |
| `checks` | Result cache, one row per `(name, tld, module, platform)`; TTL per module |
| `suggest_sessions` | One row per Suggest run (description, model, filters) |
| `suggest_candidates` | Every generated name — kept/rejected, iteration, cascade-deleted with session |
| `report_summaries` | LLM one-line summary per name (280-char cap) |

TTLs: domain 24h · marketplace 24h · social 7d · trademark 30d.
Full DDL: `docs/NameSweep-Spec.md` §4 (unchanged).

---

## 5. Core engine

- `CheckRequest` / `CheckResult` immutable value objects (Spec A §5.2).
- `ModuleInterface`: `check(CheckRequest): CheckResult[]`, `name(): string`.
- `Engine`: orchestrates modules in order, cache-aware, **never crashes** — a throwing
  provider becomes `status=uncertain` and is logged at WARN.
- All four modules are independent; one failing module never stops the others.

---

## 6. Modules & providers

### 6.1 Domain — RDAP → WHOIS fallback
- RDAP for TLDs in `config/tlds.php` + present in the IANA bootstrap; else WHOIS (port 43).
- RDAP 404 → available · 200 → registered · 429 → retry 3× backoff · network error → uncertain.
- `.do` / `.ht` use WHOIS with marker arrays in `config/tlds.php`.
  **Open item:** markers start empty → WHOIS response is logged and `uncertain` is returned.
  `bootstrap.txt` at repo root holds the expected responses for one known-registered and one
  known-available domain on each TLD; **you** fill it in from a one-off WHOIS probe, and I
  wire the matcher to read it. (Done 2026-08-17 from this machine — no particular host required.)

### 6.2 Marketplace — 3-tier aftermarket ✅ (M4)
1. **RobotDomainSearch** (keyless beta) — impl in `RobotDomainSearchProvider`; fan-out via `HttpClient::getMulti` (cap `aftermarket.concurrency`=5). **Live status (verified 2026-08-17):** `api.robotdomainsearch.com` resolves (165.227.69.224) but the API endpoint was not accepting connections (timeout on http+https). The provider throws on that, and the module correctly falls through to tiers 2–3. Endpoint is configurable (`ROBOTDOMAINSEARCH_*_URL`); re-verify from any host if it ever returns and update `.env`.
2. **Openprovider** (`provider=SEDO` then `AFTERNIC`) — impl in `OpenproviderProvider`, only wired when `OPENPROVIDER_USERNAME` + `OPENPROVIDER_PASSWORD_HASH` are set. Endpoint and auth confirmed against developer.openprovider.com on 2026-09-17: login handshake at `POST /auth/login`, check at `POST /domains/check` on `https://api.openprovider.eu/v1`. Still needs one live run to confirm the `status` enum.
3. **ParkedPageDetector** — fetches http+https, matches parked signatures (Sedo/Afternic/Bodis/ParkingCrew/AdSense + "this domain" meta) → `parked`; otherwise no signal.
- Runs only for TLDs the Domain module returned `available`; fan-out via `curl_multi_*` (cap 5).
- No tier has a definitive answer → `available` (no aftermarket/parked signal).

### 6.3 Social — X, Instagram, TikTok, YouTube ✅ (M4)
- 404 → available. 200 with a positive signal → registered.
- 200 with **no** positive signal → `uncertain` (never guess "available").
- 429/rate-limit → uncertain. No auth for the baseline check.
- Provider classifiers (`SocialModule` → per-platform `classify()`) documented in each provider file:
  X: `followersCount`/`profileImageUrlHttps`/og-title; Instagram: `edge_followed_by`/profile JSON (explicit "page isn't available" → available);
  TikTok: `uniqueId`/user JSON ("couldn't find this account" → available); YouTube: `channelMetadataRenderer`/subscriber ("page isn't available" → available).

### 6.4 Trademark — DR / HT / US ✅ (M6)
- **ONAPI (DR):** best-effort form scrape — 0 rows → available, >0 → registered, unparseable →
  `uncertain` ("ONAPI page unparseable — manual assist required"). Verified live 2026-08-17:
  the spec URL 404s and the live site only offers a Joomla site search, so the provider
  legitimately reports `uncertain` in practice (`ONAPI_URL` override for when a real search
  page appears).
- **Haiti:** no clean public API → always `uncertain` + manual "Mark verified" button
  (`POST /api/v1/check/trademark/manual`, `source='manual'`, cache-picked-up on later checks).
  Verified live 2026-08-17 (201 → re-check returns the manual row from cache).
- **USPTO:** TESS is retired; the live endpoint is
  `POST https://tmsearch.uspto.gov/prod-v1-0-0/tmsearch` (ES-style body; `WM.value` lowercase
  — uppercase silently returns 0 hits; count in `hits.totalValue`; `_source` in, `source` out;
  no auth; AWS WAF may 403). Verified live 2026-08-17: `lumen` → 408 registered, `zxqkw` → 0
  available. Documented in README.
- Module has an `enabled` flag (config `trademark.enabled`, default true); CLI `--no-trademark`,
  API `"trademark": false`. Default market `us` (`default_markets`). TTL 720 h.

---

## 7. Suggest mode (LLM loop) ✅ (M5)

- Loop: prompt LLM → get N names → run `Engine::check` (domain + social) → keep only fully
  available → repeat with rejected list as context. Max 5 iterations (config
  `suggest.max_iterations`).
- `LlmClient`: Ollama primary, OpenRouter fallback (empty result, error, or 3× identical output).
  Strict JSON parse `{"names": [...]}`; retry once, then switch backend. `generate()` /
  `generateJson()` return the backend alias actually used so sessions record `ollama:qwen2.5:3b`.
- Persists every generated name (kept or rejected) per session (`suggest_sessions` +
  `suggest_candidates`, session finalized on completion). Verified live 2026-08-17: 15 candidates
  across 5 iterations, model `ollama:qwen2.5:3b` (llama3.1:8b is OOM-killed on this 8 GB box).
- Prompts live in `config/app.php` and are editable from the Settings page.

## 8. Summary writer ✅ (M5)

One tweet-length English sentence per report, LLM-generated (temp 0.2, config
`summary.temperature`), cached in `report_summaries`, regenerated when the newest check result
is newer than the cached summary. Enriched in the API/CLI layer (`SummaryWriter`), never inside
the Engine. `POST /summary` force-regenerates. On LLM failure the summary is `null` — the report
still works.

---

## 9. REST API (`/api/v1`)

- **Auth:** `Authorization: Bearer <key>` on every endpoint except `GET /health`.
  Keys stored as sha256; per-key rate limit 60/min (429 + `Retry-After`).
- **Endpoints:** `GET /health` · `POST /check` (+ `/check/domain|marketplace|social|trademark`,
  `/check/trademark/manual`) · `POST /suggest` · `GET /suggest/{sessionId}` · `POST /summary`.
- **Error shape:** `{"error":{"code","message","details"}}` → 400/401/404/429/502/500.
- Request accepts `names: [...]` (bulk, from Spec B) alongside Spec A's single `name`.

## 10. CLI (`php bin/namesweep`)

`check` · `suggest` · `key:create` · `key:list` · `key:revoke` · `cache:clear` · `health` ·
`rdap:refresh`. No interactive prompts. Exit codes: 0 ok · 1 any `uncertain` · 2 bad flags ·
3 internal error.

## 11. Web UI ✅ (M7)

`/` (check + report) · `/suggest` · `/history` · `/settings`. Server-rendered, color-coded
rows (green=available / red=registered / amber=for_sale / grey=parked / blue=uncertain),
mobile-responsive, all assets local, no JS framework. Pages POST to themselves and render
inline; `app.js`/`suggest.js` progressively enhance the same forms via `fetch` with
`X-Requested-With: XMLHttpRequest` (the server returns just the results fragment — no API key
ever reaches the browser). `SettingsPage` edits LLM prompts + enabled TLDs and writes them to
**`storage/settings.json`** (gitignored, merged into config at bootstrap — the "no
config_overrides table" decision from §1, applied as a machine-local file); API keys are shown
read-only. `HistoryPage` groups the last checks by name with status-count badges, 25/page.
**Deployment:** base-relative URLs + `.htaccess` front-controller rewrites make the app work
under a subdirectory (`/namesweep/public/`) or at the domain root; `.htaccess` also exposes
the `Authorization` header to PHP (`SetEnvIf`), which Apache otherwise strips. Verified live
2026-08-17 on both the dev server and a shared-host Apache subdir.

---

## 12. Build order — milestones (each reported to Meridian on completion)

| # | Milestone | Deliverable | Depends on |
|---|---|---|---|
| **M1** | **Setup & planning** ✅ | Specs reviewed, hybrid design, PLAN.md, git repo, Meridian registered | — |
| **M2** | **Foundation** ✅ | `composer.json` + autoloader, `.env(.example)`, `config/` (app, tlds, markets, social), `Env`/`Database`/`Logger`, `bootstrap.php`, `schema.sql` (applied, 5 tables on local MySQL 8), storage dirs, owner README | M1 |
| M3 | Domain checks E2E | `HttpClient`, RDAP, WHOIS, `Engine`, `DomainModule`, `CheckRepository`, CLI `check`, API `check` | M2 |
| M4 | Marketplace + Social | 3-tier aftermarket, 4 social providers, parked detector | M3 |
| **M5** | **Suggest + Summary** ✅ | `LlmClient`, `SuggestEngine`, suggest endpoints, `report_summaries`; verified live 2026-08-17 (CLI + API) | M3 |
| M6 | Trademark ✅ | ONAPI / HTI / USPTO + manual-verify endpoint; verified live 2026-08-17 (USPTO 408/0 hits, HT manual 201 + cache pickup, DR unparseable→uncertain) | M3 |
| **M7** | **Web UI** ✅ | 4 pages + assets; verified live 2026-08-17 (forms, AJAX fragments, settings save/reset, pagination) | M3–M6 |
| **M8** | **Hardening** ✅ | strict CLI flags (unknown flag → exit 2), API request logging (method/path/status/key/ms via shutdown hook), `tests/smoke.php` (§15 battery: 30 PASS/0 FAIL/1 SKIP live 2026-08-17 — incl. rate limit 429+Retry-After, key plaintext-once, CLI/API shape parity, manual-verify round trip, no-eval/no-CORS/no-CDN), `deploy/nginx.namesweep.conf` + `deploy/logrotate.namesweep`, README Deploy section, acceptance run recorded | M7 |

---

## 13. Open items — need you, or I verify live

- [x] **`.do` / `.ht` WHOIS markers** — done 2026-08-17 without a VPS session: `.do` markers filled in `bootstrap.txt` from live probes (`No Object Found` / `Creation Date:`, verified google.do→registered, zzqqxwvxyz.do→available); `.ht` switched to RDAP — whois.nic.ht is IPv6-only with port 43 closed from our networks, but rdap.nic.ht is in the IANA bootstrap and answers (verified google.ht→registered, zzqqxwvxyz.ht→available). The spec's ".do/.ht via WHOIS" acceptance is met via WHOIS for `.do` and RDAP (the registry's own protocol) for `.ht` — deviation documented in config/tlds.php — M3/M8
- [x] **RobotDomainSearch live endpoint + JSON shape** — verified 2026-08-17: **down** (api.robotdomainsearch.com resolves but the endpoint would not accept connections). Implementation falls through to tiers 2–3 per the spec; `ROBOTDOMAINSEARCH_*_URL` config is ready for the real endpoint once confirmed — M4
- [x] **Openprovider** exact aftermarket endpoint + auth — confirmed 2026-09-17 from the published OpenAPI reference; implemented with the login/token handshake — M4
- [x] **USPTO** live endpoint — verified + documented 2026-08-17: `POST https://tmsearch.uspto.gov/prod-v1-0-0/tmsearch` — M6
- [x] **Ollama** running (local or VPS) for Suggest mode — verified 2026-08-17 (qwen2.5:3b; llama3.1:8b OOM-killed on the 8 GB box) — M5
- [ ] **`OPENROUTER_API_KEY`** — LLM fallback — M5 (optional until then)
- [ ] **`OPENPROVIDER_USERNAME` + `OPENPROVIDER_PASSWORD_HASH`** — aftermarket tier 2 — M4 (optional)
- [x] **Deploy artifacts** — `deploy/nginx.namesweep.conf` + `deploy/logrotate.namesweep` written and documented in README — M8

---

## 14. Out of scope (not building)

User accounts/login, email, payments, webhooks, GraphQL, Docker, CI/CD, PHPUnit/Pest
(single `tests/smoke.php` only), public-facing README.

## 15. Meridian tracking

Project `namesweep` is registered with a `dev` tracker. The tracker is driven by **plan
items**, not reports:

- **Plan items** (the source of truth for progress): posted via `POST /api/items`
  (`X-MC-Key` header). Tree = **phase → subphase**. Statuses: `pending`, `in_progress`,
  `partial`, `done`, `deferred`, `blocked`, `external`. Meridian computes progress % from
  item statuses (done = 1, partial = 0.5, deferred/external excluded).
- **Session reports** (`POST /api/reports`) are reserved for milestones and urgent items
  (blockers, `requires_human`), not routine progress.
- As each phase is worked, flip its items to `in_progress`, then `done` on completion, so
  the dashboard reflects live progress. `requires_human=true` whenever waiting on the owner
  (e.g. `.do`/`.ht` markers).

---

# Phase 2 — Report engine (M9–M14)

> **Added 2026-08-19** after owner review of the M1–M8 build. Phase 1 implemented
> `docs/NameSweep-Spec.md` faithfully; this phase covers the requirements that spec
> never contained (pricing, budget, alternatives, open-ended suffixes) plus two
> genuine Phase-1 gaps (CLI bulk, blind aftermarket).

## P2.0 Review findings

| # | Owner observation | Verdict | Root cause |
|---|---|---|---|
| 1 | Suffixes must not be limited to a fixed list | **Broken** | `config/tlds.php` is a closed 5-entry allowlist; `DomainModule::check()` returns `uncertain: "Unsupported TLD 'x'"` for anything else. Reproduced live: `.org` → uncertain. |
| 2 | Return alternative suffixes, not one yes/no | **Missing** | Not in either spec. |
| 3 | Budget field, results never silently filtered | **Missing** | Not in either spec. |
| 4 | Full pricing per domain | **Missing** | `checks.price` only ever holds an aftermarket asking price. No registration pricing anywhere. |
| 5 | Batch multiple names | **Partial** | API `names[]` works. UI is single-input. **CLI is single-name — `PLAN.md` §1 claimed `check lumen,kasav`; the `[a-z0-9-]{1,63}` guard rejects the comma. Doc bug + missing feature.** |
| 6 | Suggestions report-style | **Partial** | `SuggestEngine` carries full per-name checks, but only returns names available on *every* requested TLD (a filter, not a report); `SuggestPage` renders status tags only. |
| 7 | Improve the UI | **Thin** | 271 lines of CSS total; server-rendered tables. |
| 8 | Callable by API/CLI by an AI model | **Works** | Bearer-key REST + `--json` CLI, shape parity verified in `tests/smoke.php`. Strongest part of the build. |

### Critical: the aftermarket tier is blind

§6.2 is marked ✅ but all three tiers are non-functional in practice:
RobotDomainSearch's endpoint is dead (verified 2026-08-17), Openprovider was never
wired (no key), leaving only `ParkedPageDetector`'s HTML fingerprinting. The tool
therefore reports RDAP `available` with almost nothing verifying the domain isn't
parked or listed — **the exact failure the owner built this to catch.** Top priority.

### Owner decisions (2026-08-19)

- **Aftermarket:** keyless signals as the always-on baseline **and** Openprovider as an optional key-gated upgrade.
- **Agent access:** sharpen REST + CLI. **No MCP server** this phase.
- **Pricing:** Porkbun keyless public API + a hand-maintained override table.
- **UI:** matrix grid (rows = names, columns = suffixes, price + status per cell).

---

## M9 — Universal suffix resolution ✅ (2026-08-19)

Removes the closed allowlist. Verified achievable before planning: the cached IANA
bootstrap already covers **1200 TLDs**, and `whois.iana.org:43` returns the
authoritative WHOIS host for any delegated TLD (tested `co me io tv do ai` — all resolved).

- Replace the `isset($this->tldConfig[$tld])` gate in `DomainModule` with a
  `SuffixResolver` chain:
  1. `config/tlds.php` — explicit overrides and hand-verified markers (`.do`, `.io`, `.ht`) win
  2. IANA RDAP bootstrap → `RdapClient`
  3. **New** `IanaWhoisDirectory` — `whois.iana.org:43` → `whois: <host>`, cached in
     `storage/cache/whois_servers.json` (30-day TTL) → `WhoisClient`
  4. Genuinely undelegated → `uncertain: "suffix not delegated"` (a correct answer, not a gap)
- **Multi-label suffixes** (`com.do`, `co.uk`): relax input validation to
  `^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9-]+)*$`, discover the server from the
  **last** label, query the **full** FQDN. `checks.tld` is `VARCHAR(64)` — no migration needed.
- **Generic WHOIS markers** in `config/tlds.php` under a `_default` key
  (`No match`, `NOT FOUND`, `No Data Found`, `no entries found`, `Status: free`,
  `not registered`); per-TLD arrays still override.
- Settings page gains a free-text "extra suffixes" field alongside the checkboxes.
- **Done when:** `.org .ai .co .xyz .app .com.do` all return `available`/`registered`, never `uncertain`.

**Delivered.** New `SuffixResolver` + `IanaWhoisDirectory`; `DomainModule` no longer consults
an allowlist. Generic `_default` markers live in `config/tlds.php`, verified live 2026-08-19
against registered + available responses for `.co .me .io .do .com.do .tv .ai .us .nl`
(17/17 correct) and an availability sweep of `.com .org .ai .co .xyz .com.do .do .ht .io .me
.tv .us` (12/12 correct). `.co.uk` resolves through the parent registry's RDAP. Free-text
suffix boxes added to the check form and Settings. Smoke battery extended with four M9
assertions. Two things worth recording beyond the plan as written:

- **Available-first marker priority for the generic set.** `.do` and `.com.do` answer an
  unregistered domain with `Domain Name: foo.do / Domain Status: No Object Found`, so the
  generic `Domain Name:` marker would have reported every available `.do` as registered.
  Per-suffix sets keep the spec's registered-first order; only `_default` is inverted.
- **`uncertain` is no longer cached** (`Engine` will not write it, `CheckRepository` will not
  read it). Found while testing: the stale `"Unsupported TLD 'org'"` row from before the fix
  was still being served, which is the general failure mode — a momentary rate limit or
  network blip became the cached answer for a full TTL. Spec B §6 called for this; Spec A
  did not, and the build had followed Spec A.

## M10 — Restore aftermarket truth ✅ (2026-08-19)

Both tiers, per the owner decision.

- **New `DnsSignalProvider`** (keyless, always on) — the reliable signal the HTML
  detector misses. Resolve NS/A/MX via `dns_get_record()`:
  - NS matching known parking/brokerage operators (Sedo, Afternic/`dns*.afternic.com`,
    Bodis, ParkingCrew, DAN, Undeveloped, Above.com, Namefind) → `parked`/`for_sale`
  - NS present but no MX and A → a known parking IP range → `parked`
  - No NS at all on a domain RDAP called registered → `registered`, dormant
  - Operator list in a new `config/parking.php` so it is editable without code changes
- **RDAP registrar identity** — RDAP 200 responses already fetched carry the registrar
  entity. Registrar ∈ {Afternic, Sedo, HugeDomains, DAN} is a strong for-sale signal at
  zero extra HTTP cost. Parse it in `RdapClient` and pass through.
- Rewire `MarketplaceModule` tier order: DNS signals → RDAP registrar → Openprovider
  (if the Openprovider credentials are set) → RobotDomainSearch (if its URL is ever revived) →
  `ParkedPageDetector`. Retire RobotDomainSearch from tier 1; keep the class, demote it.
- Fix the double-run: `MarketplaceModule::check()` currently re-invokes
  `DomainModule::check()` internally, which doubles every RDAP call under `--no-cache`.
  Have `Engine` pass the domain results down instead.
- **Done when:** a known HugeDomains/Afternic-parked domain reports `for_sale`/`parked`, not `available`.

**Delivered.** New `DnsSignalProvider` + `config/parking.php`; `MarketplaceModule` rewritten
around two chains; `DomainResultsAware` kills the double lookup. Validated against 20
hand-labelled domains — **20/20** (12 for-sale/parked across Afternic, DAN, NameFind,
DomainMarket, HugeDomains, Efty, Above.com, ParkingCrew; 8 live businesses as negative
controls). Four findings changed the plan as written:

- **The module only ran for `available` domains**, so it could never answer "this registered
  domain is for sale" — the question it exists for, and the reason `lumen.com` returned
  `"marketplace": []`. Now the chain is chosen by domain status: registered → DNS signals then
  page fetch; available → aftermarket APIs. `uncertain` is skipped, since there is no honest
  aftermarket answer for an unknown registration state.
- **RDAP registrar identity does not work as a for-sale signal.** The plan called it a free
  strong signal. Afternic, DAN and NameFind domains all report `GoDaddy.com, LLC` — RDAP names
  the registrar of record, not the broker. Using it would flag millions of live businesses.
  Downgraded to informational `detail` on the domain result. Tracker item marked `partial`.
- **AdSense was a parked signature** in `ParkedPageDetector` and flagged `nepal.com`, a real
  travel guide, as parked. `googlesyndication.com` appears on millions of ordinary sites.
  Removed, with a smoke assertion to stop it returning. Latent before M10 because the detector
  only ever ran on unregistered names.
- **Injected domain results leaked between requests** (a bug in this milestone's own code,
  caught by the smoke battery). Modules are singletons; a marketplace-only check after a
  registered-domain check read the stale status and reported an available name as
  `registered`. Fixed with a per-request reset in `Engine` plus a name guard in the module,
  and a regression assertion.

Also added: per-check INFO logging in `DomainModule`. Spec §14 and the README both claimed it
existed; it did not. It is what made the double lookup measurable — 6 lookups for 3 suffixes
before, 3 after, confirmed by A/B.

## M11 — Pricing layer ✅ (2026-08-19)

- **New `PricingProvider`** — `GET https://api.porkbun.com/api/json/v3/pricing/get`
  (public, keyless, verified 2026-08-19: **907 TLDs**, registration/renewal/transfer,
  multi-label entries like `com.mx` included). Cached to
  `storage/cache/tld_pricing.json`, 24h TTL, same refresh pattern as `RdapBootstrap`.
- **New `config/pricing_overrides.php`** for suffixes Porkbun does not sell (`.do`,
  `.ht`, `.com.do`) — each entry carries `price`, `currency`, `source_url`, `verified_on`
  so a stale hand-entered number is always attributable.
- **Separate the two meanings of "price."** `CheckResult` gains
  `registrationPrice`, `renewalPrice`, `currency`, `priceSource`; the existing `price`
  keeps its meaning of *aftermarket asking price* and is renamed `salePrice` in the
  JSON output (`price` retained as a deprecated alias for one release).
- **Migration** `database/migrations/002_pricing.sql` — add `registration_price`,
  `renewal_price`, `currency`, `price_source` to `checks`.
- Pricing attaches to **every** domain row regardless of status, so a registered `.com`
  still shows what it would cost if it frees up.
- **Done when:** every domain row in API/CLI/UI output carries a price or an explicit
  `null` + reason.

**Delivered.** New `TldPricing` + `config/pricing_overrides.php`; `CheckResult` gains
`registrationPrice`/`renewalPrice`/`transferPrice`/`currency`/`priceSource` and a `pricing`
block in the JSON, with `sale_price` as the new name for the aftermarket asking price and
`price` kept as a deprecated alias. Rendered in CLI, web report and API. Three deviations:

- **No `002_pricing.sql`.** A price is a property of the suffix, not of a lookup. Storing it
  on cached rows would let a 30-day trademark row carry a figure that went stale weeks ago,
  so pricing is derived fresh on every report. Tracker item marked `deferred` with this
  reasoning; a smoke assertion keeps `checks` free of price columns.
- **`.do` / `.ht` / `.com.do` ship with null prices.** Neither registry publishes a readable
  price list (nic.do has no pricing section, registro.do does not resolve), so the values are
  left empty rather than guessed. The report says `no price` plus the reason. Owner fills them
  in with `source` + `verified_on`, exactly like `bootstrap.txt`.
- **Unpriced suffixes still emit a `pricing` block** carrying the note. Silence would read as
  "free" or "not checked".

## M12 — Alternatives and budget ✅ (2026-08-19)

Shipped exactly as scoped below. Decisions made during delivery:

- **The market key is not the suffix.** `config/markets.php` now carries an explicit
  `cc_tld` per market because DR's market id is `dr` but its suffix is `.do`. Bootstrapping
  alternatives from **market keys** produced the bogus `.dr` ("not delegated") and missed
  `.do` — caught in a live run before commit. See `config/markets.php`.
- **`within_budget` is honest-or-null, never a cross-currency guess.** It compares the
  price a buyer would actually pay (asked `sale_price` if listed, else yearly `registration`)
  and only when the row's currency equals the request's currency; anything else is `null`
  ("don't know"), not `false`.
- **UI default-on means every check now includes the alternative set.** Requested rows keep
  their order and come first; each row carries `requested` / `within_budget`. The web form
  gained the toggle (default on), a budget field, and a currency select.
- **Verified live 2026-08-19:** `check lumen --tlds=com --modules=domain --alternatives
  --budget=50` → 12 domain rows (1 requested + 11 alternatives); `.ai` at $82.70 =
  `within_budget=false`, `.com` at $11.08 = `true`; row-count parity with and without a
  budget asserted in `tests/smoke.php`. Web UI fragment run and API
  `{"alternatives":true,"budget":50}` both verified against the dev server.
- `.dr` is gone from the alternative set; `.do`, `.ht`, `.us` are present via `cc_tld`.

- **`alternatives`** request flag (default on for UI, opt-in for API/CLI). When set, the
  engine also checks `config/app.php` → `alternative_tlds`
  (`com net org io co app dev xyz ai` + the configured market ccTLDs), minus what was
  already asked. Every row carries `requested: true|false` so the UI can group
  *"You asked for"* vs *"Also worth knowing"*.
- **`budget`** + `currency` — a **display and ranking input, never a filter.** Every row
  gets `within_budget: true|false|null`; the report badges and sorts by it but returns
  the complete set. Explicitly asserted in `tests/smoke.php` so no future change can
  quietly reintroduce filtering.
- Wired through all three surfaces: API `"budget": 50`, CLI `--budget=50 --alternatives`,
  UI number field.

## M13 — Batch, matrix UI, suggest report ✅ (2026-08-24)

Delivered in this session. **CLI batch:** `check name1,name2,name3` and `--names-file=path` (`-` for stdin).
  Split before the per-name regex — this is the bug behind the false claim in §1.
  Table output becomes the matrix; `--json` returns the keyed bulk shape the API
  already emits. `--csv` for spreadsheet hand-off.
- **UI rework** — matrix grid:
  - Names textarea (one per line or comma-separated), custom-suffix free-text field,
    budget input, alternatives toggle
  - Results: rows = names, columns = suffixes; each cell shows status + price, `✓` marks
    within-budget; click a row to expand full per-suffix detail (source, registrar,
    parking signal, social, trademark)
  - CSV + JSON export buttons
  - Real design pass on `base.css`/`report.css` — the grid needs horizontal scroll
    containment on mobile, sticky first column, and a legible status palette
- **Suggest becomes a report:** `SuggestEngine` returns *all* candidates with their full
  check + pricing rows and a `cleared: true|false` plus rejection reason — not just names
  that passed every filter. Same matrix rendering as the check page.

## M14 — Agent-facing sharpening ✅ (2026-08-24)

Delivered in this session. REST + CLI only, per the owner decision.

- **`verdict` block** on every `/check` response — a compact, machine-actionable summary
  an LLM can branch on without parsing the full matrix:
  `best_available`, `cheapest`, `cheapest_within_budget`, `blocked_by`
  (trademark/social/aftermarket), `recommendation`. Deterministic, not LLM-generated —
  the existing `summary` string stays as the prose layer.
- **`GET /api/v1/tlds`** and **`GET /api/v1/pricing`** so an agent can discover what is
  supported and what it costs before committing to a check.
- **README section "Calling NameSweep from a coding agent"** — copy-pasteable curl, the
  full request/response schema, and a ready-made tool-definition JSON schema to hand to
  a model.
- Extend `tests/smoke.php`: multi-label suffix, pricing present, budget never filters,
  bulk CLI parity with bulk API, `verdict` shape.

## P2.1 Sequencing

M9 first — it is small and unblocks #1 and #2. M10 next because a blind aftermarket is
the owner's core complaint. M11 before M12 (budget needs prices). M13 and M14 last.

## P2.2 Carried-over open items

- [ ] `OPENPROVIDER_USERNAME` + `OPENPROVIDER_PASSWORD_HASH` — now an explicit M10 tier-3 upgrade rather than "optional"
- [ ] `OPENROUTER_API_KEY` — LLM fallback (Ollama works locally on qwen2.5:3b)
- [x] `PLAN.md` §1 bulk-CLI claim is now implemented by M13
- [x] M10: `MarketplaceModule` double `DomainModule::check()` — fixed via `DomainResultsAware`
      injection; verified 6 → 3 lookups for 3 suffixes by A/B test.
- [x] `PLAN.md` §1 bulk-CLI claim is now implemented by M13
- [x] M10: `MarketplaceModule` double `DomainModule::check()` — fixed via `DomainResultsAware`
      injection; verified 6 → 3 lookups for 3 suffixes by A/B test.

## Session 2026-09-06 — Local TLS 404, env precedence, Windows harness fixes

- **`https://localhost/namesweep/public/` returned Apache 404 while the same URL over
  plain HTTP worked.** Root cause was never in this repo: the Causeway vhosts file
  (`C:/xampp/apache/conf/extra/httpd-vhosts.conf`) had no `*:443` vhost for `localhost`,
  so TLS requests for localhost fell through to the *first* `*:443` vhost
  (`causeway.local`, docroot `htdocs/causeway`) where `/namesweep/public` does not exist.
  Fixed by adding a `localhost` `*:443` vhost (docroot `C:/xampp/htdocs`, the shared
  Causeway cert, placed first so it is also the default TLS server). Original file kept
  next to it as `httpd-vhosts.conf.bak-namesweep-fix`. Recorded here because the fix
  lives outside the repository and would otherwise be invisible to the next agent.
- **`.env` precedence reversed** in `Env::load` and `bin/meridian`'s reader: the project's
  `.env` now wins over pre-existing process environment variables. This machine carries
  machine-wide `DB_USER`/`DB_NAME`/`DB_PASSWORD` (another project's) and a
  `MERIDIAN_API_KEY` scoped to *qaribo* — under the old precedence every CLI entry point
  failed DB auth ("Access denied for user 'root'@'localhost'") and the tracker wrapper
  answered as the wrong project. Opt back into real-env precedence by setting
  `NAMESWEEP_ENV_ALLOW_OVERRIDE=1` in the process environment (the smoke suite does this
  for the children it spawns). Note the asymmetry that hid the bug: Apache runs as
  LocalSystem and does not see the user-level machine variables, so the web app worked
  while the CLI did not.
- **Smoke harness Windows fixes** (`tests/smoke.php`): `VAR=x cmd` prefix syntax does not
  exist on Windows — proc_open silently failed to spawn the dev server and the whole
  15.8 API section skipped ("dev server never became ready"). Spawned processes now
  receive injected variables through proc_open's `$env` parameter. The "M13 matrix
  controls" assertion was rewritten to match a bare GET (the CSV/JSON export buttons
  only render inside a POST-produced report, so the old check could never pass).
