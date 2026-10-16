# Instagram follower (Verified Botanicals)

## Quickstart guide

**Shell: PowerShell.** Not Ubuntu. The script drives the Windows Chrome profile at
`C:\chrome-automation-profile` and opens its database tunnel with Windows `ssh.exe`, and it refuses
to start anywhere else.

### Daily run

```powershell
cd "C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper\instagram-follower"
node instagram-follower.js --check-db
node instagram-follower.js --follows 25
```

`--check-db` confirms the script reaches the VPS database (`bizousoft_ig_analytics` on
`66-206-23-226.boulat.net`) and prints the queue counts. It opens no browser. The second command is
the follow run, in a visible Chrome window.

Leave out `--cooldown-min` and `--cooldown-max` on daily runs. The default wait between follows is a
random 3 to 5 minutes, about 15 follows an hour, with a 2 to 4 action warm-up before the first
follow. 25 follows take about 1.3 to 2.1 hours (24 waits of 3 to 5 minutes, plus about 6 minutes
for the follows and the warm-up); 100 take about 6.5 to 7.5 hours.

### Follows per day while ramping up

`--follows N` counts only real Follow clicks. Accounts already followed, already requested, or not
found are passed over without counting.

| Day | Date | Command |
|---|---|---|
| 1 | 2026-10-14 | `node instagram-follower.js --follows 25` |
| 2 | 2026-10-15 | `node instagram-follower.js --follows 35` |
| 3 | 2026-10-16 | `node instagram-follower.js --follows 45` |
| 4 | 2026-10-17 | `node instagram-follower.js --follows 55` |
| 5 | 2026-10-18 | `node instagram-follower.js --follows 65` |
| 6 | 2026-10-19 | `node instagram-follower.js --follows 75` |
| 7 | 2026-10-20 | `node instagram-follower.js --follows 85` |
| 8 | 2026-10-21 | `node instagram-follower.js --follows 95` |
| 9 onward | 2026-10-22 | `node instagram-follower.js --follows 100` |

The ramp follows "Rate limit risk at 100 follows per day" below: start at 20 to 30, add about 10 a day. If a day is skipped, continue
from the last number run rather than from the date. One run a day.

### If something goes wrong

| What you see | What to do |
|---|---|
| Exit code `2`, or `ACTION BLOCKED` in the summary | Instagram blocked follows. Stop for the day; do not rerun. Recommendation: the next day, repeat the previous day's number instead of increasing it. |
| `!! COULD NOT RECORD THE FOLLOW ...` | The database was unreachable when a follow finished. Run `node instagram-follower.js --replay-journal` once the connection is back. |
| `Chrome ... is not signed in to Instagram` | Run `node instagram-follower.js --login`, sign in as @verifiedbotanicals in the window, press Ctrl+C, rerun. |
| `ssh tunnel process ended (...)` during a run | The tunnel dropped. The script reopens it on the next database call; nothing to do unless it keeps repeating. |
| `TUNNEL_FAILED` at startup | Run `ssh -p 22222 bizousoft@66.206.23.226 "echo key-login-ok"`. It must print `key-login-ok` without asking for a password. |
| The run stops with no summary | Read the `EXIT` line it printed, or the last `exit` entry in `run-log.jsonl`, and tell Claude what it says. |
| `could not attach to or start Chrome` | Close any Chrome window using `C:\chrome-automation-profile`, and make sure the Record scheduler is not running (same profile, same port 9222). |
| `STOP. instagram-follower.js only runs on Ildar's Windows PC` | You are in Ubuntu or on the VPS. Use PowerShell on the PC. |

Ctrl+C stops a run at any time. Every finished follow is already saved; a rerun continues from the
next pending account.

### Short test run

For checking a change, not for daily use: three named accounts, no warm-up, 30 to 60 second waits.
The quotes around the list are required in PowerShell, which otherwise splits it at the commas.

```powershell
node instagram-follower.js --only "user1,user2,user3" --follows 3 --no-warmup --cooldown-min 30 --cooldown-max 60
```

Add `--dry-run` to click nothing and write nothing.

### Profile likes (added 2026-10-15)

The daily run also opens, now and then, a random account @verifiedbotanicals follows and likes one
of its 6 most recent posts or reels. Nothing extra to type: it is on by default. 1 to 2 an hour, at
least 20 minutes apart, at most 15 a run, the same account no sooner than every 14 days, never a
post that is already liked. Feed likes and profile likes together stay at or under 10 an hour.
Pending follow requests are checked on the same visits: when the profile button says `Following`
the request was accepted, and the queue row becomes `followed`.

