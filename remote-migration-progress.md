# Remote migration progress

Resume file for the migration described in [remote-migration-handoff.md](remote-migration-handoff.md).
Started 2026-09-20. Update this file at the end of every work cycle.

## Decisions taken 2026-09-20

| # | Decision | Answer |
|---|---|---|
| 1 | Host | VPS with root and SSH. The SSH tunnel in step 6 is therefore available and is the chosen transport. |
| 2 | Outbound HTTPS to api.apify.com | Allowed. `?action=start` and `?action=enrich` keep working on the remote copy. |
| 3 | Domain | `seo.bizousoft.com`. TLS status not yet confirmed. Confirm before deploying, because both the Apify token and the session cookie travel over that connection. |
| 4 | Authentication | Reuse whatever already authenticates `seo.bizousoft.com`. Ildar does not have the details yet, and said on 2026-09-20 that he will handle authentication himself once the application is on the remote machine. |
| 5 | Developer delivery | Answered from the filesystem rather than from Ildar. `web/public/accounts.php` and `src/AccountsBrowser.php` already exist and `api.php` already carries `accounts-facets`, `accounts-search` and `accounts-bulk`. The accounts browser is built. This migration modified it rather than duplicating it. |

## Step status

| Step | State |
|---|---|
| 1. Fresh dump | **Blocked on Ildar.** The Cowork sandbox cannot reach Laragon on `localhost:8080` or MySQL on `3306`. See "What Ildar has to run" below. |
| 2. Provision remote database | **Blocked on the VPS existing.** |
| 3. Deploy the PHP application | **Blocked on the VPS existing.** |
| 4. Replace the localhost guard | **Done.** `src/Auth.php`, `web/public/login.php`, `web/public/page-guard.php`, and the guard at the top of `api.php`. |
| 5. Role allowlist | **Done.** `src/Acl.php`, the check above the `switch` in `api.php`, and the 500-row cap in `AccountsBrowser::bulk()`. |
| 6. Point the follow script at the remote database | **Done in the script.** Retry, keepalive, TLS option and the write journal are all in `instagram-follower.js`. Not yet exercised against a real remote database, because there is not one yet. |
| 7. Verify end to end | **Blocked on steps 1, 2 and 3.** |

## What was changed

### ig-follower-scraper

| File | Change |
|---|---|
| `src/Auth.php` | New, 332 lines. Resolves a visitor to a username and one of two roles. Four providers: `localhost`, `builtin`, `basic`, `external`. |
| `src/Acl.php` | New, 105 lines. What each role may do. Positive, closed allowlist. |
| `web/public/login.php` | New. Sign-in form for the `builtin` provider. |
| `web/public/page-guard.php` | New. Included first by every page. 401 or redirect when not signed in, 403 when the role may not open the page. |
| `web/public/api.php` | The `REMOTE_ADDR` comparison was replaced by an `Auth` check and an `Acl` check, both above the `switch`. `accounts-bulk` now passes the role's limits into the service. |
| `src/AccountsBrowser.php` | `bulk()` takes an optional `$limits` array and enforces the allowed operations and the row cap inside the transaction. |
| `config.example.php` | New `auth` block, documented. |
| `tests/auth-acl-test.php` | New, 18 cases. Needs no database and no `config.php`. |
| `README.md` | New "Authentication and roles" section. Layout table and endpoint list brought up to date. |
| `accounts-browser-spec.md` | Raised to 1.1. Sections 6, 8 and 12 corrected, since all three said the localhost guard was the access control. |

### instagram-follower

| File | Change |
|---|---|
| `instagram-follower.js` | 817 to 1,100 lines. Retry helper, keepalive, optional TLS, the write journal and `--replay-journal`. Backup of the previous version at `instagram-follower.js.bak-preremote`. |
| `.env.example` | Rewritten. SSH tunnel instructions and the TLS settings. |
| `.gitignore` | Added `pending-writes.jsonl`. |
| `tests/db-retry-test.js` | New. Exercises the retry helper against simulated failures. |
| `README.md` | New section "The database is on a remote host, this script is not". Files and flags tables brought up to date. |

## Verification done

| Check | Result |
|---|---|
| `php -l` on all nine changed or new PHP files | Clean. |
| `php tests/auth-acl-test.php` | 18 of 18 cases pass. Run in the cloud container on PHP 8.4.21. |
| `node --check instagram-follower.js` | Clean. |
| `node tests/db-retry-test.js` | All pass. Run on the PC. |

Two test failures were found and both were faults in the tests rather than in the code. The first
used `array_replace_recursive` to remove a user from the config, which merges rather than replaces
and left the user in place. The second did not model `db()` recreating the pool, so it counted one
pool discard where the real code performs two.

Nothing has been run against the real database. `Auth`, `Acl` and the retry helper do not touch it,
which is why they could be tested at all, but the guard in `api.php`, the page guards and the
500-row cap have not been exercised against a live request.

## What Ildar has to run

The Cowork sandbox cannot reach Laragon on `localhost:8080` or MySQL on `3306`, so these are his to
run on the PC.

Confirm nothing broke locally. The default provider is `localhost`, which reproduces the old
behaviour, so the existing `config.php` needs no change:

```powershell
cd "C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper"
php tests\auth-acl-test.php
```

Then open `http://localhost:8080/ig-follower-scraper/accounts.php` and confirm it still loads, still
filters, and still tags. Then `index.php`.

Take the fresh dump, which step 1 needs and which must be taken after the most recent follow run
rather than before it:

```
http://localhost:8080/ig-follower-scraper/api.php?action=dump
http://localhost:8080/ig-follower-scraper/api.php?action=stats
```

Compare the row counts the two report before moving anything.

## Open items

- `Auth::externalIdentity()` is a stub that denies everyone. It is the one function to write to
  adopt the `seo.bizousoft.com` sign-in. Ildar is handling this.
- Whether `seo.bizousoft.com` already has a TLS certificate is unconfirmed.
- `ig_audit_log`, recommended in section 3.1b of `remote-hosting-notes.md`, has not been built. Once
  a second person can change thousands of rows, "who did this and when" is not answerable without
  it. It is not required for the move.
- The retry helper is duplicated between `instagram-follower.js` and `tests/db-retry-test.js`,
  because the script is a single standalone file with no module exports, which is how every
  automation script in this workspace is built. A change to the helper has to be pasted into the
  test as well.
- Carried over from the hand-off and untouched: `@chuck.obin` and accounts like it record as `error`
  where `skipped` with the note "blocked account" would be correct; 8,357 accounts are unenriched
  pending the Apify credit reset on 2026-10-17; the queue is still ordered by `id`.

## Log

- 2026-09-20: read the hand-off, the spec and the codebase. Recorded the five decisions.
- 2026-09-20: steps 4, 5 and the script half of step 6 written and tested. Documentation updated
  across both projects. Steps 1, 2, 3 and 7 remain, and all four need either the PC or the VPS.
