# Story tray investigation - progress / resume file

Started 2026-10-22. Hand-off: find out how to reach more Instagram stories than the visible tray shows,
signed in as @verifiedbotanicals, read-only. Output: `story-tray-findings.md` in this folder, screenshots
in `story-tray-findings-screenshots/`. No code changes, nothing committed.

## Status

Done 2026-10-22 11:19 (browser work) and report written. 11 accounts' stories opened. No block.
Report: `story-tray-findings.md`. Nothing committed.

2026-10-22 17:30: hand-off re-sent. Extra web search on "accounts with stories without the tray" added to
report section E3 (Apify cookie actor, instagram_private_api, outdated Medium claim). No browser work.
Still open, needs the operator's go-ahead: load the full following list (686 accounts, about 46 more
list requests) and compare the accounts marked with a story against the tray data, to test whether the
tray leaves out any followed account that has a story.

## Limits

- At most 25 accounts' stories opened in total, 1 to 2 frames each.
- 2 to 6 seconds between actions, about 45 minutes in total.
- Stop on any action block, login challenge or captcha.
- The follow script (`node instagram-follower.js`) must not run at the same time. Last run in
  `run-log.jsonl` finished 2026-10-20 16:10.

## Story accounts opened (running count)

1. kaitrudeau89 - clicked 11:06, story had expired (expiring_at 1792681576), page showed "Something went wrong ... Reload page", no frame shown
2. beenjosh - 1 frame, auto-advanced
3. veronica.r.flores1 - 1 frame, left by next-account card
4. kevin49206 - 1 frame, ArrowRight moved to next account
5. logan_rainer - 1 frame, auto-advanced
6. christopher_russo50 - 1 frame, left by card
7. painbcofficial - 1 frame, left by card
8. arisnaibrahim - 1 frame, Escape
9. morgainz1 - 1 frame (12th ring, last rendered), left by card
10. johndivinewellness - 1 frame (13th in tray data, not rendered), Escape
11. glassman420 - opened by direct URL /stories/glassman420/ after the "View story" confirmation, 1 frame, viewer returned to /

## Checklist

- [x] Operator confirms no follow run is active (2026-10-22 10:57)
- [x] Online research: other ways to see which followed accounts have active stories
- [x] A. Tray: ring count, chevron, container scroll, seen/unseen markers, reorder, reload
- [x] B. Viewer walk (B4 end-of-list open): sequence beyond the rendered rings, next-account jump, end of list, Escape
- [x] C. Network: tray request path, account count, fields, sanitized sample
- [x] D. Direct `/stories/<username>/` URL, with and without an active story; profile ring
- [x] E. Following list rings, other pages, pacing notes
- [x] Write `story-tray-findings.md`

## Findings so far

Session started 10:58 in a new Playwright MCP tab (the MCP browser, viewport 1359 x 906). Screenshots
are written with page.screenshot() from browser_run_code_unsafe, because the MCP screenshot tool only
writes to its Temp folder.

- A1 (verified): fresh load, 12 ring buttons rendered, `ul` has 13 `li` (12 rings plus a 1 px spacer
  `li` at translateX(13727px)). Each `li` is translateX(n * 104px). The tray is a virtualized list.
- A4 (verified, contradicts notes section 2): on a fresh load, before any viewer was opened, every ring
  already had `aria-label="Story by <u>, not seen"`.
- C (verified): the full tray is embedded in the home page HTML, in a `<script>` holding
  `RelayPrefetchedStreamCache` for `adp_PolarisStoriesV3TrayContainerQueryRelayPreloader_...`, key
  `xdt_api__v1__feed__reels_tray`. It had 132 entries, all `reel_type` user_reel, ranked_position 1..132,
  4 muted (ranks 129-132), 10 with seen > 0 but seen < latest_reel_media. No network fetch of the tray
  happened on load; graphql calls on load were IGDOmniPickerNullStateListQuery, IGDBadgeCountOffMsysQuery,
  IGDChatTabsJewelOffMsysQuery, QuickPromotionSupportIGSchemaBatchFetchQuery x2,
  PolarisStoriesV3AdsPoolQuery (/graphql/query), PolarisAPIGetFrCookieQuery, plus
  POST /api/v1/web/fxcal/ig_sso_users/.
- A2 (verified): tray Next control is `button[aria-label="Next"]`, a direct sibling of the scroller
  `div[role="presentation"]`, 45 x 57 at x 743 y 50. Selector used:
  `div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]`. Each click scrolls
  416 px (4 rings). 32 clicks reached the end (scrollLeft 13098); the Next button is then removed.
  `button[aria-label="Go back"]` appears once scrolled. Old rings are removed from the DOM (virtualized,
  12 to 18 li). The last ring was camyarlenem, the last of the 132 data entries.
