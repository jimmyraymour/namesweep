# AGENT_RULES.md — read this before doing anything

This repository is **agent-agnostic**. Claude, Codex, Antigravity, Codebuff and others have all
worked here, and more will. None of them can see another's private memory, chat history, or
scratch notes. **Everything the next agent needs must live in this repository.** If you learn
something that would change how the next agent works, write it here or in `PLAN.md` — not in your
own memory store, which nobody else can read.

---

## 1. Where the project's memory lives

Read these in order at the start of a session. Together they are the full context; you do not need
to reconstruct anything from git history.

| File | What it holds | Read it when |
|---|---|---|
| **`AGENT_RULES.md`** (this file) | House rules, the mandatory Meridian workflow | Always, first |
| **`PLAN.md`** | Milestones M1–M14, what is done, and **why decisions were made** — including plan assumptions that turned out to be wrong | Always, second |
| **`README.md`** | How the system actually works, plus every empirically verified finding (marker sets, parking operators, pricing sources) with the dates and sample sizes behind them | Before touching a module |
| **`docs/NameSweep-Spec.md`** | The primary source spec (Spec A) — the original blueprint | For original intent |
| **`docs/NameSweep-Technical-Spec.md`** | The second source spec (Spec B) — only two ideas were adopted, see `PLAN.md` §1 | Rarely |
| **`tests/smoke.php`** | The executable definition of "working". Many assertions exist specifically to stop a past bug returning | Before and after any change |
| **`bootstrap.txt`** | Hand-verified WHOIS responses for suffixes needing them | Domain module work |

`PLAN.md` is the decision log. When you make a non-obvious call — especially when you *reject* an
approach — record it there with the evidence, the way the existing entries do.

---

## 2. Meridian tracker — mandatory, and never ask permission

The operator tracks this project in **Meridian**, their portfolio dashboard. Progress bars are
computed from tracker item statuses, so **work that is not posted does not exist** as far as the
operator is concerned.

> **Do not ask whether to update the tracker.** Updating it is part of doing the work, like
> writing a commit message. You finished something → you update the item. You started something →
> you update the item. You got blocked → you update the item *and* raise an alert. The operator
> should never have to prompt you, and should never have to ask what state things are in.

Credentials live in `.env` (git-ignored). Copy them from `.env.example` and ask the operator for
the key if it is missing. The key is scoped to this project alone.

```
MERIDIAN_API_URL=https://meridian.raymour.lat/api
MERIDIAN_PROJECT_ID=namesweep
MERIDIAN_TRACKER=dev
MERIDIAN_API_KEY=<ask the operator>
```

### 2.1 Use the wrapper

`bin/meridian` wraps the whole API so a correct update is one command. It reads `.env` directly and
deliberately does **not** load the app bootstrap, so it still works when the database is down —
which is exactly when you most need to report a blocker.

```sh
php bin/meridian whoami          # confirm the key works and what it is scoped to
php bin/meridian status          # last reported phase, health, open alerts — run this FIRST
php bin/meridian items           # the full task tree
php bin/meridian items --status=in_progress
```

### 2.2 The loop you must follow

**At the start of a session**

```sh
php bin/meridian status          # what did the last agent leave behind?
php bin/meridian items           # what is already tracked?
```

**When you start a piece of work**

```sh
php bin/meridian item 2470 in_progress
```

**When you finish it — immediately, not at the end of the session**

```sh
php bin/meridian item 2470 done "One or two sentences on what was actually delivered, \
including anything surprising. Future agents read these."
```

**When you take on work that has no tracker item, create one**

```sh
php bin/meridian new "Fix suffix parsing for multi-label TLDs" \
  --parent=2469 --status=in_progress --priority=high --section=domain
```

**When you are blocked — do not wait for the end of the session**

```sh
php bin/meridian item 2474 blocked "Needs the Openprovider username + password hash from the operator"
php bin/meridian alert "[namesweep] Openprovider key needed" \
  "Tier 2 cannot be verified without a key. Tried X, got Y. Need Z." \
  --severity=warning --type=agent_blocked --by=<your-agent-name>
```

**At the end of every session — required**

```sh
php bin/meridian report \
  --by=claude-code \
  --health=green \
  --phase="Phase 13 - M12 Alternatives + budget" \
  --done="What you actually finished, concretely." \
  --next="The next concrete step." \
  --artifact="commit 0b41740" \
  --blockers="none"
```

A project that goes three days without a report is auto-flagged on the operator's dashboard.
`--artifact` is technically optional; **always send one** — it is your proof of work. Add
`--human` only when you genuinely need a decision or a credential; it opens a triage alert.

### 2.3 Choosing a status honestly

The operator's progress percentage depends on picking the right one.

| Status | Use when | Weight |
|---|---|---|
| `pending` | Not started | 0%, counted |
| `in_progress` | Being worked on right now | 0%, counted |
| `partial` | Genuinely half-done, or delivered differently than planned | 50% |
| `done` | Complete **and verified** | 100% |
| `blocked` | Cannot proceed — raise an alert too | 0%, counted |
| `deferred` | Consciously dropped or found unnecessary | excluded |
| `external` | Waiting on someone outside this project | excluded |

`blocked` drags the percentage down; `deferred` and `external` do not. Choose deliberately.

Two worked examples from this project:

- Item #2471 "RDAP registrar identity as a for-sale signal" is **`partial`**, not `done`. The
  planned signal was invalidated by evidence (see §4), and something smaller was delivered instead.
  Marking it `done` would have claimed work that did not happen.
- Item #2479 "Migration 002_pricing.sql" is **`deferred`**, not `done`. The migration turned out to
  be unnecessary. `deferred` excludes it from the percentage; `done` would have inflated it.

