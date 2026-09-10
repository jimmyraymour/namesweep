# Local secrets — declared exceptions

Third-party API keys live centrally in Bifrost (`/keys`), scoped per project.
This file lists the secrets that deliberately stay in **namesweep** and says why.

`bifrost/scripts/audit_secrets.php` reads this list. Anything secret-shaped in
this project's config that is *not* listed here is reported as a STRAY finding,
so a key kept locally is a decision on the record rather than an oversight.

Three things legitimately stay local:

1. **App-internal secrets** — not a third-party identity, so there is nothing to
   centralize (database passwords, session and encryption keys).
2. **Credentials bound to one app** — tied to that app's own registered redirect
   URI or vendor account, so sharing them across projects would be wrong.
3. **Inter-project auth** — the key this app uses to authenticate to a sibling.
   Bifrost issues these as service keys; they are not provider credentials.

## Retained

- `DB_PASS` — App-internal: this app's own MySQL account.
- `MERIDIAN_API_KEY` — Inter-project auth: identifies NameSweep to Meridian.

## Not here

Every other provider credential is fetched with
`Bifrost::secret('<slot>', ...)` and granted under `/keys/projects`. If you are
adding a new third-party key, it belongs in Bifrost, not in this file.
