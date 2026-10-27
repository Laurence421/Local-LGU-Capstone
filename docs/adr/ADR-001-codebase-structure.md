# ADR-001: Codebase and module structure

**Status:** Proposed
**Date:** 2026-10-27
**Deciders:** Research Section dev team; capstone adviser

## Context

This repo is the **Research Section**, one of nine LGU2 systems (RACS, Ordinance, Minutes, Agenda, Committee, Committee Journal, Journal, Archives, Research). Each runs on its own `*.jampzdev.com` subdomain. The stack is vanilla PHP + MySQL (mysqli) on Hostinger shared hosting, with Composer used only for PHPMailer and TCPDF.

What the code looks like today:

- **Page-per-file, all concerns inline.** Each page opens its own DB connection, runs its queries, handles its POST actions and renders its HTML. `draftmeasurestask.php` is 5,707 lines, `accounts_list.php` 1,781, and `dashboard.php` 1,517.
- **Duplicated features.** There are three draft-measures pages (`legislative-research-section/draftmeasurestask.php`, `draftmeasurestask_enhanced.php`, `check/draftmeasurestask.php`), plus `dashboard.php` / `dashboard_new.php` and two diverging copies of `super_header.php`.
- **Connection sprawl.** There are 17 inline `new mysqli(DB_HOST, …)` calls, plus `connection.php` (`$lgu2_conn`), `CAconnection.php` and two `connection2.php` files (`$conn`), each pointing at a different DB name.
- **Machine endpoints mixed with pages.** JSON receivers (`receive_from_lgu2.php`, `sync_endpoint.php`, `update_measure_status.php`, …) sit next to UI pages in `contents/legislative-research-section/`, alongside about 8 test and debug scripts.
- **Stub modules.** `ordinance-resolution-tracking/*` and `session-meeting-management/*` are 0–19-line placeholders. Their real functionality lives in the *other* LGU2 systems.
- **Two layouts.** 12 pages use `includes/header.php` and 12 use `includes/super_header.php` (see ADR-004).

Constraints: shared hosting (no long-running processes, no Docker), a student team on a capstone timeline, PHP-only skills, and RACS already on Laravel.

## Decision

**Option A, incremental consolidation inside vanilla PHP.** Add one shared bootstrap, separate machine endpoints from pages, and delete duplicates and stubs. A framework is not adopted now. Option B (front controller) stays open for later because the bootstrap is the first step toward it.

Target layout:

```
/bootstrap.php          session, config (.env), db(), auth helpers, CSRF, error handling
/config/                config loader only — no secrets in git
/includes/layout/       one header/sidebar/footer (see ADR-004)
/pages/research/…       UI pages: thin — load data, render
/api/v1/…               JSON endpoints for other LGU2 systems (ADR-003)
/src/                   plain PHP functions/classes: Measures, Users, Sync, Audit
/migrations/            numbered .sql files (ADR-003)
/tools/                 dev-only scripts, never deployed
```

## Options Considered

### Option A: Incremental consolidation (vanilla PHP)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low |
| Cost | None; works on current hosting |
| Scalability | Enough for one LGU office (tens of users) |
| Team familiarity | High |

**Pros:** Can be done one file at a time with no big-bang rewrite. Deleting duplicates and test files shrinks the attack surface immediately. Each step can be shipped on its own.
**Cons:** Routing is still the file system, and people need discipline not to put SQL back into pages. No built-in CSRF or validation.

### Option B: Front controller + light MVC (no framework)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Medium |
| Cost | None; needs `.htaccess` rewrite (supported on Hostinger) |
| Scalability | Same as A |
| Team familiarity | Medium |

**Pros:** One entry point gives one place for auth, CSRF and logging. URLs are cleaner, and controllers are testable.
**Cons:** Every page's URL and every link changes, and the SSO receiver URLs that the other 8 systems call would change too. It also means writing a mini-framework by hand.

### Option C: Migrate to Laravel (match RACS)
| Dimension | Assessment |
|-----------|------------|
| Complexity | High |
| Cost | High (rewrite of about 28k lines) |
| Scalability | Good |
| Team familiarity | Low–Medium (RACS team has some) |

**Pros:** Auth, CSRF, migrations, validation and queues come built in, and the stack would be the same as RACS.
**Cons:** A full rewrite in the middle of the project, with feature freeze. The deploy pipeline would need `composer install` and `artisan` on the server.

## Trade-off Analysis

The biggest problems (secrets in code, unauthenticated endpoints, duplicated 5k-line pages, connection sprawl) are not caused by having no framework. They are caused by having no shared entry code. A shared `bootstrap.php` fixes most of them at roughly 5% of Option C's cost. Option B adds clean routing but breaks every URL the other LGU2 systems depend on, for little extra gain at this team size. If the project continues after the capstone, a move from A to C can be done one module at a time.

## Consequences

- **Easier:** Changing the DB host or credentials in one place, adding CSRF and auth to every page, finding "the" draft-measures page, and onboarding new members.
- **Harder:** Nothing important. Some files move, so update `.htaccess` and any hard-coded paths.
- **Revisit:** Option B or C if the system outlives the capstone or the module count grows.

## Action Items

1. [ ] Create `bootstrap.php`: `session_start` with secure cookie flags, `.env` loading, a single `db()` returning one mysqli connection (`utf8mb4`, `+08:00`), `require_login($roles)`, `csrf_token()` and `csrf_check()`.
2. [ ] Replace all 17 inline `new mysqli(...)` calls and the four `connection*.php` files with `db()`.
3. [ ] Pick the canonical draft-measures page, merge the needed differences in, and delete the other two. Do the same for `dashboard_new.php` and the duplicate `super_header.php`.
4. [ ] Move JSON receivers to `/api/v1/` (ADR-003) and test and debug scripts to `/tools/`. Exclude `/tools/` from deploy.
5. [ ] Delete or hide the stub `ordinance-resolution-tracking` and `session-meeting-management` pages, and link to the owning LGU2 system instead.
6. [ ] Split `draftmeasurestask.php`: move SQL and actions into `src/Measures.php` and keep the page to rendering.
