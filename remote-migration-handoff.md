# Hand-off: migrate the IG follower scraper to a remote host

Written 2026-09-20 to be read at the start of the next session. The goal of that session is to get
this application running on a remote host so a remote employee can use the accounts browser.

> **Status as of 2026-09-20, later the same day.** The five decisions have been answered and steps
> 4, 5 and the script half of step 6 are done. Steps 1, 2, 3 and 7 are not, because each needs
> either Ildar's PC or a VPS that does not exist yet. Current state, what changed and what Ildar has
> to run are in [remote-migration-progress.md](remote-migration-progress.md), which is the file to
> read first from now on. This file remains the plan.
>
> Two answers differ from what this document assumed. Decision 5 was settled by looking at the
> filesystem rather than by asking: the accounts browser is already built, so step 4 and step 5
> modified it rather than adding to a greenfield page. Decision 4 is still open, and Ildar has said
> he will wire the `seo.bizousoft.com` authentication in himself once the application is on the
> remote machine. `Auth::externalIdentity()` is the stub left for that, and it denies everyone until
> it is written.

Read alongside:

- [remote-hosting-notes.md](remote-hosting-notes.md) - the full portability audit. Section 3 lists
  everything that breaks on a remote host and section 4 explains why the follow script stays home.
- [accounts-browser-spec.md](accounts-browser-spec.md) - the CRUD interface spec written for a
  developer, including the role allowlist and the audit log.
- [README.md](README.md) - what every endpoint and button does.
- [ig-follower-scraper-progress.md](ig-follower-scraper-progress.md) - the build history. Phases 4
  and 5 in that file are stale leftovers from an abandoned actor comparison and can be ignored.

---

## Where things stand right now

The application runs locally under Laragon at `http://localhost:8080/ig-follower-scraper/`. Port 80
refuses connections on this machine, so the `:8080` is not optional.

The database `sunny_kratom` holds 10,857 accounts scraped from the followers of
@american_kratom_assoc. All of them are in `ig_accounts` with `follow_status` of `pending`, except
the roughly 189 that were imported from Ildar's manual following and the ones the follow script has
worked through since. 2,500 of the 10,857 have been enriched with follower counts, bios and business
flags. The remaining 8,357 are waiting for the Apify credit cycle that resets on 2026-10-17.

A full logical backup already exists and has been verified, taken 2026-09-20 at 11:01 New York:

| File | Size |
|---|---|
| `data/dump-sunny_kratom-20260920-110145.sql` | 22.9 MB |
| `data/dump-sunny_kratom-20260920-110145.sql.gz` | 5.0 MB |

That dump is a point-in-time copy and it is now several days old. Take a fresh one with
`?action=dump` before cutting over, because the follow script writes to `ig_accounts` on every run
and the old dump would silently undo that progress.

## The architecture that was already agreed

This was settled on 2026-09-20 and does not need revisiting.

| Component | Where it runs | Reason |
|---|---|---|
| MySQL | Remote host | It is only data. Nothing about a database is sensitive to which IP address reaches it |
| PHP app, including the accounts browser | Remote host | Being reachable from Indonesia is the entire point of the move |
| `instagram-follower.js` | Stays on the PC | It keeps the residential Quebec IP and the aged, signed-in Chrome profile |

The reason the follow script stays on the PC is worth restating, because it is the thing that would
be most tempting to change. Instagram evaluates where a session connects from. The account has been
operating from a residential Quebec address with a Chrome profile that has real history behind it,
and that profile is a large part of why the automated following has been tolerated. Moving the
session to a datacenter IP is one of the most common triggers for a checkpoint or a ban.

The cost of getting this wrong is lopsided. The web application being down for an evening costs
nothing. Losing @verifiedbotanicals costs the follower base, the queue progress and the account.

After the move, the follow script keeps running on the PC but reads its queue from the remote MySQL
instance, so both halves see the same data without either one changing location.

---

## Decisions needed from Ildar before any code is written

Ask these first. Several of the steps below branch on the answers.

1. **Which host.** A VPS with root and SSH, or shared hosting with cPanel. This decides whether an
   SSH tunnel is available, which is the preferred way for the follow script to reach the database.
2. **Whether the host allows outbound HTTPS to `api.apify.com`.** Some shared hosts block outbound
   connections entirely, which would break `?action=start` and `?action=enrich` on the remote copy.
