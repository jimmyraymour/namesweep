# NameSweep — Build Plan

> **Status:** M1 ✅ + M2 ✅ complete — M3 (Domain checks E2E) next · **Design:** hybrid (see §1) · **Tracking:** Meridian project `namesweep`
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
  known-available domain on each TLD; **you** fill it in after a one-off test on the VPS, and I
  wire the matcher to read it.

### 6.2 Marketplace — 3-tier aftermarket
1. **RobotDomainSearch** (keyless beta) — I verify the live endpoint/JSON and document it.
2. **Openprovider** (`provider=sedo` then `afternic`) — needs `OPENPROVIDER_API_KEY` (optional).
3. **ParkedPageDetector** — fetches the site, looks for parking signatures; `uncertain` if ambiguous.
- Runs only for TLDs the Domain module returned `available`; fan-out via `curl_multi_*` (cap 5).

### 6.3 Social — X, Instagram, TikTok, YouTube
- 404 → available. 200 with a positive signal → registered.
- 200 with **no** positive signal → `uncertain` (never guess "available").
- 429/rate-limit → uncertain. No auth for the baseline check.

### 6.4 Trademark — DR / HT / US
- **ONAPI (DR):** best-effort form scrape — 0 rows → available, >0 → registered, unparseable →
  `uncertain` ("ONAPI page unparseable — manual assist required").
- **Haiti:** no clean public API → always `uncertain` + manual "Mark verified" button
  (`POST /api/v1/check/trademark/manual`).
- **USPTO:** I verify the live TESS endpoint and document it; 0 hits → available, >0 → registered.
- Module has an `enabled` flag; CLI `--no-trademark`, API `"trademark": false`.

---

## 7. Suggest mode (LLM loop)

- Loop: prompt LLM → get N names → run `Engine::check` (domain + social) → keep only fully
  available → repeat with rejected list as context. Max 5 iterations.
- `LlmClient`: Ollama primary, OpenRouter fallback (empty result, error, or 3× identical output).
- Strict JSON parse `{"names": [...]}`; retry once, then switch backend.
- Persists every generated name (kept or rejected) per session.
- Prompts live in `config/app.php` and are editable from the Settings page.

## 8. Summary writer

One tweet-length English sentence per report, LLM-generated (temp 0.2), cached in
`report_summaries`, regenerated when results change.

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

## 11. Web UI

`/` (check + report) · `/suggest` · `/history` · `/settings`. Server-rendered, color-coded
rows (green/red/amber/grey/blue), mobile-responsive, all assets local, no JS framework.

---

## 12. Build order — milestones (each reported to Meridian on completion)

| # | Milestone | Deliverable | Depends on |
|---|---|---|---|
| **M1** | **Setup & planning** ✅ | Specs reviewed, hybrid design, PLAN.md, git repo, Meridian registered | — |
| **M2** | **Foundation** ✅ | `composer.json` + autoloader, `.env(.example)`, `config/` (app, tlds, markets, social), `Env`/`Database`/`Logger`, `bootstrap.php`, `schema.sql` (applied, 5 tables on local MySQL 8), storage dirs, owner README | M1 |
| M3 | Domain checks E2E | `HttpClient`, RDAP, WHOIS, `Engine`, `DomainModule`, `CheckRepository`, CLI `check`, API `check` | M2 |
| M4 | Marketplace + Social | 3-tier aftermarket, 4 social providers, parked detector | M3 |
| M5 | Suggest + Summary | `LlmClient`, `SuggestEngine`, suggest endpoints, `report_summaries` | M3 |
| M6 | Trademark | ONAPI / HTI / USPTO + manual-verify endpoint | M3 |
| M7 | Web UI | 4 pages + assets | M3–M6 |
| M8 | Hardening | `RateLimiter`, logging polish, `tests/smoke.php`, acceptance checklist, deploy notes (nginx, logrotate) | M7 |

---

## 13. Open items — need you, or I verify live

- [ ] **`.do` / `.ht` WHOIS markers** — after your one-off VPS test (fill `bootstrap.txt`) — M3/M8
- [ ] **RobotDomainSearch** live endpoint + JSON shape — I verify and document — M4
- [ ] **USPTO** live endpoint — I verify and document — M6
- [ ] **Ollama** running (local or VPS) for Suggest mode — M5
- [ ] **`OPENROUTER_API_KEY`** — LLM fallback — M5 (optional until then)
- [ ] **`OPENPROVIDER_API_KEY`** — aftermarket tier 2 — M4 (optional)
- [ ] **Deploy** to VPS (nginx + PHP-FPM + logrotate) — you wire up per README — M8

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
