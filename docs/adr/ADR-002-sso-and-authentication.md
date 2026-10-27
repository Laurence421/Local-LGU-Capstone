# ADR-002: SSO and authentication across LGU2 systems

**Status:** Proposed
**Date:** 2026-10-27
**Deciders:** Research Section dev team; the auth owners of the other 8 LGU2 systems (any change to the token format needs all of them to agree); capstone adviser

## Context

**Local login** (`index.php`, `auth.php`): username or email + `password_verify`, then an emailed OTP, then a session. `auth.php` handles idle timeout, ID regeneration every hour, and a profile refresh from `users` every 5 minutes. This part is reasonable.

**SSO** (`super_admin_uitheme/super_sso.php` issues, `sso_login.php` receives): the sender builds an HS256 JWT with `user_id, email, name, role, user_type, target, source, iat, exp(+60s)`, signs it with **one secret shared by all nine systems** and committed in the source, and redirects to `https://<target>/sso_login.php?token=…`.

Problems found:

1. **Foreign `user_id` becomes local identity (bug).** `sso_login.php` puts the token's `user_id`, which comes from the *sending system's* DB, into `$_SESSION['user_id']` and never sets `profile_last_updated`. On the first protected page, `auth.php` runs `SELECT … FROM users WHERE id = ?` with that foreign id and overwrites `fullname`, `email` and `user_type` with whichever *local* user has that id. A RACS user with id 7 becomes Research user 7, including user 7's role.
2. **One shared symmetric secret.** Any of the nine systems, or anyone who has read any of the repos, can mint a valid token for any system, user and role.
3. **No audience or replay check.** The receiver does not check that `target` equals `research`, so a token minted for RACS also works here. Nothing stops a token being used again within its 60 seconds.
4. **Token in the URL query.** It ends up in browser history, server access logs and `Referer` headers.
5. **Role vocabulary is not shared.** `isSuperAdmin()` returns true for `admin`, `research` *and* `superadmin`. RACS only accepts `superadmin`. The sidebar knows about 10 more roles. Nothing defines what `research` means in another system.
6. The OTP is compared with `===` instead of `hash_equals`, and there is no visible attempt limit or expiry.

## Decision

**Option B: per-system asymmetric signing, with identity matched by email and an explicit role map.**

- Each system holds its own **Ed25519 or RS256 private key** and publishes its public key. Receivers keep an allow-list `{issuer → public key}`. A system can then only mint tokens *as itself*, and a leaked receiver config cannot forge anything.
- Required claims: `iss`, `aud` (the target system key), `sub` = **email**, `role`, `iat`, `exp` (≤ 60 s) and `jti`. The receiver rejects the token if `aud ≠ research`, if `iss` is not on the allow-list, or if the `jti` was already seen (store used `jti`s in a small table for 2 minutes).
- The receiver **looks up the local user by email**. It never uses a foreign `user_id`. If no local user exists, it is denied by default, or just-in-time provisioning applies with a mapped role if the team decides to allow it.
- The foreign role goes through a **role map** owned by the receiver (for example `racs:superadmin → admin`). Unmapped roles are denied.
- Delivery: an auto-submitting **POST form** (token in the body) to `sso_login.php`, not `?token=`.
- After SSO login: `session_regenerate_id(true)`, set `profile_last_updated`, and write an audit-log entry (`includes/audit_log.php`).

Ship Option A's hardening first as a stop-gap, because it needs no agreement from the other teams beyond the claim names.

## Options Considered

### Option A: Keep shared-secret HS256, harden it
Validate `aud`, add `jti` replay protection, map by email, use POST, move the secret to `.env` and rotate it.

| Dimension | Assessment |
|-----------|------------|
| Complexity | Low |
| Cost | None |
| Scalability | OK for 9 systems |
| Team familiarity | High (already in use) |

**Pros:** Small change, and other systems keep their current verifier code.
**Cons:** Problem #2 remains: one leaked secret compromises all nine systems, and every team holds it.

### Option B: Per-system asymmetric keys + email identity + role map (chosen)
| Dimension | Assessment |
|-----------|------------|
| Complexity | Medium |
| Cost | None (`openssl_sign`/`sodium_crypto_sign` are built into PHP; `firebase/php-jwt` via Composer) |
| Scalability | Good; adding a system means adding one public key |
| Team familiarity | Medium |

**Pros:** Removes forgery across systems, so a compromise stays contained to one system. It still needs no central server.
**Cons:** All nine teams must update both issuer and receiver code, and keys need distribution and rotation.

### Option C: Central identity provider (Keycloak / OIDC)
| Dimension | Assessment |
|-----------|------------|
| Complexity | High |
| Cost | Needs a VPS; Keycloak will not run on Hostinger shared hosting |
| Scalability | Best |
| Team familiarity | Low |

**Pros:** One user directory and one login, with standard protocols, MFA, logout everywhere and audit.
**Cons:** New infrastructure, nine integrations, and a single point of failure. Too heavy for the capstone timeline.

## Trade-off Analysis

The bug in item 1 and the missing `aud` check are fixable today under any option. The real choice is how far one compromised system should reach. Under A, it reaches all nine. Under B, it reaches only that system's own tokens. Under C, the IdP becomes the single high-value target, but it is professionally run. B removes the systemic risk without new infrastructure, which fits shared hosting. C is the right answer if LGU2 becomes a real production deployment for an LGU.

## Consequences

- **Easier:** Reasoning about who can log in where, revoking one system (remove its key), and auditing SSO entries.
- **Harder:** Cross-team coordination. Each system needs a keypair and an allow-list, and users must have the **same email** in every system.
- **Revisit:** Option C if LGU2 goes to real production; logout across all systems (not covered here).

## Action Items

1. [ ] **Now:** in `sso_login.php`, reject the token unless `target === 'research'`. Resolve the user by `email` against local `users` and set the session from the local row. `session_regenerate_id(true)`. Write to the audit log.
2. [ ] **Now:** move the SSO secret to `.env`, rotate it with the other teams, and delete it from the repo and git history.
3. [ ] Add an `sso_used_jti` table and reject replays.
4. [ ] Change `getSSOUrl()` to render an auto-submitting POST form.
5. [ ] Write `docs/sso-contract.md` with claim names, the role vocabulary and the role map. Circulate it to the 8 other teams.
6. [ ] Move to per-system keypairs (Option B), coordinated with RACS first because it already has its own receiver route.
7. [ ] OTP: compare with `hash_equals`, set a 5-minute expiry and a limit of 5 attempts, and add rate limiting on the login POST.
8. [ ] Rename `isSuperAdmin()` to what it actually checks (`canUseSSO()`), or narrow it to `superadmin`.
