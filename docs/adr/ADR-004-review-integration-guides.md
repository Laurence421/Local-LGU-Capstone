# ADR-004: Review of the Super Admin UI and SSO integration guides

**Status:** Proposed
**Date:** 2026-10-27
**Deciders:** Research Section dev team; capstone adviser
**Reviewed:** `INTEGRATION_GUIDE.md`, `SUPER_ADMIN_UI_INTEGRATION_SUMMARY.md`, `super_admin_uitheme/SSO_SETUP_GUIDE.md`, `super_admin_uitheme/README.md`

## Context

`INTEGRATION_GUIDE.md` tells developers to move each page onto the Super Admin UI by **copying a full `<head>` block** into it: Montserrat, Bootstrap 5.3.8, Font Awesome 6.5.0, Unicons 4.0.0, `super_styles.css` and `system-lock.css`. Each page then includes `super_header.php` and `super_sidebar.php` and adds the JS at the bottom. `SUPER_ADMIN_UI_INTEGRATION_SUMMARY.md` says "all major pages" are integrated. `SSO_SETUP_GUIDE.md` asks the other 8 teams to accept `admin` and `research` roles on SSO, in addition to `superadmin`.

## Findings

### What works
- A single theme, with tokens in `super_styles.css`, light and dark mode, and a role-filtered sidebar.
- The guides are clear and step-by-step, with a live demo page (`super_admin_demo.php`).
- `SSO_SETUP_GUIDE.md` states plainly that SSO does not raise a user's role.

### Issues

| # | Issue | Evidence | Impact |
|---|-------|----------|--------|
| 1 | **Copy-paste layout.** Every page carries its own copy of the head/CSS/JS block. | Guide steps 1–3 | Any library bump or new stylesheet means editing every page, and the copies drift. Two copies of `super_header.php` already differ (`includes/` vs `super_admin_uitheme/`). |
| 2 | **Migration is about half done, not "complete".** | 12 pages still include the old `includes/header.php` (e.g. `profile.php`, `research_dashboard.php`, all three `records-and-correspondence/*` pages). 12 use `super_header.php`. | Users see two different UIs, and the summary misleads readers. |
| 3 | **The guide adds `session_start()` before `auth.php`**, which also starts the session. | Guide "NEW WAY" | Harmless thanks to the guard in `auth.php`, but people copy it as if it were required. Pages should include one bootstrap file instead (ADR-001). |
| 4 | **Third-party CSS/JS without Subresource Integrity**, and an extra icon set (Unicons) loaded alongside Font Awesome. | Guide step 1 | Supply-chain exposure and extra page weight. |
| 5 | **The SSO guide asks other teams to widen access** (`admin`, `research` → SSO) without a shared role definition. | `SSO_SETUP_GUIDE.md` RACS section | `research` is a staff role here, but every receiving system would treat it as admin-equivalent. See ADR-002, item 5. |
| 6 | **The SSO guide spreads the shared secret.** Its setup steps hand the same secret to every team. | `SSO_SETUP_GUIDE.md`, `super_sso.php` | See ADR-002, item 2. |
| 7 | **"System Lock" is a front-end overlay** (`system-lock.js`). | Guide §3 | The session stays live underneath, so this is not a security control. It must not be described as one. |

## Decision

Accept the Super Admin UI as **the** layout, but replace the copy-paste integration with **one shared layout partial**, and finish the migration through it:

```php
<?php require __DIR__ . '/../../bootstrap.php'; require_login(['admin','research']);
$pageTitle = 'Draft Measures';
include LAYOUT . '/start.php';   // doctype, <head>, all CSS (with SRI), header, sidebar
?>
  … page content …
<?php include LAYOUT . '/end.php'; // JS (with SRI), </body></html>
```

Rewrite the SSO guide around the contract in ADR-002 (per-system keys, `aud`, email identity, role map) instead of "accept more roles".

## Options Considered

### Option A: Keep the per-page copy-paste guide
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low |
| Cost | Grows with every page and every library change |
| Scalability | Poor |
| Team familiarity | High |

**Pros:** Nothing to build.
**Cons:** Drift has already happened (issues 1 and 2).

### Option B: Shared `layout/start.php` + `layout/end.php` (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Low |
| Cost | About a day to build, then about 10 minutes per page |
| Scalability | Good |
| Team familiarity | High (plain `include`) |

**Pros:** One place to change the theme, libraries and SRI hashes. Pages shrink, and the old header can be deleted.
**Cons:** Pages that build their own `<head>` need light refactoring.

### Option C: Template engine (Twig/Plates)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Medium |
| Cost | Composer dependency; every page rewritten |
| Scalability | Good |
| Team familiarity | Low |

**Pros:** Template inheritance and auto-escaping.
**Cons:** Too much change for the gain at this stage. Revisit together with ADR-001 Option C.

## Trade-off Analysis

B solves the real problem (drift and duplication) using plain PHP the team already knows. C adds auto-escaping, which matters given the escaping issues in ADR-003, but costs a full rewrite of the views. Escaping in templates can be enforced by review for now.

## Consequences

- **Easier:** Theme and library changes, consistent UI across all pages, and deleting `includes/header.php` and the duplicate `super_header.php`.
- **Harder:** Nothing important.
- **Revisit:** Template engine if the system moves to Laravel (Blade).

## Action Items

1. [ ] Build `includes/layout/start.php` and `end.php` from `super_admin_demo.php`, with pinned versions and SRI attributes. Drop Unicons if Font Awesome covers the icons.
2. [ ] Pick one `super_header.php` and delete the other copy.
3. [ ] Migrate the 12 pages still on `includes/header.php`, then delete it.
4. [ ] Replace `INTEGRATION_GUIDE.md` steps 1–3 with the two-include snippet above, and correct `SUPER_ADMIN_UI_INTEGRATION_SUMMARY.md`.
5. [ ] Rewrite `SSO_SETUP_GUIDE.md` against `docs/sso-contract.md` (ADR-002) and remove the secret from it.
6. [ ] Relabel "System Lock" as a privacy screen in the docs, or back it with a server-side lock flag that `auth.php` checks.
