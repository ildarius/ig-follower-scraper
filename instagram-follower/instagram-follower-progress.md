# Instagram follower - progress / resume file

Project description: [README.md](README.md)
Started 2026-09-15. Status: **script written 2026-09-17, syntax-clean, not yet run against Instagram.**

Resume rule: read this file and `README.md`, then continue from the first unchecked item.

---

## Phase 0 - decisions

| # | Decision | Status |
|---|---|---|
| 0.1 | Account: @verifiedbotanicals | ✅ settled 2026-09-15 |
| 0.2 | Auth: CDP attach to `C:\chrome-automation-profile` on port 9222, shared with the Record scheduler | ✅ settled 2026-09-15 |
| 0.3 | Daily target: 100 follows, cooldown 3 to 5 min, run length about 6.5 to 7.5 h | ✅ settled 2026-09-15 |
| 0.4 | Comment reading is dwell time only, nothing stored | ✅ settled 2026-09-15 |
| 0.5 | One continuous run per day, or split into sessions (for example 4 x 25 follows on a schedule)? | ⬜ open |
| 0.6 | Ramp schedule from 20 to 30 per day up to 100. Which day does the ramp start? | ⬜ open |
| 0.7 | Source for the follow-target usernames: @american_kratom_assoc followers, scraped via Apify by [ig-follower-scraper](../README.md) | ✅ settled 2026-09-16 |
| 0.8 | Queue lives in MySQL `sunny_kratom.ig_accounts`, not a CSV | ✅ settled 2026-09-16 |

## Phase 1 - selector verification (Playwright MCP, live)

Done 2026-09-15. Full write-up: [instagram-selectors-notes.md](instagram-selectors-notes.md).
Signed in to the MCP browser as @verifiedbotanicals, no CAPTCHA, no checkpoint.

- [x] Story tray: open, advance, exit. Tray buttons carry `aria-label="Story by <user>, seen|not seen"`, so unseen filtering is a DOM read. Escape exits the viewer.
- [x] Feed post like button: `svg[aria-label="Like"]` vs `"Unlike"`, on a `div[role="button"]` 3 levels up. Flip confirmed by a real like on `/p/DcY1QXEF8o2/`.
- [x] Profile Follow button: `header button[type="button"]`, innerText `Follow` / `Following`. Flip confirmed by a real follow of `/painbcofficial/`.
- [x] Not-found profile: no `header` element, text "Sorry, this page isn't available.". Title is unreliable.
- [x] Post modal comment list: `[role="dialog"]` with a scrollable inner `ul`. A direct `/p/<code>/` navigation gives a full page with no dialog instead, so both shapes need handling.
- [x] Ad detection: ads have no `/p/` or `/reel/` permalink. The label renders as "Ad", not "Sponsored", and is localized.
- [x] Profile grid hrefs are `/<user>/p/<code>/`, not `/p/<code>/`. `a[href^="/p/"]` returns 0 on a profile page.
- [ ] **Private account:** none available during the probe. Expect `Follow` then `Requested`. Confirm on the first live run.
- [ ] **Action block dialog:** did not fire. Text-match detection plus a Follow-button revert check is specified but unproven.
- [ ] **Comment scrolling:** `ul.scrollBy()` from page JS did not move the list. Use `page.mouse.wheel` over the comment column and confirm at build time.

Two traps recorded in the notes file:

1. `"Following"` contains `"Follow"`. A substring match selects an already-followed profile and the click opens the unfollow menu. Compare trimmed innerText with `=== 'Follow'`.
2. Playwright's `:text-is("Follow")` does not match, because the text sits in a child div rather than on the button.

Real actions taken on the account during the probe: one like (al.chl), one follow (Pain BC, a listed
target in `chronic-pain-instagram-canada.md`), two stories watched. None need reverting.

## Phase 2 - script - DONE 2026-09-17

`instagram-follower.js`, 726 lines, passes `node --check`. Headed by default.

| # | Decision | Status |
|---|---|---|
| 1.0 | Follow only accounts with **50 or more followers** | ✅ settled 2026-09-17 |

