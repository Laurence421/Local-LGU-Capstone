# ADR-003: Database layout and cross-system data sync

**Status:** Proposed
**Date:** 2026-10-27
**Deciders:** Research Section dev team; RACS team (the main data partner); capstone adviser

## Context

**Databases this code connects to:** `lgu2local` (`config/database.php`, `connection.php`, `CAconnection.php`), `lgu_research_db` (two `connection2.php` files), `lgu2` (one inline call), and the production Hostinger DB (`data_synchronization.php`, reached directly on a public IP). Which DB a page writes to depends on which connection file it happens to include.

**Schema:** 13 hand-maintained `.sql` dumps in `config/`, with `m1_measurecreation` defined in 4 of them, `m9_similaritychecking` in 5 and `users` in 2. Endpoints also run `CREATE TABLE IF NOT EXISTS` at request time (`receive_from_lgu2.php`, `sync_endpoint.php`). There is no single source of truth for the schema.

**Three different sync mechanisms:**

1. **HTTP push between systems.** Research POSTs JSON to RACS (`racs.jampzdev.com/.../receive_from_lgu2.php`), and RACS and others POST to `receive_*` / `update_*` here. Auth is a static `api_key` in the JSON body. Three different keys are hard-coded, and one is shared by 7 endpoints. CORS is `*`.
2. **Generic table sync** (`sync_endpoint.php`, `api/sync_m9_similaritychecking.php`): `get_data` / `receive_data` for whole tables, with primary-key detection at runtime.
3. **Direct DB-to-DB copy** (`data_synchronization.php`): pull and push `users`, `audit_logs`, `document_requests`, `m9_similaritychecking`, `password_resets` and `research_inbox` between a laptop's MySQL (via an ngrok TCP tunnel, `root`, no password) and production. This copies **password hashes and reset tokens** across environments.

**Data handling:** inputs are run through `htmlspecialchars()` *before* `INSERT`, so the DB stores HTML-encoded text. That breaks search and similarity checking, and the text gets encoded twice on display.

## Decision

**Option A: one database per system, one config source, and integration only through authenticated, idempotent HTTP APIs.**

- **Within Research:** one DB, named by `.env` (`DB_NAME=lgu2local` locally, the Hostinger name in production). All code uses `db()` (ADR-001).
- **Schema:** replace the `config/*.sql` dumps with `/migrations/0001_init.sql, 0002_…`, plus a 30-line `tools/migrate.php` that records applied files in a `schema_migrations` table. No DDL in request handlers.
- **Between systems:** HTTP JSON under `/api/v1/` only. Each partner gets its **own key** from `.env`, sent in an `Authorization: Bearer` header (not the body). Each request carries an `Idempotency-Key` (or upserts on the business code, e.g. `m9_SC_Code`) so retries never duplicate data. Requests and results go to `audit_logs`. CORS is removed, since these are server-to-server calls.
- **Outbound reliability:** write the outgoing message to an `outbox` table in the same transaction as the business change. A cron job (Hostinger supports cron) sends pending rows and retries failures. This replaces sending at the moment the user clicks.
- **Retire direct DB-to-DB sync.** Local and production are separate environments. Use migrations for schema and seed scripts for test data, and never copy `users` or `password_resets` between them.
- **Store raw text and escape on output** (`htmlspecialchars` in templates only). Use prepared statements for all SQL.

## Options Considered

### Option A: DB per system + authenticated idempotent HTTP + outbox (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Medium |
| Cost | None; runs on shared hosting with cron |
| Scalability | Fine for LGU volumes (hundreds of measures per year) |
| Team familiarity | High (HTTP+JSON is already in use) |

**Pros:** Each team owns its schema, and the contracts between systems are explicit. Retries are safe, and nothing is lost if RACS is down.
**Cons:** Data is copied between systems and is only eventually consistent. Each payload contract needs to be documented.

### Option B: One shared central DB for all LGU2 systems
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low at first, high later |
| Cost | Needs a DB reachable by all 9 subdomains; on Hostinger that means a public 3306 port |
| Scalability | Poor organisationally: any team's migration can break the other eight |
| Team familiarity | High |

**Pros:** No sync code and no duplicated data, with joins across modules.
**Cons:** Tight coupling, no ownership boundaries, and a DB exposed to the internet (the risk this ADR is trying to remove). It is also hard to reconcile with RACS's Laravel migrations.

### Option C: Keep the current mix, just move secrets to `.env`
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low |
| Cost | None |
| Scalability | Unchanged |
| Team familiarity | High |

**Pros:** Smallest change.
**Cons:** Three sync paths and four DB names stay. Password hashes still get copied between environments, there are still no retries or idempotency, and the stored text is still HTML-encoded.

## Trade-off Analysis

Option B looks simpler but only moves the coupling into the schema, and on this hosting it requires an internet-facing MySQL. Option C leaves the data-integrity and security problems in place. Option A matches how the nine systems are actually organised (separate teams, separate subdomains, separate stacks) and builds on the HTTP integration that already works with RACS. Its costs are a written payload contract and an outbox table.

## Consequences

- **Easier:** Setting up a fresh environment (`migrate.php`), knowing which DB a page touches, recovering from partner downtime, and auditing what was sent and received.
- **Harder:** Changing a payload means updating the documented contract with the partner team. Developers can no longer "just pull prod" to their laptop.
- **Revisit:** If the systems ever merge into one product, reconsider a shared DB (behind a private network, not a public IP).

## Action Items

1. [ ] Rotate the production DB password. Close remote MySQL access on Hostinger, or restrict it to known IPs. Stop the ngrok tunnel and delete the credential blocks from `data_synchronization.php` and `dbInfo`.
2. [ ] Add `.env` + `.env.example` and `.gitignore` the real file. Load it in `bootstrap.php`.
3. [ ] Collapse the `config/*.sql` dumps into `migrations/0001_init.sql` (the canonical versions of `users`, `m1_measurecreation`, `m9_similaritychecking`, `m9_measure_reviews`, `research_inbox`, `audit_logs`, `password_resets`, `document_requests`) and add `tools/migrate.php`.
4. [ ] Create `/api/v1/` with one shared `require_partner('racs')` guard (Bearer key per partner, POST only, JSON only). Move each `receive_*` / `update_*` endpoint behind it. Remove `Access-Control-Allow-Origin: *`.
5. [ ] Add upsert-by-code or `Idempotency-Key` handling to every receiver.
6. [ ] Add an `outbox` table and a `tools/send_outbox.php` cron job. Route `draftmeasurestask.php`'s sends to RACS through it.
7. [ ] Remove `htmlspecialchars()` from the write paths, write a one-off script to `html_entity_decode` existing rows, and escape in templates.
8. [ ] Write `docs/api-contract.md` with every endpoint, its payload and responses. Share it with RACS.
