# IG Follower Scraper

One-time Apify scrape of an Instagram account's follower list into MySQL, written in PHP.

The first target is **@american_kratom_assoc** (12.4K followers as of 2026-09-16). The rows it
produces are the follow queue for the
[instagram-follower](../instagram-follower/README.md) Playwright script.

**Actor:** [apify/instagram-followers-following-scraper](https://apify.com/apify/instagram-followers-following-scraper)
**Cost:** $1.15 per 1,000 profiles. 12,400 followers is about $14.26.
**Blocker:** a free Apify account gets only the first page, 48 followers. See below.
**Progress / resume file:** [ig-follower-scraper-progress.md](ig-follower-scraper-progress.md)

---

## Operator guide

Everything runs from **`http://localhost:8080/ig-follower-scraper/`**. Port 80 is refused on this
machine, so the `:8080` is required. The pretty hostname `ig-follower-scraper.test` does not resolve
because Laragon never auto-detected the manually created symlink.

### Check spend before anything billable

```
?action=usage
```

Returns `spent_usd`, `cap_usd`, `remaining_usd` and the billing cycle dates. Every actor run costs
money. **Read this first, every time.** The included credit and the hard cap are different numbers:
spending past the credit is overage on the card, and hitting the cap aborts runs partway.

```
?action=run-cost&run=N        what one run actually cost, and its real per-1000 rate
?action=actor-pricing&actor=X an actor's configured per-event prices
```

Never quote a price from an actor's store page. Those read "from $X", which is the price at a
discount tier this account does not have. Measured rates on STARTER/BRONZE:

| Actor | Store page says | Actually charged |
|---|---|---|
| `apify/instagram-followers-following-scraper` | $1.15 / 1,000 | **$1.75 / 1,000** |
| `memo23/instagram-followers-count-scraper` | from $1.30 / 1,000 | **$2.38 / 1,000** |

### The two jobs this app does

**1. Scrape a follower list** into `ig_accounts`, which is the follow queue.

Dashboard: set username, direction and results limit, press **Start run**, poll **Refresh status**
until SUCCEEDED, then **Import until done**.

```
?action=start&username=american_kratom_assoc&what=followers&limit=13000
?action=status[&run=N]
?action=import-all&run=N&seconds=60
```

**2. Enrich those accounts** with follower counts, post counts, bios and business flags, into
`ig_profile_stats`.

Dashboard: the **Enrich profiles** panel. Set how many, choose whether to skip private accounts,
press **Enrich & import** and leave the page open. It waits for Apify to finish, then imports the
dataset automatically. **Resume latest import** is only for recovery after the browser closes or a
request fails.

```
?action=enrich&n=2400[&public=1]
?action=import-all&run=N&seconds=60
?action=enrich-stats
```

Enrichment only ever picks accounts with no stats yet, in queue order, so pressing it repeatedly
walks down the list without paying twice for anyone.

### Every endpoint

Every action below requires an authenticated caller. Only `accounts-search`, `accounts-facets`,
`accounts-bulk` and `queue-stats` are available to the operator role; the rest are admin only. See
[Authentication and roles](#authentication-and-roles).

| Endpoint | What it does |
|---|---|
| `?action=usage` | Spend, cap, remaining, billing cycle dates |
| `?action=run-cost&run=N` | What a run actually cost, and its per-1000 rate |
| `?action=actor-pricing&actor=X` | An actor's configured per-event prices |
| `?action=start&username=&what=&limit=` | Start a follower scrape |
| `?action=enrich&n=&public=0\|1` | Start a profile enrichment run |
| `?action=status[&run=N]` | Refresh a run's status from Apify and return the row |
| `?action=import[&run=N]` | Import one page (500 rows). Routes by run kind |
| `?action=import-all&run=N&seconds=60` | Import repeatedly within a time budget |
| `?action=reset-cursor&run=N` | Rewind a run so its dataset re-imports from zero |
| `?action=abort[&run=N]` | Abort a running actor. Still billed for what it returned |
| `?action=log[&run=N]` | The Apify run log. **The only place limits and silent stops appear** |
| `?action=stats` | Queue counts by follow status and source |
| `?action=enrich-stats` | Enrichment coverage, zero-post and business counts, top accounts |
| `?action=queue&n=20` | Next N pending follow targets |
| `?action=queue-stats&min_followers=50&min_posts=0` | How many pending accounts pass the follow script's filters, with bands and days of runway |
| `?action=queue-row&username=X` | One `ig_accounts` row with its follow state, joined to its stats |
| `?action=queue-head&n=40` | The first N rows in queue order with their statuses, plus the first row that qualifies under the 50-follower filter |
| `?action=import-followed[&apply=1]` | Mark accounts followed by hand before automation. Preview by default |
| `?action=profile&username=X` | One enriched row, by username or `ig_user_id` |
| `?action=bio-search&q=chronic&n=25` | Search enriched bios |
| `?action=raw&run=N&n=2[&clean=0]` | Raw dataset items, unmapped. `clean=0` shows hidden fields |
| `?action=probe&actor=X&input=<json>&wait=90` | Run any actor with any input, return its raw output |
| `?action=migrate` | Apply schema additions idempotently |
| `?action=dump[&data=0][&tables=a,b]` | Full SQL backup written to `data/`. `data=0` for schema only |

`?action=probe` is how to answer "what does this actor return" before writing code against it. Actor
pages do not list output fields, and summaries of them were wrong twice here. A probe costs under a
cent.

### Queries the follow script needs

The follow queue lives in `ig_accounts`. Enrichment lives in `ig_profile_stats`, joined on
`ig_user_id`. Nothing is filtered by default.

Next target, unfiltered:

```sql
SELECT username FROM ig_accounts
 WHERE follow_status = 'pending'
 ORDER BY id LIMIT 1;
```

Next target as the follow script actually selects it, 50 or more followers:

```sql
SELECT a.username, s.followers_count, s.posts_count
  FROM ig_accounts a
  JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
 WHERE a.follow_status = 'pending'
   AND s.followers_count >= 50
 ORDER BY a.id
 LIMIT 1;
```

The INNER JOIN means only enriched accounts qualify, because an unenriched account has no follower
count. As of 2026-09-17 that is **2,130 of 10,857**. Enrichment coverage now limits how long the
follow script can run before it exhausts the queue.

**Do not filter on `is_private`.** Decided 2026-09-17: private accounts are followed like any other,
and record `requested` instead of `followed`. This is also why enrichment runs in plain queue order
rather than with `?action=enrich&public=1`.

Record an attempt:

```sql
UPDATE ig_accounts
   SET follow_status = 'followed',        -- or requested / already_following / error / skipped
       follow_attempted_at = '2026-09-17 20:04:11',   -- America/New_York, written by Node not MySQL
       follow_note = NULL
 WHERE username = 'someaccount';
```

Put a row back in the queue:

```sql
UPDATE ig_accounts SET follow_status = 'pending', follow_note = NULL WHERE username = 'someaccount';
```

How much of the queue is enriched:

```sql
SELECT COUNT(*) AS total,
       SUM(s.id IS NOT NULL) AS enriched,
       SUM(s.id IS NULL)     AS not_enriched
  FROM ig_accounts a
  LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id;
```

### Accounts followed by hand before the automation existed

Ildar followed about 190 accounts manually between 2026-09-10 and 2026-09-17, tracked in a Google
Sheet. Those rows were imported on 2026-09-17 so the follow script does not spend a cooldown slot
re-following them.

Source file: `data/already-followed.json`, parsed from the sheet export. Kept for audit.

```
?action=import-followed          preview, changes nothing
?action=import-followed&apply=1  write
```

It only touches rows whose `follow_status` is still `pending`, so it is safe to re-run and cannot
overwrite a result the script produced. The `Outreach Stage` column of the sheet decides the status:
"Follow Requested" becomes `requested`, anything else becomes `followed`. Each row gets the sheet's
follow date and the note "followed by hand before automation, imported from follow tracker", so
hand-entered history stays distinguishable from what the script did.

Result: 189 records in the file, **187 applied**, 1 (`nathetara`) not in the queue at all, 1
(`stephaniepitts76`) already set to `already_following` by the script's first run and therefore
skipped. 188 of the 189 were in AKA's follower list, so the manual work came from the same pool.

Effect on the queue: pending fell from 10,856 to 10,669, and accounts eligible at 50+ followers fell
from 2,130 to 1,943, which is 19 days of runway at 100 a day.

To import another batch later, replace `data/already-followed.json` with the same shape:

```json
[{"username": "someone", "status": "followed", "attempted_at": "2026-09-10 12:00:00"}]
```

### When something looks wrong

- **A run says RUNNING but nothing is running.** The dashboard refreshes non-terminal runs on load.
  If it still looks stuck, `?action=status&run=N` forces a refresh.
- **A run SUCCEEDED but returned far less than asked.** Read `?action=log&run=N`. A plan limit or a
  silent stop appears only there, never in the API status.
- **An import dies partway.** The cursor is saved per batch. Press Import again and it resumes.
- **A row count looks doubled.** It will not be. Both upserts are keyed and were verified: runs 1, 2
  and 3 overlapped and `ig_accounts` holds exactly 10,857, matching the dataset.

## Why PHP runs this and not the assistant's sandbox

The assistant's Linux sandbox cannot reach Laragon's MySQL on port 3306. This is the same constraint
the Karkusha app hit, recorded in
`Karkusha/fb-group-marketing/karkusha-db-migration-PROGRESS.md`, and the fix is the same: a
localhost-guarded PHP endpoint that the assistant drives through a browser request to
`http://127.0.0.1`. PHP runs on Windows, so it reaches MySQL directly.

Ildar can also run everything from PowerShell with `cli.php`, which has no request timeout.

## Layout

| Path | Purpose |
|---|---|
| `bootstrap.php` | Loads `config.php`, sets the timezone, registers the `src/` autoloader. |
| `config.example.php` | Template. Copy to `config.php` and fill in the Apify token and DB credentials. |
| `config.php` | Real secrets. Gitignored. Never commit it. |
| `sql/schema.sql` | Creates the `sunny_kratom` database and three tables. |
| `src/Config.php` | Dot-path config reader plus the New York timestamp helper. |
| `src/Auth.php` | Who the visitor is. Four providers, two roles. Replaced the localhost guard. |
| `src/Acl.php` | What that role may do. Positive, closed allowlist plus the operator row cap. |
| `src/Db.php` | PDO singleton, utf8mb4, exceptions on, emulated prepares off. |
| `src/Apify.php` | REST client: start a run, read a run, abort a run, page a dataset. |
| `src/Scraper.php` | Orchestration: `start()`, `refresh()`, `importBatch()`, `importAll()`, `stats()`. |
| `cli.php` | PowerShell entry point. |
| `web/public/index.php` | Dashboard. Admin only. |
| `web/public/accounts.php` | Accounts browser. The page the employee uses. |
| `web/public/login.php` | Uses the parent login/logout for `external`; has a local form for `builtin`. |
| `web/public/page-guard.php` | Included first by every page. Authenticates, then checks the role. |
| `web/public/api.php` | JSON endpoint behind both pages. Authenticates and checks the role on every request. |
| `tests/auth-acl-test.php` | Authentication and role tests. Needs no database and no `config.php`. |
| `tests/web-auth-test.php` | HTTP tests with a temporary app copy and synthetic sessions. |

Endpoints: `?action=` `start`, `status`, `import`, `import-all`, `abort`, `stats`, `queue`, `log`,
`enrich`, `enrich-stats`, `raw`, `probe`, `migrate`, `reset-cursor`, `dump`, `accounts-search`,
`accounts-facets`, `accounts-bulk`, `queue-stats`, `queue-head`, `queue-row`, `recent-attempts`,
`facets`, `account`, `profile`, `bio-search`, `import-followed`, `actor-pricing`, `run-cost`,
`usage`.

`import` and `import-all` route by the run's `kind` column, so the same buttons work for follower
runs and enrichment runs.

**`?action=probe&actor=<id>&input=<urlencoded JSON>&wait=90`** runs any actor with any input and
returns its raw output and log tail. Use it before writing code against a new actor. Actor pages do
not list their output fields, and summaries of them were wrong twice in this project. A probe costs
a fraction of a cent.

**`?action=migrate`** applies schema additions idempotently, so a schema change needs no HeidiSQL.

**`?action=dump`** writes a full logical backup to `data/dump-<db>-<timestamp>.sql`, using PHP rather
than `mysqldump`. 23 MB and 37,576 rows as of 2026-09-20, about 5 MB gzipped. Restore with
`mysql -u <user> -p <database> < dump.sql`. Dumps are git-ignored.

### Moving to a remote host

Planned in [remote-migration-handoff.md](remote-migration-handoff.md), tracked in
[remote-migration-progress.md](remote-migration-progress.md), with the full portability audit in
[remote-hosting-notes.md](remote-hosting-notes.md).

The PHP application was already portable: every path derives from `__DIR__`, `config.php` and
`data/` sit above the docroot, and all connection settings are config-driven. The access control was
not portable, and has been replaced. See [Authentication and roles](#authentication-and-roles).

`instagram-follower.js` stays on the PC. It depends on an aged, signed-in Chrome profile and a
residential Quebec address, and moving that session to a datacenter address is a common trigger for
an Instagram checkpoint. It reads its database settings from `.env`, so it drives the remote MySQL
while continuing to run locally. The changes that made that safe are in that script's own
[README](../instagram-follower/README.md).

## Authentication and roles

The application used to allow exactly one visitor: whoever connected from `127.0.0.1`. That was
enforced by a single comparison near the top of `api.php`. On a remote host `REMOTE_ADDR` is the
visitor's address, so the same comparison rejects everyone, including the operator.

Deleting it is not an option. `?action=start` and `?action=enrich` spend real money at Apify,
`?action=accounts-bulk` can rewrite thousands of rows, and `?action=dump` returns the whole dataset.
An unauthenticated copy of this application on a public address gives all of that to anyone who
finds the URL.

Two new classes replace it.

`src/Auth.php` answers who the visitor is. It resolves them to a username and one of two roles, or
to nobody. Which mechanism it uses is chosen with the `auth.provider` key in `config.php`:

| Provider | Identity comes from | Use it when |
|---|---|---|
| `localhost` | `REMOTE_ADDR` being `127.0.0.1` or `::1`, always as admin | Explicit opt-in for a standalone local installation. |
| `builtin` | A session login against bcrypt hashes in `config.php` | Any host, when nothing else is available. |
| `basic` | `PHP_AUTH_USER`, set by the web server doing HTTP Basic | The web server already authenticates. |
| `external` | The parent SEO site's `PHPSESSID` session | Default on this host, including when the auth block is absent. |

The `external` provider reuses the parent site's Google login. It reads `$_SESSION['user']['email']`
and the matching `$_SESSION['app_user']` from the same PHP session storage and handler. Parent
admins become scraper admins; parent marketers become operators. Missing identities, unknown
roles, mismatched emails, and sessions marked inactive are denied. It does not trust identity or
role headers sent by a browser, and it releases the session lock without rewriting the parent data.

For a narrower list of users, populate `auth.external_roles` with lowercase email addresses and
their `admin` or `operator` roles. Once this map has entries, unlisted addresses are denied even
if they are parent-site admins. Legacy parent sessions without `app_user` also need this explicit
email mapping. With an empty map, the recognised parent roles apply.

Signed-out HTML requests redirect to `/login.php`; API requests return `401` JSON with that login
URL. Both browser pages navigate to login when an API request reports an expired session. The
shared session is a Secure, HttpOnly, SameSite=Strict 400-day sliding cookie: each valid scraper
request renews it, and PHP retains the matching server-side session for the same period. Set
`auth.external_session_lifetime` to change this duration (and keep it aligned with the parent
site's session policy).
`web/public/login.php?logout=1` redirects to the parent's `/logout.php`. Override
`auth.external_login_url`, `auth.external_logout_url`, and `auth.external_session_name` if the
host uses different paths or a different session name. Shared cookies retain Secure, HttpOnly,
and SameSite=Strict. The parent's existing login finishes at its dashboard; reopen the scraper
after signing in. No parent files need modification.

Authentication works before private `config.php` exists. After authentication and role checks,
API requests return `503` until that file is supplied. Database and Apify setup are still required
for application operations. The local `.htaccess` blocks direct access to source, tests, config,
and data files, disables directory listings, and sends the project root to the accounts browser.

`src/Acl.php` answers what that role may do. The operator role, meaning the employee, may open
`accounts.php` and call four actions: `accounts-search`, `accounts-facets`, `accounts-bulk` and
`queue-stats`. The admin role may do everything. The list is positive and closed, so an action added
to `api.php` next month is denied to the operator until it is named in `Acl`, rather than exposed by
having been forgotten.

Three further limits apply to the operator role:

- `accounts-bulk` accepts only `skip` and `revert`, checked in `AccountsBrowser::bulk()` against the
  limits `Acl` supplies.
- One bulk operation may change at most 500 rows, configurable with `auth.operator_bulk_max_rows`.
  The cap is measured against the number of rows the `UPDATE` would actually touch, inside the
  transaction, so a select-all with no filter is refused before anything is written. Without the
  cap, one such click marks the entire queue of roughly 10,800 accounts and the only visible symptom
  would be the follow script quietly running out of work.
- Requesting `index.php` returns 403 rather than a redirect, because a redirect would confirm that
  the page exists.

The checks sit in two places. `api.php` checks on every request, above the `switch`. Pages include
`web/public/page-guard.php` as their first statement, before any output. Pages also hide controls
the role cannot use, which is presentation only; the decision that counts is the one `api.php`
makes.

Run the tests after deploying, before letting anyone in:

```powershell
php tests/auth-acl-test.php
php tests/web-auth-test.php
```

The unit suite covers all four providers, shared-session validation, both roles, the page rules,
and the row cap. The HTTP suite requires curl and permission to open a temporary localhost port;
it verifies redirects, `401`/`403` responses, session expiry, and preservation of the shared session.
Both suites use isolated temporary sessions and need no database, Apify token, or `config.php`.
Exit status is 0 when all checks pass.
`log` returns the Apify run log as JSON lines, which is the only place the actor reports a
subscription cap or a silent stop.

## Setup

### 1. Database

Laragon -> **MySQL** -> HeidiSQL -> **File > Load SQL file** -> `sql/schema.sql` -> **F9**.

Connection if needed: host `127.0.0.1`, port `3306`, user `root`, password empty.

### 2. Config

```powershell
cd "C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper"
copy config.example.php config.php
notepad config.php
```

Put the Apify token in it. Get it from Apify Console -> Settings -> API & Integrations -> Personal
API tokens. It starts with `apify_api_`.

### 3. Laragon vhost

The project lives in the Obsidian vault, and the vhost is a symlink pointing **into** the vault from
Laragon, never the other way round. A symlink inside `All projects` breaks Obsidian startup.

Run CMD as Administrator:

```
mklink /D "C:\laragon\www\ig-follower-scraper" "C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper\web\public"
```

Then open `http://ig-follower-scraper.test/` or `http://ig-follower-scraper.test:8080/` if a Docker
container has taken port 80. The tell for that is a plain-text `404 page not found` from a Go server.

## Running it

### From the dashboard

1. Open the vhost.
2. Leave the username as `american_kratom_assoc`, limit `500`, press **Start run**. It asks for
   confirmation, because the run is billed.
3. Press **Refresh status** until it reads `SUCCEEDED`.
4. Press **Import until done**. That calls the import endpoint once per page and repeats until the
   last page comes back short.
5. Check the counts, then clear the limit field and start the uncapped run.

### From PowerShell

```powershell
cd "C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper"
php cli.php start american_kratom_assoc followers 500
php cli.php status
php cli.php import
php cli.php stats
php cli.php queue 20
```

`php cli.php abort` stops a run that was started by mistake. A pay-per-result actor keeps billing
while it runs, so abort rather than closing the tab.

## Profile enrichment

The followers actor returns list membership only. Follower counts, post counts, bios and business
flags come from a second actor, `memo23/instagram-followers-count-scraper`, billed separately at
about $1.30 per 1,000 profiles.

From the dashboard: set a count, choose whether to skip private accounts, then press
**Enrich & import**. Keep the page open while Apify runs; it imports automatically when the run
succeeds. If the browser closes or a request fails, **Resume latest import** continues from the
saved cursor. It only ever picks accounts that have no stats yet, so each new enrichment run walks
through the queue without re-paying for anyone.

Endpoints: `?action=enrich&n=100[&public=1]` and `?action=enrich-stats`.

Why this actor rather than the official `apify/instagram-followers-count-scraper`, which costs the
same: both were probed live on the same handle and returned identical counts, but memo23 returns 21
fields against 8. The extra fields are the filter this project needs, `postsCount` to spot empty
shells, `isBusiness`/`category` to separate vendors from consumers, `biography` for keyword matching,
and `publicEmail`/`publicPhone` for outreach.

## Schema

Three tables, in `sunny_kratom`.

### `ig_accounts`

One row per Instagram account ever seen, unique on `ig_user_id`. This table is the follow queue.

| Column | Notes |
|---|---|
| `ig_user_id` | Instagram's numeric id. Stable across username changes, so it is the unique key rather than the username. |
| `username`, `full_name`, `profile_pic_url` | From the actor. |
| `is_verified`, `is_private` | Flags from the actor. |
| `first_seen_at`, `last_seen_at` | Updated on re-scrape. |
| `follow_status` | `pending`, `followed`, `requested`, `already_following`, `error`, `skipped`. Defaults to `pending`. |
| `follow_attempted_at` | America/New_York wall time. Written by PHP, not by MySQL, so no timezone tables are needed. |
| `follow_note` | Error reason when `follow_status` is `error`. |

A re-scrape never resets `follow_status`, `follow_attempted_at` or `follow_note`. The upsert only
touches the profile fields and `last_seen_at`.

### `ig_relations`

Which source account a profile was found under, and in which direction. Unique on
(`source_username`, `relation`, `ig_user_id`). Kept separate from `ig_accounts` so the same profile
can be a follower of one account and a following of another without duplicating the profile row.

### `ig_profile_stats`

One row per enriched account, unique on `ig_user_id`, refreshed in place. Holds all 21 fields from
the enrichment actor: `followers_count`, `follows_count`, `posts_count`, `is_private`, `is_verified`,
`is_business`, `biography`, `external_url`, `public_email`, `public_phone`, `category`, `fb_id`,
`location_id`, `account_type`, `profile_pic`, `user_url`, `user_full_name`, `scraped_at`.

Kept separate from `ig_accounts` because it is refreshed on its own schedule and costs money per row,
while `ig_accounts` is the follow queue. Indexed on `followers_count` and `posts_count` so the queue
can be filtered on them.

### `ig_raw_items`

Every dataset item exactly as Apify returned it, for both run kinds, keyed on
(`run_id`, `ig_user_id`). Redundant while the mapped columns cover every field, and kept so a future
actor's extra fields survive without a code change first.

### `ig_scrape_runs`

One row per Apify run, with `cursor_offset`. The import advances the cursor only after its
transaction commits, so a request that dies mid page re-imports that page rather than skipping it.
The upserts are idempotent, so a repeated page changes nothing.

## Notes that cost time to learn

**utf8mb4 everywhere.** Instagram display names contain emoji. A 3-byte `utf8` column throws
"Incorrect string value" on insert, or silently truncates.

**The actor's error shape.** A private, empty or missing profile returns a single item of
`{"error": "no_items"}` rather than a result list. The importer counts it as skipped and surfaces the
string instead of writing a junk row.

**Private accounts are still in the list.** A follower who is private is scraped normally.
Following a private account produces a follow request, not a follow, so the Playwright script records
`requested` rather than `followed`. Filter them out of the queue if that distinction is not wanted:
`WHERE is_private = 0`.

**Billing is per result, not per run.** An aborted run still bills for what it already returned.
The 48-profile first run therefore cost about $0.06, not the $0.58 the 500 limit suggested.

**A free Apify account gets one page only.** This is the thing that decides whether this project can
do its job. The first run finished as SUCCEEDED in 4.8 seconds having made exactly one HTTP request,
and the run log says why:

```
INFO  Scraped 48 followers for american_kratom_assoc (48/500)
WARN  Users without paid subscriptions can only access the first page of followers
      for american_kratom_assoc
```

`resultsLimit` was 500 and the account has 12,400 followers, so the cap is not the input and not the
account. Reaching the rest needs a paid Apify subscription. Nothing in the actor's public description
says this; only the run log does, which is why `?action=log` exists.

**Check the run log whenever a run succeeds but returns less than expected.** A SUCCEEDED status with
a small dataset is not an error anywhere in the API response. The warning only appears in the log.

## Two bugs fixed on 2026-09-16, both found by the first real run

**The item count is not in the run object.** `GET /v2/actor-runs/{id}` returns `stats` with compute
units and timings, and no `datasetItemCount`. Reading it from there made a finished run holding 48
rows report "Dataset items: 0", which looked like the scrape had failed. The count comes from
`GET /v2/datasets/{id}` as `itemCount`. `Apify::getDataset()` does that, and `Scraper::refresh()`
calls it.

**A named placeholder cannot be repeated with native prepares.** The `ig_accounts` upsert used
`:seen` for both `first_seen_at` and `last_seen_at`. Because `Db` sets
`PDO::ATTR_EMULATE_PREPARES => false`, MySQL prepares the statement itself and rejects the repeat
with `SQLSTATE[HY093]: Invalid parameter number`. The placeholders are now `:first_seen` and
`:last_seen`, bound to the same value. Emulated prepares would have hidden this.