`--min-followers` defaults to 50. Because an unenriched account has no follower count, this makes
the queue query an INNER JOIN on `ig_profile_stats`, so only enriched accounts are eligible.
`--min-followers 0` restores the LEFT JOIN and lets unenriched accounts back in.

Measured immediately after the decision: **2,130 of 10,857 pending accounts qualify**, which is 21
days at 100 follows a day. The 8,357 unenriched accounts cannot qualify until they are enriched, and
that waits on the 2026-10-17 credit reset. Enrichment coverage now caps the follow script's runway.

Notes on choices that are not obvious from the code:

- The queue query is an **INNER JOIN** when `--min-followers` is above 0, and a **LEFT JOIN** when it
  is 0. The banner says which is in effect and prints the eligible count.
- `--min-posts N` filters only rows that have stats. A row with no stats always passes.
- An account already showing "Following" or "Requested" records its status but **does not consume a
  cooldown slot**, because no follow request was sent.
- Action-block detection is two signals: the text match, and the Follow button reverting within
  seconds of a successful-looking click. Either one halts the run and exits **2**.
- The sign-in check runs before any action, so a signed-out Chrome fails with instructions instead
  of clicking blindly.

### Superseded checklist

## Phase 2 - script (original plan)

- [ ] `package.json`, playwright pinned.
- [ ] Chrome launch / CDP attach, copied from `../../the-record-scheduler/schedule-record-series.js`.
- [ ] `--login` flow.
- [ ] MySQL connection via `mysql2`, `.env` for credentials.
- [ ] Queue read (`WHERE follow_status = 'pending' ORDER BY id LIMIT 1`) and per-attempt `UPDATE`.
- [ ] Cooldown timer, 180 to 300 s random, measured from attempt.
- [ ] The five actions as separate functions.
- [ ] Action scheduler: random order, per-hour budgets for likes, stories and comment reads.
- [ ] Action block detection, halt and non-zero exit.
- [ ] `run-log.jsonl` writing.
- [ ] Failure screenshots into `run-screenshots/`.
- [ ] Remaining flags: `--dry-run`, `--follows`, `--only`, `--headless`, `--pause-on-fail`, `--no-launch`.

## Backfill of manual follows, 2026-09-17

About 190 accounts had already been followed by hand between 2026-09-10 and 2026-09-17, tracked in a
Google Sheet. Imported into `ig_accounts` via
`ig-follower-scraper/api.php?action=import-followed&apply=1`, so the script does not re-follow them.

