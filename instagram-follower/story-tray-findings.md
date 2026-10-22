# Story tray findings: reaching more stories than the visible tray shows

Investigated 2026-10-22 in the Playwright MCP browser on the PC, signed in as @verifiedbotanicals,
desktop Chromium, viewport 1359 x 906, English UI. Browser time 10:58 to 11:19 local (about 21
minutes). The operator confirmed beforehand that no `node instagram-follower.js` run was active; the
last run in `run-log.jsonl` had finished 2026-10-20 16:10.

No code was changed and nothing was committed. Screenshots are in `story-tray-findings-screenshots/`.
Nothing was liked, followed, replied to or sent. No action block, login challenge or captcha appeared.

Every claim is marked **verified** (seen in this session) or **not verified**.

---

## 1. Summary

| Approach | Works | Status | Distinct accounts reached | Selector or URL |
|---|---|---|---|---|
| Tray Next chevron | yes | verified | 132 of 132 in the tray list (32 clicks, 4 rings per click) | `div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]` |
| Tray container scroll | yes | verified | any position (scrollTo, scrollBy, Shift + wheel, horizontal wheel) | `div[role="presentation"]:has(ul li canvas)` |
| Viewer walk (auto-advance / ArrowRight) | yes | verified | follows the full tray list, including accounts never rendered as rings | `/stories/<user>/<storyId>/` |
| Next-account jump in the viewer | yes | verified | 1 click per account, no frame stepping | `a[href^="/stories/<user>/"]` preview card right of centre |
| Network list (page's own data) | yes | verified | 132 accounts on the first load, 131 on later loads | embedded in the home HTML as `xdt_api__v1__feed__reels_tray`, not fetched |
| Direct `/stories/<username>/` URL | yes, with an extra confirmation click | verified | 1 account per load, no auto-advance | `https://www.instagram.com/stories/<username>/` |
| Profile avatar ring | yes, as a has-story marker | verified | 1 account per profile visit | `header div[role="button"][tabindex="0"] > span[role="link"] img` plus a 165 x 165 canvas |
| Following list rings | yes, as a has-story marker | verified | all 685 followed accounts loaded (58 requests): 130 had a story, all 130 were in the tray data (section 6) | `[role="dialog"] div[role="button"]:not([aria-disabled="true"]) span[role="link"] img` |

The short answer: the tray is not limited to 12 to 17 accounts. It is a virtualized list. The page
receives the complete list (132 accounts at 10:58) inside the home page HTML and only renders the rings
that are near the visible part of the tray. Every approach below can reach the whole list.

---

## 2. Answers

### A. The tray itself

**A1. Rings on a fresh load, and their order (verified).** On a fresh load of `https://www.instagram.com/`
there were 12 ring buttons (`ul li div[role="button"]:has(canvas)`) inside a `ul` with 13 `li`
elements. The 13th `li` is a 1 px spacer positioned at `transform:translateX(13727px)`. Each ring `li`
is positioned with `transform:translateX(n * 104px)`, so the `ul` is 13,728 px wide for 132 accounts
while only 12 to 18 rings exist in the DOM at any moment. Screenshot: `a1-home-fresh-load.png`.

Order: unseen first, then fully seen, verified on the third page load of the session. After viewing
some accounts and reloading, the 131-entry list had 124 unseen accounts at ranks 1 to 120 and 128 to
131, and the 7 fully seen accounts at ranks 121 to 127. On the first load the 4 muted accounts were the
last 4 entries (ranks 129 to 132). The muted accounts count as unseen in the data (`seen: 0`), which is
why they appear among the unseen ranks on the third load. On the very first load all 132 entries were
unseen, so that load could not show the seen/unseen order.

**A2. The Next control (verified).** The tray has a `button[aria-label="Next"]` that is a direct sibling
of the scroller `div[role="presentation"]`, 45 x 57 px, at x 743, y 50 (right edge of the tray). The page
has two other `button[aria-label="Next"]` elements in feed carousels, so the selector must be scoped:

```
div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]
```

Each click scrolled the tray by 416 px, which is 4 rings. Old rings are removed from the DOM as new ones
render, so the DOM never held more than 18 `li`. After the first click a `button[aria-label="Go back"]`
appeared on the left. After 31 clicks scrollLeft was 12,896; the 32nd click moved it to 13,098 (the end)
and the Next button was removed. The last ring was camyarlenem, which is entry 132 of 132 in the
embedded data. Distinct accounts the tray can show: 132 at that moment.
Screenshots: `a2-tray-hover.png`, `a3-after-first-next-click.png`, `a4-tray-end-after-next-clicks.png`.

**A3. Scrolling the container (verified).** The element that scrolls is the `div[role="presentation"]`
that contains the `ul` (630 px wide, scrollWidth 13,737). All four methods worked and caused new rings to
render:

| Method | scrollLeft after | First rendered ring |
|---|---|---|
| `sc.scrollTo({left: 0})` | 0 | kaitrudeau89 |
| `sc.scrollBy({left: 2080})` | 2080 | alysonjeter |
| `page.mouse.wheel(0, 600)` with Shift held, mouse over the tray | 2680 | smokeydoesit09 |
| `page.mouse.wheel(600, 0)`, mouse over the tray | 3280 | theheatherchnl |

The first rendered ring sits about 5 positions before scrollLeft / 104, because the list renders a few
rings outside the visible area.

**A4. Seen versus unseen before any story is opened (verified, contradicts section 2 of
`instagram-selectors-notes.md`).** On the fresh load at 10:58, before the viewer had been opened on that
page, all 12 rendered rings already had `aria-label="Story by <username>, not seen"`. The notes say the
label only appears after the viewer has been opened once; that is no longer true. Rings that render
later after scrolling also carry the label (all 17 rings rendered at scrollLeft 12,480 had it).

One caveat, verified: the 4 muted accounts (ranks 129 to 132 on the first load) render with
`aria-label="... , seen"` although their data has `seen: 0`. The label cannot tell "muted" apart from
"already watched". The embedded data can (`muted: true`).

**A5. After Escape from the viewer (verified).** The URL returned to `https://www.instagram.com/`, the
tray kept its order (beenjosh, fully seen, stayed first) and scrollLeft was 0. The labels did update
without a reload: beenjosh, kevin49206, logan_rainer and arisnaibrahim became `seen`. Screenshot:
`b5-tray-after-escape.png`. The scroll position was already 0 when the viewer was opened, so whether
Escape resets a non-zero scroll position is **not verified**.

**A6. Full reload (verified).** Two reloads changed the tray. The second load had 131 entries instead of
132: kaitrudeau89 had expired (its `expiring_at` passed during the session) and beenjosh was new at rank
1. The third load put eliz98bethhh, bobbie.b.720 and kcmwellness25 in the first three places and moved
the 7 fully seen accounts to ranks 121 to 127.

### B. The story viewer

**B1. Does the viewer walk beyond the rendered rings (verified, yes).** The viewer follows the order of
the embedded tray data, not the rendered rings. On the second load the rendered rings ended at
morgainz1 (ring 12). Opening morgainz1 showed johndivinewellness as the next account, which is entry 13
of the data and was not in the DOM. Clicking its card opened
`/stories/johndivinewellness/4013228768360536402/`. Screenshot: `b1-viewer-beyond-rendered-rings.png`.

Sequence of URLs from the first walk, all from the first unseen ring:

1. `/stories/beenjosh/` (first frame URL has no storyId)
2. `/stories/veronica.r.flores1/4013113250227696537/` (auto-advance after beenjosh's single frame)
3. `/stories/kevin49206/4013158056458687666/` (next-account card)
4. `/stories/logan_rainer/4013464681930938473/` (ArrowRight on kevin49206's only frame)
5. `/stories/christopher_russo50/4013023981300511098/` (auto-advance)
6. `/stories/painbcofficial/4013108653371177645/` (card)
7. `/stories/arisnaibrahim/4012942671932981289/` (card), then Escape

An ad is inserted into the sequence. Between veronica.r.flores1 and kevin49206 the viewer had a card
for marcuszanquilaofficial with the text `Ad` instead of an age. Auto-advance would presumably play it;
that was **not verified** because the walk jumped past it with the card.

**B2. Jumping to the next account (verified).** The desktop viewer shows preview cards for the
neighbouring accounts on both sides of the centre frame:

```
a[href="/stories/<username>/<storyId>/?r=1"]    189 x 336, innerText "<username>\n<age>" or "<username>\nAd"
```

Clicking the first card to the right of the centre (x greater than `innerWidth / 2`) opened that
account; the URL changed to `/stories/<username>/<storyId>/` and the header link changed to match. This
skips the rest of the current account's frames. Cards appear 1 to 4 seconds after the viewer opens; on
one opening (morgainz1) they were not present after 2.5 seconds and were present a few seconds later.
ArrowRight moves one frame; on an account's last frame it moves to the next account. No keyboard key
that skips a whole account was found; only ArrowRight and Escape were tried, so other keys are **not
verified**. Screenshot: `b2-after-next-account-card-click.png`.

**B3. Reading the current account (verified).** Two sources agree:

- URL: `/stories/<username>/` on the first frame after a ring click, `/stories/<username>/<storyId>/`
  afterwards. Parse with `/\/stories\/([^/]+)\//`.
- Header link: `a[href="/<username>/"]` in the top-left of the frame (two such links, avatar and name).

The viewer exposes `svg[aria-label]` values `Pause`, `Menu`, `Like`, `Direct`, `Next`, `Close`, as in
the notes. There is no `[role="dialog"]`.

**B4. After the last account (partly verified).** In tray mode this was **not verified**: reaching the
end would have meant opening about 120 accounts. In direct-URL mode (D1) the viewer went to
`https://www.instagram.com/` after the only account's last frame.

**B5. Does Escape mid-account mark it seen (verified that it does not by itself).**
christopher_russo50 and painbcofficial were left after one frame by clicking the next card, and
veronica.r.flores1 likewise; all three still showed `not seen` in the tray afterwards. arisnaibrahim,
left with Escape after one frame, showed `seen`; its data before the walk had `seen` greater than 0,
which means some of its frames had been watched earlier. The reading is that `seen` means every frame of
the account has been viewed and the viewer starts at the first unwatched frame, so one frame was all that
remained. That reading is an inference from these four accounts.

### C. The full list from the page's own traffic

**C1. Is the tray fetched (verified, no).** The tray is not fetched on load. It is server-rendered into
the home page HTML inside a `<script>` element:

```
RelayPrefetchedStreamCache ... "adp_PolarisStoriesV3TrayContainerQueryRelayPreloader_<hash>"
  -> { "__bbox": { "complete": true, "result": { "data": { "xdt_api__v1__feed__reels_tray": { "broadcasts": [], "tray": [ ... ] } } } } }
```

The requests the page made during load (path, method, friendly name only):

| Method | Path | `fb_api_req_friendly_name` |
|---|---|---|
| POST | `/api/v1/web/fxcal/ig_sso_users/` | none |
| POST | `/api/graphql` | IGDOmniPickerNullStateListQuery |
| POST | `/api/graphql` | IGDBadgeCountOffMsysQuery |
| POST | `/api/graphql` | IGDChatTabsJewelOffMsysQuery |
| POST | `/api/graphql` | QuickPromotionSupportIGSchemaBatchFetchQuery (twice) |
| POST | `/graphql/query` | PolarisStoriesV3AdsPoolQuery (the story ads) |
| POST | `/api/graphql` | PolarisAPIGetFrCookieQuery |

Scrolling the tray to the end produced no tray request. Opening a ring produced
`POST /graphql/query` `PolarisStoriesV3ReelPageGalleryQuery` (the frames of the opened reels).

**C2. Size and fields (verified).** 132 entries on the first load against 12 rendered rings; 131 on
later loads. Every entry had `reel_type` `user_reel`. It includes every account the tray can show,
including all those never rendered.

| Field | Meaning, as observed |
|---|---|
| `user.username`, `user.pk`, `id` | identify the account |
| `latest_reel_media` | Unix time of the newest frame |
| `seen` | Unix time of the newest frame watched, 0 if none. Unseen when `seen < latest_reel_media`. 10 of 132 had `seen` greater than 0 but below `latest_reel_media` (partly watched) |
| `ranked_position`, `seen_ranked_position` | tray order, 1-based, equal to each other in every entry checked |
| `muted` | true for the last 4 entries |
| `expiring_at` | Unix time the oldest frame expires; one entry expired during the session |
| `has_besties_media`, `latest_besties_reel_media` | close-friends stories; 0 entries had them |
| `user.live_broadcast_id` | live videos; 0 entries had one |

**C3. Sanitized sample (verified).** Field names in order, then two entries with identifiers and URLs
redacted. Timestamps are kept because they are not secrets.

```
tray[] keys: id, reel_type, has_besties_media, latest_besties_reel_media, muted, latest_reel_media,
             seen, expiring_at, ranked_position, seen_ranked_position, user,
             latest_wearables_reel_media_long, unseen_wearables_media_igids, __typename
user keys:   pk, username, live_broadcast_visibility, live_broadcast_id, profile_pic_url,
             is_unpublished, id, hd_profile_pic_url_info, latest_reel_media, reel_media_seen_timestamp
```

```json
{ "id": "<pk>", "reel_type": "user_reel", "has_besties_media": false, "latest_besties_reel_media": 0,
  "muted": false, "latest_reel_media": 1792595176, "seen": 0, "expiring_at": 1792681576,
  "ranked_position": 1, "seen_ranked_position": 1,
  "user": { "pk": "<pk>", "username": "k**********9", "live_broadcast_visibility": null,
            "live_broadcast_id": null, "profile_pic_url": "<url>", "is_unpublished": false, "id": "<pk>",
            "hd_profile_pic_url_info": { "url": "<url>" } } }

{ "id": "<pk>", "reel_type": "user_reel", "has_besties_media": false, "latest_besties_reel_media": 0,
  "muted": true, "latest_reel_media": 1792677739, "seen": 0, "ranked_position": 132,
  "seen_ranked_position": 132, "user": { "username": "c*********m", "...": "..." } }
```

`user.reel_media_seen_timestamp` was null in the entries checked.

### D. Opening a specific account directly

**D1. `/stories/<username>/` for an account with a story (verified).** `page.goto` to
`https://www.instagram.com/stories/glassman420/` (rank 127, in the tray) did not play straight away. It
showed an interstitial with the text "View as verifiedbotanicals? glassman420 will be able to see that
you viewed their story." and a `div[role="button"]` whose text is `View story`. After clicking it the
frame played. There were no neighbour preview cards. After the account's only frame the viewer went to
`https://www.instagram.com/`; it did not advance to another account. Screenshots:
`d1-direct-stories-url-with-story.png`, `d1-after-last-frame-direct-url.png`.

**D2. `/stories/<username>/` for an account with no story (verified).** carsonweaver21 is followed and had
`latest_reel_media: 0` in the following list response. `https://www.instagram.com/stories/carsonweaver21/`
redirected to `https://www.instagram.com/carsonweaver21/` within 1.9 seconds, title `Instagram`, the
normal profile page. Detection: after navigation, the URL no longer contains `/stories/`. Screenshot:
`d2-direct-stories-url-no-story.png`.

A related case, verified by accident: clicking a tray ring whose story expired after the page loaded
(kaitrudeau89) gave URL `/stories/kaitrudeau89/`, title `Page couldn't load • Instagram`, body
"Something went wrong. There's an issue and the page could not be loaded." and a `Reload page` button.
The script should treat that page as "story gone" and return home.

**D3. Profile page avatar ring (verified, not clicked).**

| Profile | Avatar structure | Canvas |
|---|---|---|
| `/mooner.83/` (has an unseen story) | `header` img inside `span[role="link"][tabindex="0"]` inside `div[role="button"][tabindex="0"]` | 165 x 165, pixel at (82, 1) is (222, 0, 174), the gradient |
| `/carsonweaver21/` (no story) | `header` img inside `a[role="link"][href="/carsonweaver21/"]` | none |

There is no `aria-label` on either. The stable test is "the avatar img is not inside an `a`, and a canvas
sits next to it". Screenshots: `d3-profile-avatar-mooner-83.png`, `d3-profile-avatar-carsonweaver21.png`.

### E. Other routes

**E1. The Following list (verified).** On `/verifiedbotanicals/` the header shows 414 followers and 686
following. The `following` count is now `header a[role="link"][href="#"]` with text `686 following`
(not an href to `/following/`), and clicking it opens a `[role="dialog"]` titled `Following`. The URL
stays `/verifiedbotanicals/`.

Lazy loading: 12 rows per request, `GET /api/v1/friendships/43328431608/following/?count=12&max_id=<n>`
(`max_id` 12, 24, ... 120), each followed by `POST /api/v1/friendships/show_many/`. The scroller is the
inner `div` of the dialog with `overflow-y: auto` (560 x 309 px). Scrolling only worked with the mouse
over that div; 10 wheel steps of 1,200 px loaded 132 rows. Rows are not removed as more load. The
response for each page has `users[]` with `username`, `pk`, `full_name`, `is_private`,
`latest_reel_media` (0 means no story) and the page fields `big_list`, `page_size` (12), `next_max_id`,
`has_more`. Screenshots: `e1-following-dialog.png`, `e2-following-dialog-scrolled.png`.

Has-story marker in a row (verified on 132 rows):

| Row state | Avatar structure | Ring canvas 55 x 55, pixel at (27, 1) |
|---|---|---|
| Story, unseen | `div[role="button"][tabindex="0"] > span[role="link"][tabindex="-1"] > img` | (223, 1, 173) magenta |
| Story, seen or muted | same structure | (219, 223, 228) grey |
| No story | `div[role="button"][aria-disabled="true"][tabindex="-1"] > a[role="link"][href="/<u>/"] > img` | transparent |

21 of the 132 rows had a story. All 21 of those accounts were in the tray data loaded right afterwards,
so in this sample the tray did not leave out any followed account that had a story. Whether the tray
caps out above some number of accounts is **not verified**; the other 554 followed accounts were not
loaded at that point. Checked later the same day: all 685 loaded, and the tray held every followed account with a story (section 6).

There is no dedicated page or URL that lists only the followed accounts with active stories. The
following list is the only other place on the web UI where the rings appear, and it covers 12 accounts
per request in follow order, not story order.

**E2. Other controls (not verified).** No "see all" link or stories-only page was found on the home page.
Opening `https://www.instagram.com/stories/` with no username was not tried, because it would most likely
open the viewer and record a view.

**E3. What people use (from a web search).** Tools that list all current stories from followed accounts
call `GET https://i.instagram.com/api/v1/feed/reels_tray/` with the account's cookies (for example the
Go package `siongui/userpages ig-stories-list` and a Chrome extension described on Medium). That is the
same data that the web page now embeds as `xdt_api__v1__feed__reels_tray`. The hand-off rules forbid
calling it directly, and reading it from the page's own HTML gives the same list without a request.

Follow-up search, 2026-10-22 afternoon (from the web, not verified in the browser):

- Apify actor `shareze001/instagram-active-story-profiles-by-cookies`
  (https://apify.com/shareze001/instagram-active-story-profiles-by-cookies) lists "followed accounts
  that currently have an active Story". Its page says it reads the same stories tray data that
  instagram.com shows, and it needs the account's full Cookie header including `sessionid`. That
  means handing the @verifiedbotanicals session to a third party, which the hand-off rules exclude, and
  it adds nothing over the embedded tray data. Not recommended.
- The `instagram_private_api` Python library documents `reels_tray()` and `reels_media(user_ids)`;
  the second takes a list of user ids and returns their stories in one call
  (https://instagram-private-api.readthedocs.io/en/latest/api.html). Neither documents a cap on the
  number of accounts in the tray. Both are direct calls to the private API and are out of scope here.
- A 2017 Medium article on a Chrome story extension (https://medium.com/@calialec/c813e6dff911) says
  `/api/v1/feed/reels_tray/` "only returns new content". That is out of date: the tray data seen in
  this session had 7 fully seen accounts among its 131 entries.

No source describes a page or URL on the web UI that lists only the followed accounts with an active
story. Every tool found reads the reels tray.

**E4. Pacing (verified).** Roughly 70 navigations, clicks and scroll steps over 21 minutes, including 32
tray Next clicks, 11 following-list requests and 11 story openings, with 2 to 4 seconds between actions, produced no
block or challenge. The single "Something went wrong" page was the expired story in D2, not a block. The
2026-10-20 run summary (`run-log.jsonl`) recorded 61 story sets in 341 minutes, which fits within the
120 unseen, non-muted accounts available at 11:17.

---

## 3. Accounts whose stories were opened

Eleven accounts in total, 1 frame each. These are the test views.

| # | Account | How | Note |
|---|---|---|---|
| 1 | kaitrudeau89 | tray ring | story had expired, error page, no frame shown |
| 2 | beenjosh | tray ring (first unseen) | 1 frame, auto-advanced |
| 3 | veronica.r.flores1 | auto-advance | 1 frame |
| 4 | kevin49206 | next-account card | 1 frame, ArrowRight |
| 5 | logan_rainer | ArrowRight from kevin49206 | 1 frame, auto-advanced |
| 6 | christopher_russo50 | auto-advance | 1 frame |
| 7 | painbcofficial | next-account card | 1 frame |
| 8 | arisnaibrahim | next-account card | 1 frame, Escape |
| 9 | morgainz1 | tray ring 12 | 1 frame |
| 10 | johndivinewellness | next-account card (data entry 13) | 1 frame, Escape |
| 11 | glassman420 | direct URL `/stories/glassman420/` plus `View story` | 1 frame |

Visited without opening a story: the profiles `/mooner.83/` and `/carsonweaver21/`, and
`/stories/carsonweaver21/` (no story, redirected to the profile).

---

## 4. Recommendation

**Use the embedded tray data to choose the account, and open it from the tray.**

On each `watchStories()` call, load or reload `https://www.instagram.com/` and parse
`xdt_api__v1__feed__reels_tray.tray` from the page's `<script>` elements (code in section 5). Keep a
`Set` of usernames already opened in this run. Pick the first entry with `seen < latest_reel_media`,
`muted === false`, `expiring_at` more than a few minutes away, and a username not in the set. If its ring
is not rendered, scroll the tray container to `(ranked_position - 1) * 104` px with `scrollBy` or Shift +
wheel (or click Next until the ring appears), then click
`ul li div[role="button"][aria-label^="Story by <username>,"]`. Confirm the username from the URL, watch
1 to 3 frames with ArrowRight, and press Escape. Add the username to the set even if the account was
left mid-way, because the tray will still show it as `not seen`.

This gives one account per action, no repeats within a run, and a click path that looks like a person
choosing a ring. The 2026-10-20 run's 61 story sets would use 61 of the 120 unseen, non-muted accounts that were available at 11:17.

A cheaper variant, if a story set should cover several accounts in one viewer session: open one ring
from the tray and then click the next-account card (the first `a[href^="/stories/"]` right of centre
whose text does not end in `Ad`) instead of pressing ArrowRight through every frame. Each card click is
one new account.

The direct URL `/stories/<username>/` works, but it adds the "View as verifiedbotanicals?" interstitial
and an extra `View story` click, gives no neighbour cards, and returns to home after one account. A
person rarely reaches stories that way. Use it only as a fallback.

The following list is not a good primary source. It loads 12 accounts per request in follow order, 686
accounts need about 58 requests, and in the sample every account it marked with a story was already in
the tray data.

What could make this fragile:

1. The embedded JSON is an internal Relay preloader (`PolarisStoriesV3TrayContainerQueryRelayPreloader`).
   Its key or shape can change in any deploy. Fall back to the DOM labels (`not seen` rings plus Next
   clicks) when the key is missing.
2. The tray is virtualized, so a ring that is not near the visible area cannot be found by selector.
   The script must scroll before it looks.
3. Stories expire while the page is open. Opening one gives the "Something went wrong" page; detect the
   `Reload page` button or the title `Page couldn't load • Instagram` and go back home.
4. Ads are inserted into the viewer sequence. Auto-advance and ArrowRight can land on one. Check the URL
   username against the tray data, and skip cards whose text ends in `Ad`.
5. Muted accounts render as `seen` in the tray labels. Use the `muted` field, not the label.
6. Ring `aria-label` text is English and could be localized. The data fields are not.
7. Section 2 of `instagram-selectors-notes.md` says labels need one viewer opening first. That is no
   longer true and code built on it may do an unnecessary first opening.

---

## 5. New verified selectors

Same style as section 2 of `instagram-selectors-notes.md`. All verified 2026-10-22.

### Story tray: full list from the page

The home page HTML carries the full tray. No request is needed.

```js
const tray = await page.evaluate(() => {
  const s = [...document.querySelectorAll('script')].map(x => x.textContent)
    .find(t => t.includes('xdt_api__v1__feed__reels_tray'));
  if (!s) return null;
  const start = s.indexOf('{"xdt_api__v1__feed__reels_tray"');
  let depth = 0, inStr = false, esc = false, end = -1;
  for (let i = start; i < s.length; i++) {
    const c = s[i];
    if (inStr) { if (esc) esc = false; else if (c === '\\') esc = true; else if (c === '"') inStr = false; continue; }
    if (c === '"') inStr = true;
    else if (c === '{') depth++;
    else if (c === '}') { depth--; if (depth === 0) { end = i + 1; break; } }
  }
  return JSON.parse(s.slice(start, end)).xdt_api__v1__feed__reels_tray.tray.map(e => ({
    username: e.user.username, rank: e.ranked_position, muted: e.muted,
    unseen: e.seen < e.latest_reel_media, expiresAt: e.expiring_at,
  }));
});
```

132 entries on a fresh load at 10:58, against 12 rendered rings.

### Story tray: rendering and scrolling

```
div[role="presentation"]:has(ul li canvas)      the scroller, 630 px wide, overflow-x auto
  ul > li[style="transform:translateX(<n*104>px)"] > div[role="button"]    one ring, 104 px pitch
  ul > li[style*="width:1px"]                   spacer at the end of the list, not a ring
```

```
div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]      scrolls 416 px (4 rings)
div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Go back"]   appears once scrolled
```

The Next button is removed at the end of the list. `scrollTo`, `scrollBy`, Shift + mouse wheel and a
horizontal mouse wheel all scroll the scroller and render new rings.

Labels are present on a fresh load, before any viewer opening:

```
aria-label="Story by <username>, not seen"
aria-label="Story by <username>, seen"        also used for muted accounts
```

Open a specific account once its ring is rendered:

```js
page.locator(`ul li div[role="button"][aria-label^="Story by ${username},"]`).click()
```

### Story viewer: neighbour cards and current account

```
a[href^="/stories/"]          preview cards, 189 x 336, href "/stories/<user>/<storyId>/?r=1"
                              innerText "<user>\n<age>" or "<user>\nAd"
a[href="/<user>/"]            header link of the current account (avatar and name)
```

Next account: the first card with `getBoundingClientRect().x > innerWidth / 2` whose text does not end
in `Ad`. Cards can take a few seconds to appear after the viewer opens.

### Direct story URL

| Case | Result |
|---|---|
| Account with a story | URL stays `/stories/<user>/`; interstitial text "View as verifiedbotanicals?" and `div[role="button"]` with text `View story`; no neighbour cards; returns to `/` after the last frame |
| Followed account with no story | redirect to `/<user>/` within 2 s |
| Story expired since the tray loaded | title `Page couldn't load • Instagram`, body "Something went wrong", button `Reload page` |

### Has-story marker on avatars (profile header and Following dialog)

| State | Structure |
|---|---|
| Has a story | img inside `span[role="link"]` inside `div[role="button"][tabindex="0"]`, plus a ring canvas (165 x 165 in a profile header, 55 x 55 in the dialog) |
| No story | img inside `a[role="link"][href="/<user>/"]`; in the dialog the wrapping `div[role="button"]` has `aria-disabled="true"` |

Seen versus unseen from the ring canvas, top-centre pixel: (222 to 223, 0 to 1, 173 to 174) when unseen,
(219, 223, 228) when seen or muted.

### Own Following dialog

```
header a[role="link"][href="#"]       text "<n> following", opens [role="dialog"]
GET /api/v1/friendships/<own pk>/following/?count=12&max_id=<n>    12 rows per page
```

---

## 6. Following list compared with the tray (verified, 2026-10-22 17:29 to 17:40)

Question: does the tray leave out any followed account that has an active story? Section E1 left this
open because only the first 132 of 686 followed accounts had been loaded.

### Method

1. 17:29: loaded `https://www.instagram.com/` and parsed the embedded tray data. 130 entries, 5 of them
   muted (ranks 126 to 130).
2. 17:30 to 17:35: opened the Following dialog on `/verifiedbotanicals/` and scrolled it with the mouse
   wheel over the dialog's scroller, 58 wheel steps of 900 to 1,400 px, 2.2 to 4 seconds apart. Each step
   made the page fetch one page of 12 accounts. The responses the page loaded itself were recorded with
   `page.on('response')`; nothing was requested directly.
3. 17:36: reloaded the home page and parsed the tray data again. 129 entries.

No story was opened for the comparison. No warning, block or challenge appeared.

### Result

| Measure | Value |
|---|---|
| Accounts in the Following list | 685 (the header says 686 following) |
| Following-list requests | 58: `count=12`, `max_id` absent, then 12, 24 ... 684; the last page had 1 account, `has_more: false`, no `next_max_id`, `big_list: false` |
| Followed accounts with `latest_reel_media > 0` | 130, of which 15 are private |
| Rows where the DOM marker (`aria-disabled`) disagreed with `latest_reel_media` | 0 of 684 rows checked |
| Of those 130, how many were in the 17:29 tray | 130 |
| Of those 130, how many were in the 17:36 tray | 129. The missing one, zachtion_bronson, had `expiring_at` 1792704839, which is 17:33:59, between the two loads |
| Tray accounts that are not in the Following list | 0 |
| Tray accounts whose Following-list entry said "no story" | 0 |
| Tray accounts whose `latest_reel_media` differed from the Following list | 0 of 129 |

Conclusion (verified for this account, 685 followed and 130 with a story): the tray data on the home page
is the complete set of followed accounts with an active story. Loading the Following list adds no
account. Whether this still holds for an account that follows thousands of people is **not verified**.

The 130 accounts with a story are spread across the whole follow order: per block of 100 rows there were
18, 15, 7, 18, 21, 35 and 16, and the last one was row 685. Using the Following list to find them
therefore means loading the full list, 58 requests, every time. The tray costs one page load.

The 685 against 686 difference: every page of the response has a `hidden_following_account_count`
field. Its value was not recorded, so the reason for the difference is **not verified**; a hidden or
deactivated account is the likely reading.

Ring colours in the dialog, top-centre pixel of the 55 x 55 canvas, over 684 rows: 121 magenta
(223, 1, 173) = unseen story; 9 grey (219, 223, 228) = the 5 muted plus 4 fully seen accounts;
554 transparent = no story.

One story was opened afterwards to record the viewer's HTML (section 7.3): emmyrobbindoula, tray ring
rank 1, 1 frame of 12, about 5 seconds, closed with Escape. Its ring still reads `not seen` afterwards.
This is test view 12 of the day (the first 11 are in section 3).

Files in `story-tray-findings-screenshots/following-check/`:

- `following-list.json`: all 685 rows as the page received them, `username`, `latest_reel_media`,
  `is_private`, plus the page list. No ids, cookies or headers.
- `tray-after.json`: the 17:36 tray, 129 entries.
- `f1-following-dialog-end-of-list.png`, `v1-viewer-emmyrobbindoula.png`.

### Corrections to sections 2 and 5

1. The 1 px spacer `li` is the **first** child of the tray's `ul`, not the last. Its style is
   `transform:translateX(<total width>px);width:1px`. Skip `li` elements without a `div[role="button"]`.
2. In the Following dialog, rows **without** a story also contain a 55 x 55 canvas (transparent). Test
   `aria-disabled`, not whether a canvas exists.
3. In the Following dialog, `div[style*="overflow: hidden auto"]` is the 720 px tall content and does not
   scroll. The scroller is its parent, a `div` with no `style` attribute and only obfuscated classes. Find
   it by computed style (section 7.5). A wheel over the content div's centre lands below the scroller and
   loads nothing.

---

## 7. Element reference for the script

Everything here was seen on 2026-10-22 between 17:29 and 17:40 at viewport 1359 x 906, English UI,
unless marked otherwise. Positions are only for orientation; select by attributes.

### 7.1 The account list: tray data in the home page HTML

The list of accounts with a story is in one `<script>` element of the home page:

| Property | Value |
|---|---|
| Element | `script[type="application/json"]` whose text contains `xdt_api__v1__feed__reels_tray` |
| Attributes | `type`, `nonce`, `data-content-len`, `data-sjs`, `data-processed` |
| Size | about 220,000 characters |
| Text starts with | `{"require":[["ScheduledServerJS","handle",null,[{"__bbox":{"require":[["RelayPrefetchedStreamCache","next",[],["adp_PolarisStoriesV3TrayContainerQueryRelayPreloader_<hash>"` |
| Path to the list | `xdt_api__v1__feed__reels_tray.tray[]` |

The element comes with a full page load of `/`. Closing the viewer with Escape returns to `/` without a
new page load, so the data stays as it was at the last load. Reload or `goto('/')` before each story
action to get current data and drop expired stories.

Fields to use per entry:

| Field | Use |
|---|---|
| `user.username` | the account; matches the ring label and the viewer URL |
| `ranked_position` | 1-based position in the tray; the ring's `li` sits at `translateX((ranked_position - 1) * 104 px)` (verified for ranks 1 to 3 at 17:37) |
| `muted` | skip when true |
| `seen`, `latest_reel_media` | unseen when `seen < latest_reel_media` |
| `expiring_at` | skip when less than about 5 minutes away |
| `reel_type` | `user_reel` for every entry seen today |

The parser in section 5 ("Story tray: full list from the page") works unchanged on today's pages.

### 7.2 The tray DOM

```
div                                                   wrapper, no attributes
|- div[role="presentation"]                           the scroller, 630 x 124, overflow-x auto
|   `- div
|       `- ul
|           |- li[style="transform:translateX(13415px);width:1px"]        spacer, FIRST child
|           |- li[tabindex="-1"][style="transform:translateX(0px)"]       rank 1
|           |   `- div[role="button"][tabindex="0"][aria-label="Story by emmyrobbindoula, not seen"]
|           |       |- div > div > div
|           |       |     |- canvas[width="89"][height="89"]               the ring
|           |       |     `- span[role="link"][tabindex="0"] > img[alt="emmyrobbindoula's profile picture"]
|           |       `- div > span > span                                   text "emmyrobbindoula"
|           |- li[tabindex="-1"][style="transform:translateX(104px)"]     rank 2
|           `- ...                                                         12 to 18 rendered at a time
`- button[aria-label="Next"][tabindex="-1"] > div     scrolls 416 px; removed at the end of the list
   (button[aria-label="Go back"] is added on the left once scrolled)
```

| Purpose | Selector |
|---|---|
| Tray is ready | `div[role="presentation"] ul li canvas` |
| Scroller | `div[role="presentation"]:has(ul li canvas)` |
| Ring for one account | `` ul li div[role="button"][aria-label^="Story by ${username},"] `` |
| Tray Next button | `div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]` (the page has 2 other `Next` buttons, in feed carousels) |

To reach rank `r`: click the tray Next button `Math.ceil(Math.max(0, (r - 1) * 104 - 520) / 416)`
times, 0.8 to 2 seconds apart, or Shift + mouse wheel over the scroller. Then wait for the ring locator
to become visible before clicking it. Ranks 1 to 6 are visible without scrolling. Muted accounts are at
the end of the list, and fully seen accounts just before them.

### 7.3 The story viewer

After a ring click: URL `https://www.instagram.com/stories/<username>/`, later frames
`/stories/<username>/<storyId>/`, document title `Stories • Instagram`. There is no `[role="dialog"]`.

**The feed page stays in the DOM underneath with 0 x 0 boxes.** The viewer page held 5 hidden
`svg[aria-label="Like"]` from feed posts besides the visible one. Never select an icon by label alone;
filter by a non-zero `getBoundingClientRect()` or scope to the frame column.

Frame column: 472 x 839 at x 444, y 34. Find it from the header link by walking up to the first ancestor
at least 300 px tall.

| Element | How to find it | Box | Use |
|---|---|---|---|
| Header avatar link | `a[href="/<username>/"]`, first one with a non-zero box | 460,68 32 x 32 | confirms the account |
| Header name link | `a[href="/<username>/"]`, second | 500,69 130 x 12 | same |
| Frame age | `time[datetime]` inside the column, text such as `3h` | | |
| Progress bar | inside the column, the `div` (440 x 2 at 460,54) whose children are all at most 3 px tall | | **frame count = its `children.length`** (12 for emmyrobbindoula) |
| Playing segment | the segment's first child has `style="transform: translateX(-77.4802%);"`; the next segment's child had no style | | progress of the current frame; reading which segment is current from this is an inference from one account |
| Video | `video` with a non-zero box, 472 x 839 | | `duration` was 60.1 s for this frame; hidden feed videos also exist |
| Sound | `svg[aria-label="Audio is muted"]` in `div[role="button"]` | 796,68 32 x 32 | leave alone |
| Pause | `svg[aria-label="Pause"]` in `div[role="button"]` | 828,68 32 x 32 | optional |
| Menu | `svg[aria-label="Menu"]` | 860,64 40 x 40 | **never click** |
| Like | `svg[aria-label="Like"]` | 860,815 40 x 40 | **never click**, it sends the author a notification |
| Next frame | `svg[aria-label="Next"]` in `div[role="button"]` | 924,433 40 x 40 | same as ArrowRight |
| Close | `svg[aria-label="Close"]` in `div[role="button"]` | 1288,16 40 x 40 | same as Escape |
| Next-account card | `a[href^="/stories/"][href$="?r=1"]`, first with `x > innerWidth / 2` | 996,285 189 x 336, text `reidysweedy\n4h` | one click jumps one account |
| Ad card | same selector, text ends in `Ad` | 1265,285, text `redditforbusiness\nAd` | skip |

On the first account of a sequence there are cards on the right only. Escape returned to `/` and the
tray label updated without a reload.

### 7.4 States the script has to recognise

| State | How to detect |
|---|---|
| Story playing | URL matches `/\/stories\/([^/]+)\//` and the captured name equals the chosen username |
| Viewer moved on to another account (auto-advance or ad) | captured name differs from the chosen username, or the next-card text ends in `Ad` |
| Story expired after the tray loaded | title `Page couldn't load • Instagram`, a `Reload page` button; go back to `/` |
| Direct `/stories/<u>/` URL, story exists | text `View as verifiedbotanicals?` and `div[role="button"]` with text `View story` |
| Direct `/stories/<u>/` URL, no story | URL changes to `/<u>/` within about 2 s |
| Action block | text matching `/Try again later|Action Blocked|suspicious|Please wait a few minutes/i`; stop the run |

### 7.5 The Following dialog (fallback only)

Not needed for picking stories (section 6), but here in full in case the tray data disappears.

| Purpose | Selector or rule |
|---|---|
| Open | on `/verifiedbotanicals/`: `header a[role="link"][href="#"]` with text `686 following` (Playwright: `page.locator('header a[role="link"]', { hasText: 'following' })`) |
| Dialog | `div[role="dialog"][aria-modal="true"]`, heading text `Following`; Escape closes it |
| Scroller | inside the dialog, the `div` whose computed `overflowY` is `auto` and whose `scrollHeight > clientHeight`; 560 x 309 at 392,304. It has no stable attribute |
| Content | scroller > `div[style="height: auto; overflow: hidden auto;"]` > `div[style*="flex-direction: column"]` > one `div` per row. Rows are never removed; all 685 were in the DOM at the end |
| Username | in each row, the `a[role="link"][href="/<u>/"]` that has text |
| Has a story | the avatar's `div[role="button"]` has `tabindex="0"` and no `aria-disabled`; the img sits in `span[role="link"][tabindex="-1"]` |
| No story | the avatar's `div[role="button"]` has `aria-disabled="true"` and `tabindex="-1"`; the img sits in `a[role="link"][href="/<u>/"]` |
| **Danger** | every row has a `button` with text `Following`. Clicking it starts an unfollow. Never click buttons in this dialog |
| Load more | mouse wheel of 900 to 1,400 px over the scroller's centre loads one page of 12 |
| End of list | the page's last `GET /api/v1/friendships/<pk>/following/` response has `has_more: false` |

A row with a story, classes removed and the image URL replaced:

```html
<div role="button" tabindex="0">
  <canvas height="55" width="55" style="left: -5.5px; position: absolute; top: -5.5px; height: 55px; width: 55px;"></canvas>
  <span role="link" tabindex="-1" style="height: 44px; width: 44px;">
    <img alt="whiterabbitenergy's profile picture" crossorigin="anonymous" draggable="false" src="<url>">
  </span>
</div>
...
<a href="/whiterabbitenergy/" role="link" tabindex="0"><div><div><span dir="auto">whiterabbitenergy</span> ... </div></div></a>
...
<button type="button"><div><div dir="auto">Following</div></div></button>
```

Response fields per user (`users[]`): `fbid_v2, pk, pk_id, id, third_party_downloads_enabled,
strong_id__, full_name, is_private, is_verified, profile_pic_id, profile_pic_url, username,
account_badges, has_anonymous_profile_picture, latest_reel_media, is_favorite`. Page fields: `users,
big_list, page_size, next_max_id, has_more, should_limit_list_of_followers, use_clickable_see_more,
show_spam_follow_request_tab, follow_ranking_token, should_limit_list_of_followings,
hidden_following_account_count, status`.

### 7.6 Suggested flow for `watchStories()`

A sketch to show how the elements fit together, not tested code. It uses `readTray()` from section 5.

```js
// Kept for the whole run (or in a file with a timestamp, cleared after 24 hours, so a second run the
// same day does not rewatch the same accounts). Add the username as soon as the ring is clicked,
// because watching 1 to 3 frames of a 12-frame story leaves the ring "not seen".
const watched = new Set();

async function watchStories(page) {
  await page.goto('https://www.instagram.com/');
  await page.locator('div[role="presentation"] ul li canvas').first().waitFor({ timeout: 15000 });

  const tray = await readTray(page);                 // null if the embedded data is gone
  if (!tray) return watchStoriesFromRingLabels(page); // old behaviour, labels plus Next clicks

  const now = Date.now() / 1000;
  const pick = tray.find(e => !e.muted && e.unseen && e.expiresAt > now + 300 && !watched.has(e.username));
  if (!pick) return;                                  // every unseen account already watched this run

  const next = page.locator('div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]');
  const clicks = Math.ceil(Math.max(0, (pick.rank - 1) * 104 - 520) / 416);
  for (let i = 0; i < clicks && await next.count(); i++) {
    await next.click();
    await page.waitForTimeout(800 + Math.random() * 1200);
  }

  const ring = page.locator(`ul li div[role="button"][aria-label^="Story by ${pick.username},"]`);
  await ring.waitFor({ state: 'visible', timeout: 5000 });
  await ring.click();
  watched.add(pick.username);

  await page.waitForURL(/\/stories\//, { timeout: 10000 });
  if ((await page.title()).startsWith("Page couldn't load")) { await page.goto('https://www.instagram.com/'); return; }

  const frames = await countFrames(page, pick.username);   // section 7.3 progress bar
  const toWatch = Math.min(frames || 1, 1 + Math.floor(Math.random() * 3));
  for (let i = 1; i < toWatch; i++) {
    await page.waitForTimeout(3000 + Math.random() * 4000);
    await page.keyboard.press('ArrowRight');
    const m = page.url().match(/\/stories\/([^/]+)\//);
    if (!m || m[1] !== pick.username) break;          // moved on to another account or an ad
  }
  await page.waitForTimeout(3000 + Math.random() * 4000);
  await page.keyboard.press('Escape');
}

async function countFrames(page, username) {
  return page.evaluate(u => {
    const hdr = [...document.querySelectorAll(`a[href="/${u}/"]`)].find(a => a.getBoundingClientRect().width > 0);
    if (!hdr) return null;
    let col = hdr;
    while (col && col.getBoundingClientRect().height < 300) col = col.parentElement;
    if (!col) return null;
    const bar = [...col.querySelectorAll('div')].find(d => {
      const r = d.getBoundingClientRect();
      return r.height > 0 && r.height <= 3 && d.children.length > 0 &&
        [...d.children].every(c => c.getBoundingClientRect().height <= 3);
    });
    return bar ? bar.children.length : null;
  }, username);
}
```

With 130 accounts in the tray and 5 muted, about 120 to 125 unseen accounts were available at 17:29.
The 2026-10-20 run did 61 story sets, so one run uses about half of them with no repeats.
