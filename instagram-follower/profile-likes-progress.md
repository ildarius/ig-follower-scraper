# Profile likes: progress and resume file

Feature: a filler action in `instagram-follower.js` that opens a random account @verifiedbotanicals
follows and likes one of its posts. Planned with Ildar on 2026-10-15.

## Settled decisions (2026-10-15)

| Rule | Value |
|---|---|
| Accounts eligible | Accounts @verifiedbotanicals follows: the 2026-10-14 following-list scrape (521 accounts), plus accounts the script follows, plus `requested` accounts found to show `Following` when checked |
| How acceptances are found | The profile button. A `requested` account is picked like any other once 14 days have passed; `Following` means accepted (row becomes `followed`, like goes ahead), `Requested` means not yet (recheck in 14 days). No notifications page, no regular Apify reruns |
| Manual follows | Very few expected. An occasional Apify following-list rerun covers them; no extra code |
| Rate | 1 to 2 profile likes an hour, at least 20 minutes apart, at most 15 per run |
| Revisit the same account | Not within 14 days |
| Which post | Random among the 6 most recent grid tiles; pinned posts and reels included |
| Already liked | Never clicked: on-page `Like`/`Unlike` check, plus a database record of every post the script liked. One retry on another post, then move on |
| Total likes, feed and profile | At most 10 an hour |

## Steps

- [x] 1. Verify live in the Playwright MCP, clicking no Like: profile grid tiles and their order, pinned
      tile marker, reel tile marker, what clicking a post tile and a reel tile opens, the like button
      in each, the private-profile state, the `Requested` / `Following` buttons.
- [x] 2. PHP app: `ig_own_following` table filled by the following-list import; table of posts liked.
- [x] 3. Fill `ig_own_following`: deploy, then `?action=migrate` and `?action=own-following-rebuild`
      (from run 19's stored items, no Apify rerun needed). Expect 521 rows.
- [x] 4. Script action, flags (`--profile-likes-per-hour`, `--no-profile-likes`, `--profile-like-once`),
      dry-run support, tests.
- [x] 5. Dry run, then a short real run.

## Log

- 2026-10-15: plan settled. Step 1 started.
- 2026-10-15: step 1 done. Findings in section 6 of `instagram-selectors-notes.md`: tile selector and
  markers, every tile (photo, pinned, reel) opens a dialog at `/p/<code>/`, the action-bar selector that
  matches exactly one bar in all four cases checked, and the Following / Requested / private states.
  @logan_rainer (recorded `requested`) already shows `Following`: a live case for the acceptance check.
- 2026-10-15: step 2 done. `src/OwnFollowing.php`: `ig_own_following` (ig_user_id, username,
  full_name, is_private, source, first/last seen, last_visited_at, last_liked_at, visit_note),
  `ig_post_likes` (post_code unique, owner_username, source profile/feed, liked_at), and
  `ig_accounts.request_checked_at`. The own-following import upserts each account; new admin action
  `own-following-rebuild` refills the table from `ig_raw_items` of a stored run, so step 3 needs no
  Apify rerun. `tests/own-following-test.php`: 34 checks pass (16 new), PHP 8.3, SQLite.
- 2026-10-15 12:11: step 3 done on the VPS. `migrate` created both tables and added `request_checked_at`;
  `own-following-rebuild` inserted 521 rows from run 19. Step 4 started.
- 2026-10-15: step 4 done in `instagram-follower.js`. Two new sections before the machine guard:
  "profile likes: pure helpers" (`parseRange`, `postCodeFromHref`, `visitDecision`, `profileLikeGate`,
  `shuffled`) and "profile likes: browser and database" (`pickProfileTarget`, `likeFromProfile`, and
  best-effort writes to `ig_own_following`, `ig_post_likes` and `ig_accounts.request_checked_at`).
  Main loop: a `plike` filler option gated by `profileLikeGate`; feed likes now count only when a like
  actually happened, and both kinds share the 10-an-hour total. Feed likes are recorded in
  `ig_post_likes` (source `feed`); every follow or already-following result is added to
  `ig_own_following` (source `follow_script`). Banner, run summary and `--check-db` show the
  profile-likes figures. `--profile-like-once` does one visit and exits, for step 5.
  Writes are skipped when the tables are missing (banner says so) and in a dry run.
  `tests/profile-likes-test.js`: 96 checks pass, and the other two suites still pass. The tests found
  one gap, fixed: a pending request whose profile showed no button was not marked as checked, so it
  would have been picked again on every run. Backup of the script before step 4:
  `archive/instagram-follower.js.bak-pre-profile-likes`. Step 5 next: Ildar runs
  `--profile-like-once --dry-run`, then `--profile-like-once`, on the PC.
- 2026-10-15 12:38 to 12:40: step 5 live on the PC. Dry run: picked @jenspidey, opened post
  DXGSGmwlx33, clicked nothing. Real run 1: picked @jennifer.ellison (pending request), button still
  `Requested`, nothing clicked, `request_checked_at` written (requests due went from 80 to 79 on the next
  banner). Real run 2: liked post DPRn4wxiVW9 by @kavamamastore. `--check-db` at 12:44 confirmed it:
  520 of 521 followed accounts due, 79 pending requests due, posts liked 1 recorded, 1 today. Step 5 done;
  the feature is complete and on by default in daily runs.

## Known small gaps (not fixed, noted 2026-10-15)

- 8 accounts in the following list still have `follow_status = 'requested'` in the queue (accepted
  before the 2026-10-14 scrape). They get profile likes as followed accounts, but their queue status
  stays `requested`, because the acceptance check only runs for requests not in `ig_own_following`.
- 25 queue rows marked `followed` are not in the stored following list: follows made after the
  2026-10-14 scrape and before profile likes existed. They are not in `ig_own_following`, so they get
  no profile likes until the next following-list import. Follows from now on are added automatically.