To test it on its own, one profile visit and then stop, no follows:

```powershell
node instagram-follower.js --profile-like-once --dry-run
node instagram-follower.js --profile-like-once
```

The first opens a post and clicks nothing; the second likes it. `--check-db` shows how many
accounts are due and how many posts have been liked. To leave profile likes out of a run, add
`--no-profile-likes`.

If the banner says `Profile likes: off: tables missing`, open
`https://seo.bizousoft.com/ig-follower-scraper/web/public/api.php?action=migrate` once while signed
in as admin.

### One-time setup (done 2026-10-14, for reference)

- `.env` holds `DB_NAME`, `DB_USER`, `DB_PASS` from the VPS `config.php`, and `DB_SSH_USER=bizousoft`,
  `DB_SSH_PORT=22222`. A password containing `#` must be in single quotes.
- The PC's `C:\Users\ildar\.ssh\id_ed25519.pub` is in the server's `~/.ssh/authorized_keys`, so the
  tunnel opens without a password.
- `127.0.0.1` is in cPanel's Remote Database Access list, so MySQL accepts the tunnel's connection.
- `npm install` in this folder, if `node_modules` is missing.

---

A Node + Playwright warm-up and follow script for the @verifiedbotanicals Instagram account. It
drives a real signed-in Chrome window, performs ordinary browsing actions (stories, feed scrolling,
likes, profile and comment reading), and follows accounts from a MySQL queue on a 3 to 5 minute
cooldown, writing the outcome of every follow attempt back to that queue.

The browsing actions exist to space the follows apart with plausible activity. An account that opens
a profile, follows, closes the tab, waits four minutes and repeats produces a request pattern with no
other traffic in it, which is the pattern Instagram's rate limiter is looking for.