3. **The domain or subdomain**, and whether a TLS certificate is already in place. TLS is required,
   not optional, because both the Apify token and the login session travel over this connection.
4. **Which authentication method.** The three options, in descending order of preference, are a
   private network such as Tailscale with the existing IP guard widened to that range, HTTP Basic
   over TLS enforced at the web server plus an IP allowlist, or a shared secret in a header. The
   third is the weakest because it leaks into server logs and browser history.
5. **Whether the developer who was handed `accounts-browser-spec.md` has delivered anything yet.**
   If that work is done or partly done, the migration has to merge with it rather than duplicate it.

---

## The work, in the order it should happen

### Step 1. Take a fresh dump

Call `?action=dump` on the local app. Confirm the table row counts against
`?action=stats` before moving on. `ig_raw_items` is 13 MB of the 23 MB total and only exists so a
future actor's extra fields can be recovered without paying Apify again. It can be skipped with
`?action=dump&tables=ig_accounts,ig_relations,ig_profile_stats,ig_scrape_runs` if the transfer is
slow, but keep the local copy either way.

### Step 2. Provision the remote database

Create the database with the right charset, then restore. The charset matters because Instagram
display names contain 4-byte emoji, and a `utf8` database silently truncates them.

```bash
mysql -u <user> -p -e "CREATE DATABASE sunny_kratom DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c dump-sunny_kratom-<timestamp>.sql.gz | mysql -u <user> -p sunny_kratom
```

Verify after restore by selecting a row with an emoji in `user_full_name` and confirming the
character survived. Check the MySQL version too: `ig_raw_items.payload` is a `JSON` column, so it
needs MySQL 5.7.8 or newer, or MariaDB 10.2 or newer.

### Step 3. Deploy the PHP application

Upload everything except `config.php` and `data/`. Set the web server's document root to
`web/public/`, not the project root. Getting this wrong exposes `config.php` with the Apify token in
it, along with the entire `data/` directory including the dumps.

Write a fresh `config.php` on the server from `config.example.php`. It needs the remote database
credentials, the Apify token and the `America/New_York` timezone. The application has no Composer
dependencies, no CDN and no build step, so there is nothing else to install beyond PHP with PDO and
curl.

Give the web server user write permission on `data/` for dumps and on `run-screenshots/` for failure
captures.

### Step 4. Replace the localhost guard with real authentication

**Done 2026-09-20.** `src/Auth.php` and `web/public/login.php` and `web/public/page-guard.php`, with
the guard at the top of `api.php`. Four providers, and the default is `localhost`, which reproduces
the behaviour below so nothing changed on the PC. The original text follows.

This is the step that must not be skipped or deferred. `web/public/api.php:24` currently contains:

```php
if (!in_array($remote, ['127.0.0.1', '::1'], true)) { /* 403 */ }
```

On a remote host `REMOTE_ADDR` is the visitor's address, so this rejects everyone including the
operator. The obvious move is to delete it, and deleting it without putting something in its place
leaves an unauthenticated application that can spend real money through `?action=start` and
`?action=enrich`, modify thousands of rows through `?action=accounts-bulk`, and hand out the entire
dataset through `?action=dump`.

Implement whichever authentication method Ildar chose in decision 4 above.

### Step 5. Add the role allowlist

**Done 2026-09-20.** `src/Acl.php`, the check above the `switch` in `api.php`, and the 500-row cap
enforced inside the transaction in `AccountsBrowser::bulk()`. The `ig_audit_log` table recommended
at the end of this step was not built. The original text follows.

A single login is not enough, because a login grants the whole of `api.php` and several actions are
not safe in an employee's hands. The full reasoning and the risk table are in section 3.1b of
`remote-hosting-notes.md`.

Two roles. Put the check in `api.php` immediately after authentication and before the `switch`, so
that an action added later is denied by default rather than exposed by being forgotten.

| Role | Pages | Actions |
|---|---|---|
| `operator`, meaning the employee | `accounts.php` only | `accounts-search`, `accounts-facets`, `accounts-bulk`, `queue-stats` |
| `admin`, meaning Ildar | all | all |

Three extra constraints on the operator role. `accounts-bulk` must accept only an `operation` of
`skip` or `revert` and reject anything else. A single bulk operation must be capped, 500 rows is the
suggested number, because otherwise one select-all with no filter marks the whole queue and nobody
would necessarily notice. Requesting `index.php` as an operator must return 403 rather than a
redirect, since a redirect confirms the page exists.

