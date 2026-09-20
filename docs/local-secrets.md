# Local secrets — declared exceptions

Third-party AI provider keys are managed centrally in Bifrost (AI & Keys —
see root `CLAUDE.md`). This file lists the secrets that deliberately stay in
**namesweep** and says why. `bifrost/scripts/audit_ai.php` reads it.

## Retained

- `DB_PASS` — App-internal: this app's own MySQL account.
- `OPENPROVIDER_USERNAME` / `OPENPROVIDER_PASSWORD_HASH` — Credential bound to
  this app's own registered Openprovider vendor account (domain marketplace
  pricing/availability data). Openprovider issues no static API key: the hash
  generated in its control panel is a permanent *password*, exchanged at
  `POST /auth/login` for a short-lived bearer token. The token is cached in
  `storage/cache/openprovider_token.json` (gitignored, mode 0600) and is
  derived, not configured. Not an AI provider — out of the AI-routes
  redesign's scope (belongs to a future "Other keys" pass, design doc §Part D).
  Rotate the hash from the same control-panel screen if it leaks.
- `MERIDIAN_API_KEY` — Inter-project auth: this app's key to authenticate to
  Meridian, the portfolio tracker.
- `BIFROST_SERVICE_KEY` — Inter-project auth: this app's badge to Bifrost.

## Not here

Every AI provider credential is configured on a route in Bifrost's AI & Keys
UI and reached only through `Bifrost::ai('<job>', ...)`. If you are adding a
new AI provider key, it belongs in Bifrost, not in this app.