**Location:** `C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper\instagram-follower\`, a subfolder of the
[ig-follower-scraper](../README.md) repository since 2026-10-14. It used to be its own folder,
`Sunny Kratom\instagram-follower\`, outside version control.

**Runs on the PC only, never on the VPS.** The repository is deployed to seo.bizousoft.com, so this
folder exists there too. The script refuses to start there; see "Where the script may run" below.
**Progress / resume file:** [instagram-follower-progress.md](instagram-follower-progress.md)
**Verified selectors:** [instagram-selectors-notes.md](instagram-selectors-notes.md)
**Status 2026-10-14:** running against the VPS database. Follow-button fix verified live; ramp-up starts 2026-10-14 at 25 follows.

---

## Actions

Six actions, chosen at random each cycle, with randomized pauses between them. The follow action
runs on its cooldown and the profile like on its own pacing; the other four fill the time between.

| # | Action | What it does |
|---|---|---|
| 1 | Watch stories | Opens the story tray on the home feed, watches 1 to 3 stories from followed accounts, advances with the right arrow, exits with Escape. |
| 2 | Scroll the feed | Scrolls the home feed in variable increments with variable pauses, sometimes scrolling back up. |
| 3 | Like a post | Picks a random post currently in the feed viewport, reads the like button's `aria-label`, and clicks only if it reads "Like" rather than "Unlike". Already-liked posts are skipped, never toggled off. |
| 4 | Read comments | Opens a profile from the feed, opens one of its posts, scrolls the comment list, closes the post, returns to the feed. No comment text is stored. This is dwell time only. |
| 5 | Follow from the queue | Takes the next `ig_accounts` row whose `follow_status` is `pending`, opens `instagram.com/<username>/`, clicks Follow, verifies the button changed to "Following", writes the result and the timestamp back to that row. A follow (or an account found already followed) is also added to `ig_own_following`. |
| 6 | Like from a profile (added 2026-10-15) | Picks at random an account in `ig_own_following` not visited in 14 days, or a `requested` queue row not checked in 14 days. Opens its profile and reads the follow button. `Following`: opens one of the 6 most recent tiles (pinned posts and reels included) that `ig_post_likes` does not hold, and clicks Like only if the icon reads "Like"; tries a second tile if the first is already liked. `Requested`: the request is still pending, nothing is liked, the check date is written. `Follow` on a requested account: the request was declined or withdrawn; nothing is clicked. A like that does not register turns the action off for the rest of the run. Details in `profile-likes-progress.md`. |

Action 5 runs only when the cooldown has elapsed. The cooldown is a fresh random value between 180
and 300 seconds, drawn after each attempt and measured from the attempt, not from its outcome. A
failed follow starts the same cooldown as a successful one.

## Volume per run

| Setting | Default | Flag |
|---|---|---|
| Follow cooldown | 180 to 300 s, uniform random | `--cooldown-min` / `--cooldown-max` |
| Follows per run | 100 | `--follows` |
| Likes | 5 to 10 per hour | `--likes-per-hour` |
| Stories | 5 to 15 per hour | `--stories-per-hour` |
| Comment reads | 3 to 6 per hour | `--reads-per-hour` |
| Profile likes | 1 to 2 per hour, at least 20 min apart, at most 15 per run | `--profile-likes-per-hour`, `--no-profile-likes` |
| All likes together | at most 10 per hour (feed and profile) | none, `TOTAL_LIKES_PER_HOUR_MAX` in the script |
| Profile revisit | same account no sooner than 14 days | none, `PROFILE_REVISIT_DAYS` in the script |

The cooldown averages 4 minutes, so 100 follows needs 99 gaps averaging 4 minutes plus roughly 20 to
40 seconds per attempt for navigation and verification. A 100-follow run therefore takes about 6.5 to
7.5 hours of continuous browser time, with a floor of about 5 hours if every gap lands at 3 minutes
and a ceiling of about 8.5 hours if every gap lands at 5 minutes. The script must be able to run
unattended for that long and to resume after a crash, which is why the queue row is updated after
each attempt rather than at the end.

## Rate limit risk at 100 follows per day

Instagram issues temporary action blocks on follow activity, typically starting somewhere between
100 and 150 follows in 24 hours on an established account, and much lower on a new or lightly used
one. 100 per day is at the bottom of that band, not below it. Two things reduce the risk:

1. **Ramp up.** Start at 20 to 30 follows per day and add about 10 per day until reaching 100. The
   script takes the daily target as a flag so the ramp is a scheduling decision, not a code change.
2. **Stop on the first block.** If Instagram shows "Action Blocked", "Try Again Later", or the Follow
   button reverts to "Follow" immediately after a successful-looking click, the script writes
   `Error: action blocked` on the current row, stops the run, and exits non-zero. It does not retry.
   Continuing past an action block extends the block.

The account can follow at most 7,500 accounts in total. At 100 per day that ceiling is reached in 75
days, so an unfollow pass will be needed eventually. That is out of scope for this script.

## Where the script may run

Only on Ildar's Windows PC, from PowerShell. Added 2026-10-14, when this folder moved into the
ig-follower-scraper repository. That repository is deployed to the VPS at
`/home/bizousoft/public_html/seo/ig-follower-scraper`, so a copy of this script now exists there,
and running it there would start following from a datacenter address.

`assertRunningOnPc()` is the first thing `main()` does, before every mode including `--login`,
`--check-db` and `--replay-journal`. It refuses to start, exits 1, and prints the reason when any of
these hold:

| Check | Catches |
|---|---|
| A network interface has the address 66.206.23.226 | The VPS itself. |
| The script's folder is under `/home/bizousoft/` or contains `/public_html/` | The VPS deployment, or any other web deployment of this repository. |
| `process.platform` is not `win32` | The VPS again, any other Linux or Mac machine, and Ubuntu (WSL) on the PC. WSL is refused because the script needs the Windows Chrome profile and Windows `ssh.exe`; the message says to use PowerShell. |

There is no flag or setting that overrides it.

The VPS copy is also unreachable from the web: the repository's `.htaccess` returns 403 for
everything under `instagram-follower/`, as it already did for `src/`, `data/` and `tests/`.

## Authentication: attach to a signed-in Chrome, do not launch a fresh profile

Meta puts fresh automated browser profiles into a CAPTCHA loop. The Record scheduler in
[`../../the-record-scheduler/`](../../the-record-scheduler/README.md) hit this and solved it by attaching
over CDP to a Chrome that is already signed in, rather than calling `launchPersistentContext` on a
new profile directory. This script uses the same approach and the same profile, because it is the
same Instagram account.

- Profile directory: `C:\chrome-automation-profile`
- CDP endpoint: `http://localhost:9222`
- The script starts Chrome itself against that profile and port if nothing is listening. `--no-launch`
  disables that.
- Chrome 136 and later refuses `--remote-debugging-port` on the default user profile, which is why
  automation uses a separate directory. Setup and the one-time profile copy are documented in the
  Record scheduler README.

**Do not run this script and the Record scheduler at the same time.** They attach to the same Chrome
instance on the same port and will fight over tabs.

This is a deliberate departure from the workspace browser-automation SOP, which specifies
`launchPersistentContext` against a profile under `%APPDATA%`. The SOP holds for Facebook, Kijiji and
LesPAC. Instagram gets CDP attachment instead, for the CAPTCHA reason above.

## The queue

