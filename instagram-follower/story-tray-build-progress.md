# Story tray rebuild: progress

Rebuild of `watchStories()` in `instagram-follower.js` so story sessions spread over many different
followed accounts with no repeats. Evidence: `story-tray-findings.md`. Started 2026-10-22.

Built on the VPS copy of the repository (`/home/bizousoft/public_html/seo/ig-follower-scraper`), on
top of commit 81abfa4. Nothing committed: Ildar reviews the diff first.

## Where the build differs from the hand-off

The hand-off was written against an older copy of the script, from before commit 9d03e3e (story
settings, 2026-10-22 10:23). Reviewed with Codex before building:

1. `--stories-per-session` already existed. It now means the frames watched from the one account of
   a session, and replaces the hand-off's `STORY_FRAMES_MAX = 3` (same default, 1 to 3). The README's
   `--stories-per-hour` is correct: that flag exists now, so it was not changed to "set in the script".
2. `--story-seconds` already existed and still sets the time per frame. Without it the time is the
   hand-off's 3 to 7 s (it was 2.5 to 6 s).
3. The result keeps the existing shape: `opened` is the yes/no the hand-off calls `watched: true`,
   and `watched` is the number of frames, as the call sites in `main()` already expect. The call
   sites needed no change: they already count only opened sessions.
4. Codex: the viewer opens at the first unwatched frame, so for a partly watched account
   (`seen > 0`) the frame count is not what is left. Such an account gets one frame and no
   ArrowRight. The label fallback also watches one frame, because it has no `seen` data.
5. Codex: when the viewer shows a different account than the one clicked, both are remembered.
6. Codex: the URL is checked before each ArrowRight and about a second after it, not only after the
   dwell, so time on another account or an ad stays under a second.
7. Codex: `nextClicksFor(rank)` is a hint (the pixel geometry was measured at one window size), so
   up to 2 more Next clicks are allowed, still stopping as soon as the ring is on screen.
8. Codex: usernames are compared lowercased; an unreadable `story-watched.json` is renamed to
   `story-watched.json.bad` instead of being overwritten. Not adopted: keying the file by the
   signed-in account, because the script only ever runs as @verifiedbotanicals (`ACCOUNT`).

## Checklist

- [x] 4.1 Old `watchStories()` removed; sections `stories: pure helpers` and `stories: browser` added
      before `chrome plumbing`
- [x] 4.2 Constants next to `STORIES_PER_HOUR`; `story-watched.json` gitignored (with `.tmp`, `.bad`)
- [x] 4.3 `parseTrayJson`, `pickStoryTarget`, `nextClicksFor`, `storyUserFromUrl`, `pruneWatched`
      (plus `parseRingLabel` for the fallback)
- [x] 4.4 State file: loaded once, pruned on every call, written at ring click (temp file and rename),
      errors logged as WARN
- [x] 4.5 `readTray`, `bringRingIntoView`, `countFrames`, `watchStories`
- [x] 4.6 Label fallback, WARN once per run
- [x] 4.7 Call sites: already correct (budget always spent, count only when opened)
- [x] 4.8 `--story-once` (exit 0 watched, 1 none, 2 action block)
- [x] 4.9 Header comment
- [x] 6 `tests/stories-test.js`: 99 checks pass. Three deliberate bugs (no watched filter, no
      remembering at click, no action-block check) each made it fail
- [x] 8.1 README, 8.2 selectors notes section 2, 8.3 this file
- [ ] 8.4 Master `CLAUDE.md` on the PC: not reachable from the VPS; update it there
- [x] 7 Live verification on the PC, 2026-10-22 20:23 to 20:25: `node tests\stories-test.js` 99 passed on
      the PC; three `--story-once --no-warmup` runs exited 0 with three different accounts, all chosen
      from the tray data (124 accounts). The Playwright MCP selector check was skipped: the runs used the
      same selectors on the live page.
- [ ] Not yet seen live: tray Next clicks (all three picks were rank 1 or 2), and more than one frame
      from an account (each pick had 1 frame left or was partly watched). The first normal run will
      show both in `run-log.jsonl` (`rank` above 6, `watched` above 1).

Existing suites: `db-retry-test` passes. `profile-likes-test` passes, but its random 12-hour pacing
check "at least 20 min between likes" failed once in six runs, with and without this change.
`remote-db-guard-test` has one check that fails on Linux with and without this change; it is meant
to be run on the PC.

## Story views while building

Budget for the build and its tests: 10 accounts (12 were used on 2026-10-22 by the investigation).

| # | Account | When | How |
|---|---|---|---|
| 1 | emmyrobbindoula | 2026-10-22 20:23 | `--story-once`, rank 1 of 124, 1 of 10 frames. Most likely treated as partly watched (one frame only), because the investigation opened it at 17:40 |
| 2 | alexa.covington | 2026-10-22 20:24 | `--story-once`, rank 2 of 124, 1 of 1 frames |
| 3 | adailydoseofmelrose | 2026-10-22 20:25 | `--story-once`, rank 1 of 124 after the reload, 1 of 1 frames |

3 of the 10 test views used.