The `ig_audit_log` table in section 3.1b of the hosting notes is recommended alongside this. Once a
second person can change 10,000 rows, "who did this and when" stops being answerable without it.

### Step 6. Point the follow script at the remote database

**Done in the script 2026-09-20, not yet exercised against a real remote database.** The retry
helper, the keepalive, the TLS option and the durable `recordAttempt()` are all in
`instagram-follower.js`, with tests in `tests/db-retry-test.js`.

One correction to the text below: it says three functions talk to the database. There are four. The
fourth is `eligibleCount()`, which reports how many accounts pass the current filters for the
startup banner. It is wrapped like the other three.

The original text follows.

`instagram-follower.js` reads its queue over MySQL. With the database now across the internet, three
assumptions in that script stop holding. None of this work has been done yet, it was offered and not
yet authorised.

The first problem is that there is no reconnect. The pool is created at `instagram-follower.js:147`:

```js
pool = await mysql.createPool({ ...DB, waitForConnections: true, connectionLimit: 4 });
```

Three functions in the script talk to the database: `nextTarget()`, which pulls the next account to
follow, `recordAttempt()`, which writes the outcome of a follow back to `ig_accounts`, and
`queueCounts()`, which reports how much of the queue is left. Any of them can now throw on a
transient network drop, a NAT timeout or a MySQL `wait_timeout`. That exception propagates to the
main loop's catch, which sets `stopReason` and ends the run. A single network blip would kill a run
that is meant to last six to eight hours.

The fix is a retry helper wrapped around those three functions. Three attempts with backoff, and it
must retry only on connection-level errors, which are `PROTOCOL_CONNECTION_LOST`, `ECONNRESET`,
`ETIMEDOUT` and `EPIPE`. It must never retry a SQL syntax error or a constraint violation, because
those will fail identically every time and retrying them just hides the bug.

The second problem is that `mysql2` connects in the clear by default. Credentials and the whole queue
would cross the internet unencrypted. The preferred fix is an SSH tunnel opened from the PC, leaving
the script pointed at `127.0.0.1:3306` exactly as it is today. That gives both encryption and
authentication, it avoids exposing MySQL to the internet at all, and it needs no change to the
script beyond the retry work. Setting the `ssl` option on the pool is the alternative if the host
does not offer SSH.

Also add `enableKeepAlive: true` and `keepAliveInitialDelay: 10000` to the pool options. The script
queries roughly every four minutes, which is usually frequent enough to keep the connection warm on
its own, but the keepalive removes the dependence on that timing.

The third problem is specific to `recordAttempt()`, and it is worse than a failed read. If that write
fails after the Follow button has already been clicked, Instagram has recorded the follow but the
database still says `pending`. The next run will follow the same account again, which looks like bot
behaviour to Instagram. Make that one function durable: if every retry fails, append the intended
update to a local file so it can be replayed later, and log it loudly enough to notice.

### Step 7. Verify end to end

Run the follow script against the remote database in `--dry-run` mode first and confirm it selects
the same next target it would have picked locally. Then have someone mark an account as skip through
the remote accounts browser and confirm the script no longer offers it.

---

## Carried-over items not part of the migration

These are open but should not hold up the move.

- `@chuck.obin` and accounts like it currently record as `error`. A profile showing an `Unblock`
  button should be recorded as `skipped` with the note "blocked account" instead, because it is a
  known terminal state rather than a failure.
- 8,357 accounts are still unenriched, waiting on the Apify credit reset on 2026-10-17.
- The queue is still ordered by `id`, which is the order Apify returned the accounts. Nothing
  prioritises within it.

---

## Standing constraints that apply to every step

- Never create symlinks or junctions inside "All projects". It is a Google Drive File Stream folder
  and an Obsidian vault, and symlinks break Obsidian startup.
- Browser automation scripts are Node plus Playwright with a persistent per-account profile, run
  from PowerShell. Never Python.
- Never truncate code or leave placeholders. All code and SQL is written out in full.
- No em dashes in any copy or response.
- Document new features when they are added, and update or remove the documentation when a feature
  is removed.
- `config.php` holds the Apify token and is gitignored. It is never committed, and the remote copy is
  written directly on the server rather than uploaded from here.
- Credentials are not in the vault. They live in a Google Doc titled "Credentials (<project>)" plus
  the Bitwarden organisation vault.