187 rows updated: 103 `followed`, 86 `requested` (from the sheet's "Follow Requested" column), each
carrying the sheet's date and a note marking it as hand-entered. One handle was not in the queue,
one had already been set by the script itself.

Eligible accounts at the 50-follower default fell from 2,130 to 1,943, so runway is now 19 days at
100 a day rather than 21.

**Any future manual following must be imported the same way, or the script will duplicate it.**

## First live run, 2026-09-17

The script was run once at 16:41 New York time. It touched exactly one row: `stephaniepitts76`,
recorded as `already_following`. That is correct behaviour on two counts. The account had been
followed by hand on 09-11, and an account already showing "Following" records its status without
consuming a cooldown slot, because no follow request was sent.

A second run at 16:53 opened `sheilafmckay` (id 7) and also recorded `already_following`.

Two findings from those runs:

**1. The follow tracker is incomplete.** `sheilafmckay` was followed by hand and never recorded in
the sheet, so the database had her as pending. The queue is ordered by `id`, which is Apify's return
order for AKA's follower list, and the manual following went down that same list, so the top of the
queue is mostly accounts already followed. Of the first 40 rows, 27 are already handled. The script
resolves these correctly and without spending a cooldown, so the only cost is that the first minutes
of a run look like it is doing nothing new.

**2. There was no warm-up, which was a design flaw.** The run went from launching the browser
straight to opening a profile and clicking Follow. The filler actions only ran during cooldowns, so
the opening move of every session was the most conspicuous one, which defeats the reason the filler
exists. Fixed 2026-09-17: every run now does 2 to 4 browsing actions first. `--no-warmup` skips it
for testing.

No successful new follow, `requested`, or action block has been observed yet. Phase 3 below is still
open.

## Remote database only, 2026-10-13

Ildar asked for the script to always read from the database on seo.bizousoft.com and never from
Laragon. Before this change it connected to whatever `.env` named, and `.env` still held the Laragon
defaults (`127.0.0.1:3306`, `root`, empty password), so a run would have followed from the local copy
of the queue.

Changed in `instagram-follower.js` (backup of the previous version: `instagram-follower.js.bak-pre-remote-only`):

- The script opens its own SSH tunnel to `root@seo.bizousoft.com` on local port 3307 and closes it on
  exit. No separate tunnel window is needed.
- `DB_HOST`, `DB_PORT` and the `DB_SSL*` settings are no longer read. Local port 3306 is refused.
- `DB_NAME`, `DB_USER` and `DB_PASS` have no defaults and must come from the VPS `config.php`.
- Every new connection pool is checked with `@@hostname`, `@@version_compile_os` and `@@datadir`, and
  refused (`LOCAL_DB_REFUSED`, not retried) if the server is a Windows build, has a Windows or
  Laragon data directory, has this PC's hostname, or does not match `DB_EXPECT_SERVER_HOSTNAME`.
- New `--check-db` flag. The standalone `check-db.js` added earlier the same day was removed.
- The direct TLS connection option was removed.

`.env` was replaced with the remote template. `DB_NAME`, `DB_USER` and `DB_PASS` are blank, so the
script refuses to start until they are filled in.

Tests: `tests/remote-db-guard-test.js` (new, 27 checks) and `tests/db-retry-test.js` both pass in the
Linux workspace on the PC. Nothing has been run against the real VPS yet.

- [ ] Fill `DB_NAME`, `DB_USER`, `DB_PASS` in `.env` from the VPS `config.php`.
- [ ] `node instagram-follower.js --check-db` against the VPS, then put the printed hostname into
      `DB_EXPECT_SERVER_HOSTNAME`.
- [ ] Set up an SSH key for `root@seo.bizousoft.com`, so a tunnel dropped mid-run can be reopened
      without a password prompt.

## Moved into ig-follower-scraper, 2026-10-14

Ildar asked to consolidate everything under the `ig-follower-scraper` folder, which is the git
repository (github.com/ildarius/ig-follower-scraper). This folder used to be
`Sunny Kratom\instagram-follower\`, outside version control, and is now
`Sunny Kratom\ig-follower-scraper\instagram-follower\`.

- Copied: the script, `package.json`, `package-lock.json`, `.env`, `.env.example`, `.gitignore`,
  the three markdown files, both tests, `run-log.jsonl` and `node_modules`. Every copied file was
  compared byte for byte with the original before any edit.
- `archive/` (gitignored) holds `instagram-follower.js.bak-preremote`,
  `instagram-follower.js.bak-pre-remote-only` and the obsolete header-only `follow-targets.csv`.
- Relative links fixed: `../ig-follower-scraper/README.md` is now `../README.md`, and
  `../the-record-scheduler/` is now `../../the-record-scheduler/`.
- New machine guard, `assertRunningOnPc()`, because the repository is deployed to the VPS. See
  "Where the script may run" in the README.
- The repository's `.htaccess` now blocks `instagram-follower/` from the web, and its `.gitignore`
  ignores `desktop.ini`.
- Nothing has been committed or pushed. The old `Sunny Kratom\instagram-follower\` folder is left in
  place for Ildar to delete.

- [x] `git pull` the two commits on remote master (5375e89, 85e6113), then commit this folder. Done 2026-10-14 as 07ecc60.
- [x] `--check-db` against the VPS passed 2026-10-14: `bizousoft_ig_analytics` on `66-206-23-226.boulat.net`, 6,836 eligible. SSH as `bizousoft` on port 22222 with the PC's `id_ed25519` key; `127.0.0.1` added to cPanel Remote Database Access so the tunnel's connection is accepted.
- [x] Dry-run fix: a dry run now excludes accounts it already opened, instead of reopening the first pending account on every pick.
- [x] First real runs 2026-10-14, results confirmed in the VPS database by the queue counts: the
      13:31 run handled 15 accounts (4 followed, 3 requested, 8 already_following), and the next run's
      banner showed pending 6,838 to 6,823, followed 108 to 112, requested 87 to 90.
- [x] Profile detection fix: wait up to 15 s for a follow-state button or "Sorry, this page isn't
      available." instead of a fixed 2.5 to 4.5 s, which misread a slow load as a missing profile.
- [x] Silent stops at 13:37 and 14:51 (no error, no summary, not stopped by hand). ssh now runs
      detached in its own hidden console with BatchMode=yes, its exit is logged, and every way the
      process ends prints an EXIT line and writes an `exit` entry to `run-log.jsonl`. Cause not proven;
      next silent stop will show it.
- [ ] Watch the next runs for `EXIT` lines and `tunnel-exit` entries.
- [x] Own following list marks already-followed accounts (2026-10-14). Many queue rows had been
      followed by hand and never recorded, so the script spent visits finding "Following". The PHP
      app's import now treats a `following` run of `verifiedbotanicals` (config `own_account`) as a
      marker: matching `pending` rows become `already_following`, no rows are added. Code in
      `src/Scraper.php` (`isOwnFollowingRun`, `markAlreadyFollowing`), test `tests/own-following-test.php`
      (18 checks, SQLite, passes). @verifiedbotanicals followed 543 accounts, about $0.62 to scrape.
- [ ] Deploy to the VPS, then run the following scrape from the dashboard (steps in the repo README).
- [x] Follow-button fix: the first dry run (2026-10-14) recorded `unexpected button text` on @augustinafinejewelry, @therealmomview and @thesystemtna. Their bio links button comes before Follow in the header. Verified live and fixed with `findFollowButton()`; see the correction in `instagram-selectors-notes.md`.
- [ ] After the VPS pulls the commit, confirm `node instagram-follower/instagram-follower.js --check-db`
      on the VPS prints STOP and exits 1, and that `instagram-follower/package.json` returns 403 in a
      browser.

## Phase 3 - test

- [ ] `--dry-run` full pass, no clicks, no MySQL writes.
- [ ] Live run with `--follows 3`, verify `follow_status` and New York `follow_attempted_at` values in MySQL.
- [ ] Verify an already-followed account records `already_following` and does not start a cooldown.
- [ ] Verify a bad username records `error` with `profile not found`.
- [ ] Ramp run at 20 to 30 follows, check for action blocks the next day.

## Phase 4 - scheduling

- [ ] Decide continuous vs split sessions (0.5).
- [ ] Add as a scheduled task once a full-length run has completed cleanly by hand.

## Dependency - SATISFIED 2026-09-17

The queue is populated by [ig-follower-scraper](../README.md), and it is now
full: **10,857 rows in `sunny_kratom.ig_accounts`, all `follow_status = 'pending'`**, every one a
follower of @american_kratom_assoc. At 100 follows a day that is 108 days of runway.

**4,550 of them (42%) are private.**

| # | Decision | Status |
|---|---|---|
| 0.9 | Private accounts stay in the queue and get followed | ✅ settled 2026-09-17 |

Ildar decided to follow private accounts rather than filter them. Instagram converts a follow of a
private account into a follow request, so roughly 42% of attempts will record `requested` rather
than `followed`. A request consumes a daily action allowance exactly like a follow, so the 100-a-day
ceiling and the 3-to-5 minute cooldown are unchanged. The queue query must not carry
`AND is_private = 0`.

Still undecided: whether to exclude `posts_count = 0` accounts, which are 15% of the queue and are
empty or abandoned. That filter is independent of the private question.

## Open issues

- Total following cap is 7,500. At 100 per day that is reached in about 75 days. An unfollow pass is
  not in scope here and will need its own script.
- The Record scheduler uses the same Chrome instance and port. Two runs at once will fight over tabs.
  If both end up scheduled, they need non-overlapping windows.