The queue is the `ig_accounts` table in the `sunny_kratom` MySQL database, populated by
[ig-follower-scraper](../README.md). There is no CSV. A 12.4K row queue in a file
that gets rewritten after every one of 100 daily attempts is a crash away from a corrupt file, and it
cannot be queried.

Columns this script touches:

| Column | Meaning |
|---|---|
| `username` | Instagram handle without the `@`. |
| `follow_status` | `pending` when queued. Written by this script: `followed`, `requested`, `already_following`, `error`, or `skipped`. |
| `follow_attempted_at` | Timestamp of the attempt, `YYYY-MM-DD HH:MM:SS` in America/New_York, written on both success and failure. |
| `follow_note` | Empty on success. On `error`, the reason: `profile not found` (Instagram's "Sorry, this page isn't available."), `no follow button after 15s; header buttons: ...` (the page loaded but showed no follow-state button, with the buttons it did show), `action blocked`, or the raw error text. |

Rules:

- The script selects `WHERE follow_status = 'pending'`, accounts marked VALID on the web app's
  review screen first, then by id, so a rerun resumes where the last run stopped and never
  re-follows an account.
- Each attempt is a single `UPDATE` inside its own transaction. A crash loses at most the one attempt
  in flight.
- A profile that already shows "Following" is marked `already_following` and does not consume the
  cooldown, since no follow request was sent.
- A private account produces a follow request, not a follow, so it is recorded as `requested`.
  Exclude private accounts from the queue with `AND is_private = 0` if that distinction is unwanted.
- `error` rows are left for manual review. The script does not automatically retry them. Setting
  `follow_status` back to `pending` puts a row back in the queue.

The timestamp is written by Node, not by MySQL, so no MySQL timezone tables are needed:

```js
new Date().toLocaleString('sv-SE', { timeZone: 'America/New_York' })  // "2026-09-16 09:14:22"
```

The `sv-SE` locale is what produces `YYYY-MM-DD HH:MM:SS`, which is what MySQL DATETIME wants.

Connection: the `sunny_kratom` database on seo.bizousoft.com (66.206.23.226), always, through an SSH tunnel the
script opens itself. The Laragon copy on this PC is never used. See "Always the remote database,
never Laragon" below.

### Picking the next target

Unfiltered, in queue order:

```sql
SELECT username FROM ig_accounts
 WHERE follow_status = 'pending'
 ORDER BY id LIMIT 1;
```

What the script actually runs by default. `ig_profile_stats` is the enrichment table populated by
[ig-follower-scraper](../README.md), joined on `ig_user_id`:

```sql
SELECT a.username, s.followers_count, s.posts_count
  FROM ig_accounts a
  JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
 WHERE a.follow_status = 'pending'
   AND s.followers_count >= 50
   AND s.posts_count >= 0
 ORDER BY CASE WHEN a.review_decision = 'valid' THEN 0 ELSE 1 END, a.id
 LIMIT 1;
```

**Validated accounts go first (2026-10-16).** Accounts marked VALID on the web app's review screen
(`review_decision = 'valid'`) are picked before everything else, lowest id first. VALID only
reorders: those accounts must still pass `--min-followers` and `--min-posts`. `--only` is unchanged.
Before clicking Follow the script re-reads the account's status and leaves it alone if someone
skipped it on the review screen in the meantime; results are written only to rows still `pending`.
The `review_decision` column is added by the web app (`?action=migrate`, or opening the accounts
browser once); without it the run stops with a message saying so.

**This is an INNER JOIN, which is the point and also the cost.** An unenriched account has no
follower count to test, so it cannot pass a followers filter. Setting `--min-followers 50` therefore
limits the queue to enriched accounts only.

Measured 2026-09-17, against 10,857 pending of which 2,500 are enriched:

| Threshold | Accounts eligible |
|---|---|
| any (no filter) | 10,857 |
| enriched, 0+ followers | 2,500 |
| enriched, 10+ | 2,383 |
| **enriched, 50+ (default)** | **2,130** |
| enriched, 100+ | 1,916 |
| enriched, 500+ | 1,037 |
| enriched, 1,000+ | 553 |
| enriched, 5,000+ | 88 |

So the 50-follower rule discards 370 of the 2,500 enriched accounts, 15%, and the working queue is
**2,130, which is 21 days at 100 follows a day**, not the 108 days the full list would give. The
other 8,357 accounts are invisible to the script until they are enriched, which needs the next
billing cycle's credit from 2026-10-17.

Check the current number any time without opening a browser:

```powershell
node instagram-follower.js --check-db
```

The accounts browser on the VPS reports the same through `api.php?action=queue-stats`, which returns the eligible count, the bands above, and days of runway at 100 a day. The script prints
the same number in its startup banner and warns if fewer accounts match than the follow target.

**Private accounts are deliberately included.** Decided 2026-09-17: they get followed like anyone
else. Instagram turns a follow of a private account into a follow request, so about 42% of the
4,550 private accounts in the queue will record `requested` rather than `followed`. A request still
counts toward Instagram's daily action limits exactly like a follow, so the 100-a-day ceiling and
the 3-to-5 minute cooldown apply unchanged. Do not add `AND a.is_private = 0`.

The `JOIN` means only enriched accounts are eligible. Not all 10,857 are enriched, so check coverage
before assuming the queue is deep enough:

```sql
SELECT COUNT(*) AS total,
       SUM(s.id IS NOT NULL) AS enriched,
       SUM(s.id IS NULL)     AS not_enriched
  FROM ig_accounts a
  LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id;
```

Use `LEFT JOIN` with `s.id IS NULL OR s.posts_count > 0` instead if the script should fall back to
unenriched accounts rather than stopping.

### Why the script may open accounts you already follow

**Fix, 2026-10-14:** scrape @verifiedbotanicals' own following list with Apify and mark every match
as `already_following` before running. Steps are in the Quickstart guide of the
[repository README](../README.md). What follows explains why the problem exists.

The queue is ordered by `id`, which is the order Apify returned AKA's follower list. Manual following
was done against that same list in roughly the same order, so the top of the queue is largely made up
of accounts already followed by hand.

Anything recorded in the follow tracker was imported on 2026-09-17 (see
[ig-follower-scraper](../README.md)), but the tracker turned out to be
incomplete: `sheilafmckay` was followed by hand and never recorded, so the database still had her as
pending and the script opened her profile.

This is handled rather than harmful. The script reads the button, records `already_following`, and
does **not** consume a cooldown slot, because no follow request was sent. It then moves to the next
target within seconds. Expect a run of these before the script reaches genuinely new accounts: of the
first 40 rows in the queue, 27 were already handled.

To see how far the handled block extends, call the `queue-head` action of the accounts browser's
`api.php` on the VPS, signed in as an admin:

```
api.php?action=queue-head&n=100
```

It returns the first N rows in queue order with their statuses, plus the first row that actually
qualifies under the 50-follower filter.

### Recording an attempt

```sql
UPDATE ig_accounts
   SET follow_status = 'followed',   -- or requested / already_following / error / skipped
       follow_attempted_at = ?,      -- America/New_York, from Node, see below
       follow_note = ?
 WHERE username = ?;
```

Enrichment is refreshed independently and never resets `follow_status`, so the two jobs cannot
interfere with each other.

## The database is on a remote host, this script is not

The PHP application and MySQL moved to a VPS so a remote employee in Indonesia can use the accounts
browser. This script did not move, and should not.

Instagram evaluates where a session connects from. This account has been operating from a
residential Quebec address using a Chrome profile with real history behind it, and that combination
is a large part of why the automated following has been tolerated. A datacenter address is one of
the most common triggers for a checkpoint. The cost of being wrong is lopsided: the web application
being unreachable for an evening costs nothing, and losing @verifiedbotanicals costs the follower
base, the queue progress and the account.

So the script keeps running here and reads its queue across the internet. Four things had to change
for that to be safe: retrying dropped connections, journalling writes that cannot be made, encrypting
the connection, and making sure the script can only ever reach the remote database.

### The connection now drops, and a dropped connection must not end the run

Four functions talk to the database. `nextTarget()` pulls the next account to follow.
`eligibleCount()` reports how many accounts pass the current filters, for the startup banner.
`recordAttempt()` writes the outcome of a follow back to `ig_accounts`. `queueCounts()` reports the
queue totals.

When the database was on this machine, any of those failing meant something was seriously wrong, and
ending the run was the right response. Across the internet, a transient network drop, a NAT timeout
or MySQL's own `wait_timeout` can make any of them throw. That exception reaches the main loop's
catch, which sets `stopReason` and ends the run. A run is meant to last six to eight hours, so one
blip would have cut it short.

Each of the four is now wrapped in `withDbRetry()`: three attempts, one second then four seconds of
backoff, and the pool is discarded between attempts because a pool holding dead connections hands
the same dead connection to the next attempt.

It retries only connection-level errors: `PROTOCOL_CONNECTION_LOST`, `ECONNRESET`, `ETIMEDOUT`,
`EPIPE`, `ECONNREFUSED`, `EHOSTUNREACH`, `ENETUNREACH`, `PROTOCOL_SEQUENCE_TIMEOUT` and
`ER_CON_COUNT_ERROR`. A SQL syntax error or a constraint violation is rethrown at once, because it
would fail identically on all three attempts and retrying it only hides the bug behind a slower
failure.

The pool also sets `enableKeepAlive` with `keepAliveInitialDelay: 10000`. The script queries roughly
every four minutes, which is usually often enough to keep the connection warm by itself, but the
keepalive removes the dependence on that timing.

Run `node tests/db-retry-test.js` to exercise all of this against simulated failures.

### A failed write is worse than a failed read, and is never discarded

`recordAttempt()` is different from the three reads around it. A failed read can be repeated later
at no cost. A failed write cannot, because by the time it runs the Follow button has already been
clicked. Instagram has recorded the follow and `ig_accounts` still says `pending`, so the next run
selects the same account and follows it again. Repeatedly following the same account is exactly the
pattern the pacing in this script exists to avoid.

So when all three attempts fail, the intended update is appended to `pending-writes.jsonl` and the
run carries on. The failure is printed in full, written to `run-log.jsonl` as a `journalled-write`
entry, and repeated in the end-of-run summary, because a journal nobody reads is the same as no
journal at all.

Apply it with:

```powershell
node instagram-follower.js --replay-journal
```

That opens no browser. Each entry is applied only if the row is still `pending`, for the same reason
the accounts browser refuses to bulk-tag a row that is not pending: a status written since the entry
was journalled records something that actually happened and must not be overwritten by a stale
entry. Entries that fail to apply stay in the file.

### Always the remote database, never Laragon

Changed 2026-10-13. A copy of `sunny_kratom` still exists in Laragon's MySQL on this PC. It stopped
receiving follow results when the queue moved to the VPS, so following from it would re-follow
accounts the VPS already records as followed. Until this change the script connected to whatever
`.env` named, defaulting to `127.0.0.1:3306` as `root` with no password, which is Laragon. A tunnel
that had not been opened, or a tunnel that could not bind 3306 because Laragon already held it, sent
the run to the local copy without any error.

The script now has one database path and no settings that can point it anywhere else.

1. **It opens its own SSH tunnel.** `ensureTunnel()` starts
   `ssh -N -o BatchMode=yes -o ExitOnForwardFailure=yes -p 22222 -L 127.0.0.1:3307:127.0.0.1:3306 bizousoft@66.206.23.226`
   and waits up to 30 seconds for the local port to answer. `ExitOnForwardFailure` makes ssh exit
   when it cannot bind the port, instead of staying connected with no forward. `BatchMode=yes`
   means key login only: ssh never prompts, and fails with its own message if the key is refused.
   ssh runs detached, in its own hidden console window (changed 2026-10-14, see "Silent stops"
   below). If the port already answers, for example because a tunnel was opened by hand in another
   window or a previous run's ssh outlived it, it is used as it is. The tunnel is closed when the
   script exits.
2. **The host and port are fixed.** MySQL is always reached at `127.0.0.1:<DB_TUNNEL_LOCAL_PORT>`,
   the local end of that tunnel. `DB_HOST`, `DB_PORT` and the old `DB_SSL*` settings are ignored,
   with a warning if they are still in `.env`. `DB_TUNNEL_LOCAL_PORT` defaults to 3307, and 3306 is
   refused at startup because it is Laragon's port.
3. **There are no default credentials.** `DB_NAME`, `DB_USER` and `DB_PASS` must be set, from the
   VPS copy of `ig-follower-scraper/config.php`. The old defaults, `root` with an empty password and
   `sunny_kratom`, were Laragon's.
4. **Every connection is checked against the server it reached.** Each time a connection pool is
   created, at startup and again whenever one is rebuilt after a dropped connection,
   `assertRemoteServer()` reads `@@hostname`, `@@version_compile_os` and `@@datadir` and refuses the
   server if any of these hold: it is a Windows build of MySQL (the VPS runs Linux, Laragon is
   Windows); its data directory is a Windows drive path or contains `laragon`; its hostname equals
   this PC's hostname; or `DB_EXPECT_SERVER_HOSTNAME` is set and does not match. A refusal is error
   `LOCAL_DB_REFUSED`, which is not retried, so the run stops before any query.

The server's hostname, database and MySQL version are printed in the startup banner and written to
`run-log.jsonl` as a `db-server` entry. After the first successful `--check-db`, copy the hostname it
prints into `DB_EXPECT_SERVER_HOSTNAME` to pin the script to that exact server.

A tunnel that drops during a run is error `TUNNEL_FAILED`, which `withDbRetry()` treats like any other
dropped connection: the pool is discarded and the next attempt reopens the tunnel, with the key and
without a prompt. Point `DB_SSH_KEY` at the key if it is not `C:\Users\ildar\.ssh\id_ed25519`.

### Silent stops, and what is logged now

On 2026-10-14 two runs ended with no error and no summary: one at 13:37 after 6 of 25 follows, one at
14:51 while waiting for the tunnel. Neither was stopped by hand. At the time ssh shared the
PowerShell window with the script, and a console passes Ctrl+C and close events to every process
attached to it. That is the most likely cause, though it was not proven. Two changes followed:

1. ssh runs detached in its own hidden console, so nothing it does reaches this process through the
   console.
2. Every way the process can end now prints an `EXIT` line and writes an `exit` entry to
   `run-log.jsonl`: finished, action blocked, stopped (any error), Ctrl+C, Ctrl+Break, console
   window closed, terminated, uncaught exception, unhandled rejection. The ssh process ending is
   logged too, as `ssh tunnel process ended` and a `tunnel-exit` entry, with its last error output.

If a run ever stops without an `EXIT` line, the process was killed from outside (Task Manager, a
crash of Node itself, or the PC sleeping).

The SSH tunnel also encrypts the traffic. `mysql2` connects in the clear by default, and MySQL on the
VPS never has to be exposed to the internet. The direct TLS connection that used to be the fallback
has been removed, because it was the one remaining way to give the script a host other than the
tunnel.

Tests: `node tests/remote-db-guard-test.js`. It loads the tunnel and guard code straight
from `instagram-follower.js`, so there is no copy to keep in step, and needs neither MySQL nor an SSH
server.

## Files

| File | Purpose |
|---|---|
| `instagram-follower.js` | The automation. Node + Playwright, about 2,130 lines. |
| `package.json` | Pins playwright, mysql2 and dotenv. `npm run login` / `dry-run` / `follow`. |
| `.env` | Remote database name, user and password, and the SSH tunnel settings. Copied from `.env.example`. Gitignored. |
| `.env.example` | Template, with every setting explained and the command that reads the database values from the VPS. |
| `pending-writes.jsonl` | Follow outcomes that could not be written to the database. Created only on failure. Replay with `--replay-journal`. Gitignored. |
| `tests/db-retry-test.js` | Tests for the retry helper. `node tests/db-retry-test.js`. |
| `tests/remote-db-guard-test.js` | Tests for the machine guard, the SSH tunnel, the server identity guard and the startup refusals. `node tests/remote-db-guard-test.js`. On a non-Windows machine the startup checks confirm the machine guard stops the script; the settings refusals are checked when it runs on the PC. |
| `tests/profile-likes-test.js` | Tests for the profile-likes action: the pure helpers, the pacing over 12 simulated hours, and `likeFromProfile` against a fake page and a fake database (already-liked posts, pending, accepted and declined requests, a like that does not register, an action block, a dry run). `node tests/profile-likes-test.js`. |
| `profile-likes-progress.md` | Plan, decisions and progress for the profile-likes action. |
| `archive/` | Old versions of the script and the obsolete `follow-targets.csv`. Gitignored, so it stays on this PC only. |
| `run-log.jsonl` | One JSON line per action, with a New York timestamp and outcome. Check pacing here after a run. |
| `run-screenshots/` | One screenshot per failure. First place to look when something goes wrong. |
| `instagram-selectors-notes.md` | The verified locators, and the three traps. |
| `instagram-follower-progress.md` | Build progress and resume point. |

## Flags

| Flag | Effect |
|---|---|
| `--login` | Opens Chrome and holds it open so Ildar can sign in by hand. Ctrl+C when done. |
| `--dry-run` | Does every navigation and every filler action, but never clicks Follow or Like and never writes to MySQL. Because nothing is written, it remembers which accounts it has opened and passes over them, so each pick is a different account (fixed 2026-10-14; before that a dry run reopened the first pending account every time). |
| `--follows N` | Stop after N follow attempts that consumed a cooldown. Default 100. |
| `--max-minutes N` | Also stop after N minutes, whichever comes first. For a short supervised run. |
| `--min-followers N` | **Default 50.** Only follow accounts with at least N followers. Requires enrichment data, so this restricts the queue to accounts in `ig_profile_stats`. `--min-followers 0` disables it and lets unenriched accounts back in. |
| `--min-posts N` | Also require at least N posts. Default 0, no filter. |
| `--only user1,user2` | Process only the named usernames. |
| `--cooldown-min` / `--cooldown-max` | Seconds between follow attempts. Default 180 and 300. |
| `--headless` | Headless. Off by default, and Instagram treats headless sessions more aggressively. Debugging only. |
| `--pause-on-fail` | Leave the browser open on a fatal error instead of exiting. |
| `--no-launch` | Do not start Chrome, attach to one already on port 9222. |
| `--no-warmup` | Skip the warm-up and go straight to the first follow. For testing, when the wait is in the way. |
| `--cdp` / `--chrome-profile` | Override the CDP endpoint or the profile directory. |
| `--replay-journal` | Apply `pending-writes.jsonl` to the database, then exit. Opens no browser. |
| `--check-db` | Open the tunnel, check the server is the VPS, print its hostname, the queue counts and the eligible count, the following-list figures and the profile-likes figures (accounts due, requests due, posts liked), then exit. Opens no browser and writes nothing. |
| `--profile-likes-per-hour N` or `N-M` | Profile likes per hour, drawn afresh each hour. Default `1-2`. The 20-minute gap, the 15-per-run maximum and the 10-likes-an-hour total still apply. |
| `--no-profile-likes` | Leave profile likes out of this run. Feed likes and follows are still recorded in `ig_post_likes` and `ig_own_following`. |
| `--profile-like-once` | Test mode: one profile visit (and one like, unless `--dry-run`), then exit. No follows. Exit code 1 if the like did not register, 2 on an action block. |

**The run is headed by default**, so the window is visible and every action can be watched.

**Warm-up.** Every run does 2 to 4 browsing actions (scrolling, a story, sometimes a like) before the
first follow. Added 2026-09-17 after the first live run went from browser launch straight to opening
a profile and clicking Follow. The filler actions only ran during cooldowns, so the opening move of
every session was the most conspicuous thing the account could do. `--no-warmup` turns it off for
testing.

Exit codes: 0 normal, 1 setup error, **2 action blocked**. A scheduled wrapper should treat 2 as
"stop for today", not as a retryable failure.

## What a run looks like

```
Account:   @verifiedbotanicals
Queue:     10857 pending, 0 followed, 0 requested, 0 error
Database:  sunny_kratom on vps-hostname, via SSH tunnel to 66.206.23.226
Target:    5 follows, cooldown 180-300s

[16:04:11] INFO   attaching to Chrome at http://localhost:9222
[16:04:14] FOLLOW opening @nichbotanical
[16:04:19] FOLLOW @nichbotanical: followed
[16:04:19] INFO   1/5 follows. Next in 4.2 min.
[16:04:33] STORY  watched ~2 from @tippytop2020
[16:05:10] LIKE   liked /p/DcY1QXEF8o2/ by /al.chl/
[16:06:02] SCROLL 3 steps
[16:07:41] READ   read comments on a post by /realhappyhippoherbals/
```

Every line also lands in `run-log.jsonl` as JSON, so pacing can be checked afterwards rather than
inferred from the console.

## Run pattern

```powershell
cd "C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper\instagram-follower"
npm install

# 0. Once: put DB_NAME, DB_USER and DB_PASS from the VPS into .env.
ssh -p 22222 root@66.206.23.226 "grep -A6 'db' /home/bizousoft/public_html/seo/ig-follower-scraper/config.php"
notepad .env

# 1. Confirm the script reaches the VPS database. Opens no browser.
node instagram-follower.js --check-db

# 2. Confirm the attached Chrome is signed in. Skip if it already is.
node instagram-follower.js --login

# 3. Nothing is clicked or written. Watch the window and read the log.
node instagram-follower.js --dry-run --follows 3

# 4. First real run, small and supervised.
node instagram-follower.js --follows 3

# 5. Once that looks right.
node instagram-follower.js --follows 25
```

No separate tunnel window is needed: the script opens the SSH tunnel itself and closes it on exit.

`npx playwright install chromium` is not needed: the script drives the real Chrome over CDP rather
than a Playwright-managed browser.

The script connects to the database before it opens a browser, and fails immediately with a message
naming the cause (tunnel, credentials, database name, or a local server refused) if it cannot use the
remote `sunny_kratom`.

## Build order

Selectors were verified live in the Playwright MCP on 2026-09-15, per the workspace SOP, before any
were written into the script. Writing Instagram selectors from assumption is what caused the full
rewrite of the Business Suite scheduler. Results, including three traps that would have broken a
script written from assumption, are in
[instagram-selectors-notes.md](instagram-selectors-notes.md). Three items remain unverified: the
private-account path, the action block dialog, and comment scrolling by mouse wheel.
