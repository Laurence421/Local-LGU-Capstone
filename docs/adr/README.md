# Architecture Decision Records — LGU2 Research Section

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-001](ADR-001-codebase-structure.md) | Codebase and module structure | Proposed |
| [ADR-002](ADR-002-sso-and-authentication.md) | SSO and authentication across LGU2 systems | Proposed |
| [ADR-003](ADR-003-database-layout-and-sync.md) | Database layout and cross-system data sync | Proposed |
| [ADR-004](ADR-004-review-integration-guides.md) | Review of the Super Admin UI and SSO integration guides | Proposed |

## Do these first, whatever is decided below

These are live risks in the current code. They are not trade-offs.

1. **Rotate every credential committed to the repo**, then move them out of the code (see ADR-003). Credentials are hard-coded in `dbInfo`, `contents/legislative-research-section/data_synchronization.php` (the production DB on a public IP), `sso_login.php`, `super_admin_uitheme/super_sso.php`, and every `receive_*`, `update_*`, `sync_*` and `send_*` endpoint.
2. **Stop exposing MySQL to the internet.** `data_synchronization.php` reaches a laptop's MySQL (`root`, empty password) through an ngrok TCP tunnel, and reaches the Hostinger DB directly on port 3306.
3. **Fix the SSO identity bug** (ADR-002, §Context, item 1). When a user arrives by SSO, `auth.php` reloads their profile from the *local* `users` table using the *other system's* `user_id`, so they can end up logged in as a different local user with that user's role.
4. **Fix `deploy.yml`.** `rm -rf public_html/*` deletes `uploads/` on every push, so every avatar and document uploaded on the server since the last deploy is lost. It also publishes test and debug scripts that have no login check (`test_sync.php`, `test_ajax_sync.php`, `debug_sync.php`, …).

## Implementation status (2026-10-27)

| ADR | Done | Still open |
|-----|------|------------|
| 001 | `bootstrap.php` (config, session, `db()`, `url()`, roles, CSRF, API keys); all connections from `.env`; test, debug and duplicate files deleted; stub modules removed | Moving JSON endpoints into `/api/v1/` (partners call the current URLs); splitting `draftmeasurestask.php` |
| 002 | SSO receiver checks audience, one-time use (`sso_used_tokens`), maps users by email; issuer adds `aud`/`iss`/`jti`; `sso_go.php` mints tokens at click time; secret in `.env`; OTP expiry, attempt limit, `hash_equals` | Per-system asymmetric keys and POST delivery (needs the other 8 teams) |
| 003 | One DB per system from `.env`; `migrations/` + `tools/migrate.php`; per-partner keys from `.env`, constant-time check, header or legacy body field; CORS removed; column allow-list in `sync_endpoint.php`; direct sync no longer uses ngrok or copies reset tokens | Outbox + cron; idempotency keys; storing raw text instead of HTML-encoded text |
| 004 | `includes/layout/start.php` + `end.php`; all old-header pages moved onto it; old header, footer and `style.css` deleted; every link through `url()`; sidebar dead links replaced with SSO links; guides rewritten | The 12 earlier Super Admin pages still carry their own `<head>`; SRI hashes on CDN assets |