- A3 (verified): scroller `div[role="presentation"]` (630 px wide, scrollWidth 13737) scrolls with
  scrollTo, scrollBy, Shift + mouse wheel, and horizontal mouse wheel. All re-render rings.
- Muted accounts (4, ranks 129-132) render with aria-label `..., seen` although their data `seen` is 0.
- B (verified): viewer has neighbour preview cards `a[href^="/stories/<user>/<storyId>/?r=1"]`, 189 x 336,
  text `<user>|<age>` or `<user>|Ad`. Clicking the card to the right of centre jumps straight to that
  account (URL confirms). An ad (marcuszanquilaofficial, text ends `Ad`) is inserted in the sequence.
  Current account: URL `/stories/<user>/[<id>/]` and header link `a[href="/<user>/"]`.
- B1 (verified): from ring 12 (morgainz1, last rendered) the next card was johndivinewellness, rank 13 in
  the tray data and not rendered in the DOM. The viewer follows the data order, not the rendered rings.
- A5 (verified): after Escape, URL back to `/`, tray order unchanged (seen beenjosh still first),
  scrollLeft 0. Labels updated: beenjosh, kevin49206, logan_rainer, arisnaibrahim `seen`;
  veronica.r.flores1, christopher_russo50, painbcofficial (left mid-account by card) `not seen`.
- A6 (verified): reload changed the tray: kaitrudeau89 gone (expired), beenjosh new at rank 1, 131 entries.
- Expired story: clicking a ring whose story expired after page load gives URL `/stories/<user>/`,
  title `Page couldn't load • Instagram`, body "Something went wrong ... Reload page".
- D1 (verified): goto `/stories/glassman420/` shows an interstitial first: "View as verifiedbotanicals?
  glassman420 will be able to see that you viewed their story." with `div[role="button"]` text `View story`.
  After clicking it the frame plays, there are NO neighbour preview cards, and after the last frame the
  viewer went to `https://www.instagram.com/` (no auto-advance to another account).
- E (verified): own profile `following` count is `header a[role="link"][href="#"]` text `686 following`
  (no longer an href to /following/). Dialog `[role="dialog"]` loads 12 rows per batch via
  GET /api/v1/friendships/<pk>/following/?count=12&max_id=<n> (+ POST /api/v1/friendships/show_many/).
  Scroller is the inner div with overflow-y auto (560 x 309); mouse wheel over it loads more. 10 wheel
  steps loaded 132 rows. Response `users[]` has `username`, `pk`, `is_private`, `latest_reel_media` (0 = no
  story), plus `big_list`, `page_size` 12, `next_max_id`, `has_more`.
- E (verified): row avatar with active story = `div[role="button"][tabindex="0"]` (no aria-disabled) >
  `span[role="link"]` > img (no `a`). Without story = `div[role="button"][aria-disabled="true"]` >
  `a[role="link"][href="/<u>/"]` > img. 21 of the first 132 rows had a story. Ring canvas 55 x 55: pixel
  at (27,1) is magenta/orange gradient (223,1,173) when unseen, grey (219,223,228) when seen or muted,
  transparent when no story.

## Following-list vs tray check (started 2026-10-22 17:29)

- Operator said go ahead 17:28. run-log.jsonl last entry 2026-10-20 16:10, so no follow run active.
- New Playwright MCP tab (tab 1). Another session uses the same MCP browser (Google Maps tab 0, Prohibition
  article), so every step finds the Instagram tab via page.context().pages() and calls bringToFront().
- Tray before: 130 entries at 1792704573 (17:29), 5 muted (ranks 126-130). No stories opened in this check.
- Following dialog opened 17:30 and reopened 17:31 (first listener's data was lost: run_code gives each call a
  fresh globalThis; data is now stored on the Page object as ig.__s).
- Real dialog scroller is a classed div with no style attribute, 560 x 309, found by computed overflow-y auto
  and scrollHeight > clientHeight. The `div[style*="overflow: hidden auto"]` inside it is 720 px tall and does
  not scroll. Rows WITHOUT a story also have a 55 x 55 canvas; aria-disabled on the avatar button is the marker.
- Bridge timeout is 60 s per call: keep scroll batches under about 45 s.
- 17:35: all 685 following rows loaded in 58 requests, no warning. Saved following-check/following-list.json.
- 17:36: tray after 129. All 130 followed accounts with a story were in the 17:29 tray; the only one missing at
  17:36 (zachtion_bronson) expired at 17:33:59. Tray = complete list. Saved following-check/tray-after.json.
- 17:38: opened emmyrobbindoula (rank 1, 1 frame of 12, Escape) to record viewer HTML. Test view 12 of 25.
- 17:40: closed the Instagram tab. Report sections 6 (comparison) and 7 (element reference for the script)
  written, summary table and E1 updated. DONE.