### 2.4 When a call fails

The API's error text names exactly what is wrong — read it before asking the operator.

| Status | Meaning | Fix |
|---|---|---|
| `401 Unauthorized` | Key missing, wrong, or revoked | Check `MERIDIAN_API_KEY` is set in the environment you are actually running in |
| `403 …scoped to project namesweep` | You sent a different `project_id` | Use `namesweep` |
| `403 master key required` | Master-only route (e.g. `POST /api/projects`) | Expected, not a bug. The project is already registered |
| `404 Item not found in this project` | Bad `item_id` | Re-read the tree: `php bin/meridian items` |
| `409 Item has children` | Deleting a parent | Delete or re-parent children first — but prefer `deferred` over deleting |
| `422 Invalid status: X` | Outside the enum | See §2.3 |
| `500` | Genuine server fault | Retry once, then raise an alert |

Every call is recorded in Meridian's audit log with your key label, endpoint, status and duration.

To re-fetch the full integration guide at any time:

```sh
php bin/meridian guide           # writes MERIDIAN_GUIDE.md
```

---

## 3. House rules for the work itself

These are not style preferences. Each one exists because ignoring it produced a real bug in this
repository.

**Verify against live data before you write the code.** Every significant M9–M11 decision came from
probing real registries and real domains first. Two plan assumptions died on contact with evidence
(§4). Sample sizes and dates belong in `README.md`.

**Never fabricate a value you could not verify.** `.do` and `.ht` registration prices are `null`
in `config/pricing_overrides.php` because neither registry publishes a readable price list. The
report says "no price" and explains why. An invented figure would be worse than a gap. Same
pattern as `bootstrap.txt` for WHOIS markers.

**`uncertain` is a real answer — never guess past it.** A 200 with no positive signal is
`uncertain`, not `available`. And `uncertain` is never cached: it records *our failure to find out*,
not the domain's state, so caching it would replay a momentary rate limit as the answer for a full
TTL.

**A false positive is worse than no answer.** `config/parking.php` deliberately excludes
`domaincontrol.com`, `cloudflare.com`, `googledomains.com` and other general-purpose nameservers.
Parked domains use them, but so do millions of live businesses. Reporting a working business as
"for sale" is the one error this tool must never make.

**Run the smoke battery before and after.** `php tests/smoke.php` — currently **52 PASS / 0 FAIL /
1 SKIP**. It takes several minutes because the checks are live. Many assertions exist purely to stop
a specific past bug returning; if one fails, you reintroduced that bug. Add assertions for
behaviour you fix.

**Update the docs in the same change.** `PLAN.md` for the decision and its reasoning, `README.md`
for how it works and what you verified. A later agent reads those instead of re-deriving.

**No new Composer dependencies.** Vanilla PHP 8.1+, PSR-4 autoloading only. This is locked by both
source specs.

**Keep secrets out of git.** `.env` and `meridian-guide-*.md` are git-ignored. Never put the
Meridian key in a committed file, a log line, or command output.

---

## 4. Assumptions that were wrong — do not repeat them

Recorded because they cost real time and are easy to repeat.

**"RDAP tells you who is selling a domain."** It does not. Afternic, DAN and NameFind domains all
report `GoDaddy.com, LLC`, because RDAP names the registrar *of record*, not the broker, and GoDaddy
is the largest registrar in the world. Read the **DNS delegation** instead. (M10)

**"Fetching the page will reveal a parked domain."** The landing pages that matter are
JavaScript-rendered. Afternic, DAN and NameFind answer a bare HTTPS request with 114 bytes —
`<script>window.onload=function(){window.location.href="/lander"}</script>` — containing no
signature at all, so the page tier reported an actively listed domain as *available*. (M10)

**"AdSense means parked."** `googlesyndication.com` runs on millions of ordinary sites. It flagged
`nepal.com`, a real travel guide, as parked. (M10)

**"Marker priority should always be registered-before-available."** Several registries echo the
query back in a not-found response: `.do` answers an *unregistered* domain with
`Domain Name: foo.do / Domain Status: No Object Found`. Generic markers therefore check
`available` first. Hand-verified per-suffix sets keep the original order. (M9)

**"Rate-limit detection can run first."** `.co` ships "Access to the Whois and RDAP services is
rate limited." as boilerplate *inside every successful response*. Rate-limit checks run only after
all markers miss. (M9)

**"Modules are stateless."** They are long-lived singletons. Injected domain results leaked into the
next request and made an available name report as `registered`. Anything injected must be reset
per request. (M10)

**A documented behaviour is not necessarily an implemented one.** Spec §14 and `README.md` both
described per-check INFO logging that did not exist. `PLAN.md` §1 claimed CLI bulk checking that
was never implemented — the name regex rejects the comma. Verify before relying on a claim in the
docs, including this one.

---

## 5. Current state

- **Done:** M1–M14. M12 was reconciled with the tracker, and M13/M14 were implemented and
  verified in this session. The working tree contains the uncommitted implementation; do not
  push without being asked.
- **Next:** optional provider credentials and owner-supplied pricing for `.do`/`.ht`/`.com.do`.
  Where this runs (local or a host) is the owner's call, not tracked work.
- **Needs the operator:** `.do`/`.ht`/`.com.do` registration prices (item #2477) and
  the Openprovider username + password hash (#2474, optional).
  The Meridian client is working again as of 2026-09-18. It had been pointed at
  `http://localhost/raymour/meridian/api`, which still answers but is not canonical;
  it now uses the vhost `https://meridian.raymour.lat/api`, and `whoami` confirms
  the key is correctly scoped to `namesweep`.
- `main` is ahead of `origin` and has not been pushed. Do not push without being asked.

Full detail in `PLAN.md`.
