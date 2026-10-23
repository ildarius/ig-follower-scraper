# Instagram web selectors - verified live

Verified in the Playwright MCP against a signed-in @verifiedbotanicals session on 2026-09-15.
Chromium, desktop viewport, English UI. Every item marked **verified** was executed against the live
site and the result read back from the DOM.

Instagram class names are generated (`x1i10hfl x972fbf ...`) and change between deploys. Nothing here
depends on a class name. Every locator is anchored on `aria-label`, `role`, `href` shape, or element
type.

---

## 1. Feed posts

Each feed post is an `article`. The home feed had 4 articles at load and grew past 12 after 8 scroll
steps, so infinite scroll works with plain `window.scrollBy`. Verified: scrollY reached 4515 and page
height grew to 10762.

### Post identity in the feed

| What | Locator | Value shape |
|---|---|---|
| Permalink | `a[href^="/p/"]`, `a[href^="/reel/"]` | `/p/DdUC0hdtknX/` |
| Author | first `a[href^="/"]` in the article | `/beenjosh/` |

### Skip rules, verified against live articles

| Skip | Test | Evidence |
|---|---|---|
| Sponsored post | **no** `a[href^="/p/"]` and **no** `a[href^="/reel/"]` in the article | Two ads appeared, `/runwayapp/` and `/claudeai/`. Both had only tracking-query profile links, no permalink. |
| Own post | author href is `/verifiedbotanicals/` | Own posts also render "View insights" and "Boost post" buttons. |

Do not test for the word "Sponsored". The ad label rendered as **"Ad"**, and it is localized. The
permalink test is language independent.

### Like button - verified

```
svg[aria-label="Like"]     not liked
svg[aria-label="Unlike"]   already liked
```

The clickable element is the nearest `div[role="button"][tabindex="0"]` ancestor, exactly 3 levels
above the svg, 40 x 40 px. The button carries **no** `aria-label`, so state can only be read from the
svg. Full locator for one post:

```js
`article:has(a[href="${permalink}"]) div[role="button"]:has(svg[aria-label="Like"])`
```

Verified to match exactly 1 element. Selecting on `aria-label="Like"` means an already-liked post
produces zero matches, so the script cannot unlike a post by accident.

**State flip verified.** Clicked the like button on `/p/DcY1QXEF8o2/` (al.chl, a "Suggested for you"
post):

| Before | After |
|---|---|
| `aria-label="Like"` | `aria-label="Unlike"` |
| icon colour inherited | `rgb(255, 48, 64)` |

No action block. That like is still in place on the account.

Post-click check: re-read the svg `aria-label` and require `Unlike`. Colour is a secondary check.

### Inline Follow button in the feed

"Suggested for you" posts carry their own `Follow` button inside the article header. The like locator
is scoped to `:has(svg[aria-label="Like"])` so it cannot hit that button, but any future selector
that walks generic buttons inside an article must account for it.

---

## 2. Story tray - verified 2026-10-22

Replaces the 2026-09-15 notes. Full evidence, screenshots and the questions behind it:
`story-tray-findings.md` (sections 5 and 7). Viewport 1359 x 906, English UI.

### The full list is in the page

The tray is a virtualized list. The home page HTML carries every account with a story (130 on
2026-10-22, all 130 followed accounts that had one) as JSON in one element:

| Property | Value |
|---|---|
| Element | `script[type="application/json"]` whose text contains `xdt_api__v1__feed__reels_tray` |
| Size | about 220,000 characters |
| Path | `xdt_api__v1__feed__reels_tray.tray[]` |
| Source | Relay preloader `PolarisStoriesV3TrayContainerQueryRelayPreloader`; it can change in any deploy |

It comes with a full page load of `/` only. Escape from the viewer returns to `/` without a load, so
reload before reading it again. Fields used: `user.username`; `ranked_position` (1-based tray order);
`muted`; `seen` and `latest_reel_media` (unseen when `seen < latest_reel_media`, partly watched when
`seen > 0`); `expiring_at`; `reel_type` (`user_reel`). `parseTrayJson()` in the script extracts it
with a brace walk that skips JSON strings.

### The tray DOM

```
div                                                   wrapper, no attributes
|- div[role="presentation"]                           the scroller, 630 x 124, overflow-x auto
|   `- div > ul
|       |- li[style="transform:translateX(<total>px);width:1px"]   spacer, FIRST child, no button
|       |- li[style="transform:translateX(0px)"]                   rank 1
|       |   `- div[role="button"][aria-label="Story by <username>, not seen"]
|       |       |- ... canvas[width="89"][height="89"]               the ring
|       |       `- span > span                                       text "<username>"
|       |- li[style="transform:translateX(104px)"]                 rank 2
|       `- ...                                                      12 to 18 rendered at a time
`- button[aria-label="Next"]                          scrolls 416 px (4 rings); removed at the end
   (button[aria-label="Go back"] appears on the left once scrolled)
```

| Purpose | Selector or rule |
|---|---|
| Tray is ready | `div[role="presentation"] ul li canvas` |
| Scroller | `div[role="presentation"]:has(ul li canvas)` |
| Ring for one account | `` ul li div[role="button"][aria-label^="Story by ${username},"] `` |
| Tray Next | `div:has(> div[role="presentation"] ul li canvas) > button[aria-label="Next"]`. Scoped because the page has two other `button[aria-label="Next"]` in feed carousels |
| Ring position | `li` at `translateX((ranked_position - 1) * 104 px)`. Ranks 1 to 6 are on screen without scrolling |
| Reaching rank r | `Math.ceil(Math.max(0, (r - 1) * 104 - 520) / 416)` Next clicks; rank 132 took 32 |

**Labels are present on a fresh load**, before the viewer has been opened (the 2026-09-15 notes said
otherwise; that is no longer true):

```
aria-label="Story by <username>, not seen"
aria-label="Story by <username>, seen"        also used for MUTED accounts, whose data has seen: 0
```

Use the data, not the label, to tell muted from watched. Labels are English UI text.

### Story viewer

| What | Detail |
|---|---|
| URL | `/stories/<username>/` on the first frame, `/stories/<username>/<storyId>/` afterwards. Parse with `/\/stories\/([^/?#]+)\//` |
| Title | `Stories • Instagram`. There is no `[role="dialog"]` |
| Current account | `a[href="/<username>/"]` with a non-zero box (header avatar, then the name) |
| Frame count | from the header link walk up to the first ancestor at least 300 px tall (the frame column, 472 x 839). The progress bar is the first `div` in it that is 1 to 3 px tall with all children at most 3 px tall: one child per frame |
| Where it opens | at the first unwatched frame, so for a partly watched account the frame count is not what is left |
| Next frame | key ArrowRight. On an account's last frame it moves to the next account in tray order, often an ad |
| Exit | key Escape; the URL returns to `https://www.instagram.com/` without a page load |
| Expired since load | title `Page couldn't load • Instagram`, a `Reload page` button. Go back to `/` |

Leaving after a few frames keeps the ring `not seen`: `seen` only catches up when every frame has
been watched.

**The feed stays in the DOM under the viewer with 0 x 0 boxes**, including hidden
`svg[aria-label="Like"]`. Never select a viewer icon by label alone. The script clicks nothing in
the viewer: keys only.

### Story viewer controls

Read from `svg[aria-label]` inside the viewer: `Pause`, `Previous`, `Next`, `Close`, `Like`,
`Direct`, `Menu`.

**Do not click the story `Like`.** Liking a story sends a DM notification to the author, which is a
different action from liking a feed post. Do not click `Menu`, `Direct`, a reply field or anything
else either.

---

## 3. Profile pages

### Follow button - verified

```
header button[type="button"]
```

| Account state | Button innerText | Extra |
|---|---|---|
| Not followed | `Follow` | none |
| Followed | `Following` | contains `svg[aria-label="Down chevron icon"]` |
| Private, request sent | `Requested` | not yet verified, see below |

Verified on `/al.chl/` (`Follow`, 305 x 44) and `/itsreaganbradley/` (`Following`, 323 x 44).

**Follow click verified.** Clicked `header button[type="button"]` on `/painbcofficial/`. The button
text changed from `Follow` to `Following` with the chevron svg. No dialog, no action block.
@verifiedbotanicals now follows Pain BC.

**The substring trap.** `"Following"` contains `"Follow"`. A `hasText: 'Follow'` match will select an
already-followed profile's button, and clicking it opens the unfollow menu. Read `innerText`, trim
it, and compare with `=== 'Follow'` before clicking. Nothing else is safe.

Playwright's `:text-is("Follow")` did **not** match through the MCP, because the text node sits in a
child div rather than on the button. Do not rely on it. Use:

```js
const btn = page.locator('header button[type="button"]').first();
const label = (await btn.innerText()).trim();
if (label === 'Follow') await btn.click();
```

**Correction 2026-10-14: `.first()` is wrong on profiles with several bio links.** Those profiles
show a links button, such as `augustinafinejewelry.etsy.com and 1 more`, as an earlier
`header button[type="button"]` than Follow. Verified live in the Playwright MCP:

| Profile | Header buttons, in order |
|---|---|
| `/augustinafinejewelry/` | `augustinafinejewelry.etsy.com and 1 more`, `Follow` |
| `/thesystemtna/` | `linktr.ee/TheSystemTNA and 3 more`, `Follow` |
| `/al.chl/` | `Follow` |

The 2026-09-15 verification used only profiles like `/al.chl/`. The script now uses
`findFollowButton()`: walk every `header button[type="button"]` and take the one whose trimmed text
is exactly `Follow`, `Following`, `Requested` or `Follow Back`. The exact comparison still guards the
substring trap below. `Follow Back` is treated as `Follow` and is not yet verified live.

### Profile grid links are a different shape

On a profile page the grid links are:

```
/<username>/p/<shortcode>/
/<username>/reel/<shortcode>/
```

**not** `/p/<shortcode>/`. A selector of `a[href^="/p/"]` returns 0 matches on a profile page. Use
`a[href*="/p/"], a[href*="/reel/"]`. Verified: 30 matches on `/realhappyhippoherbals/`, tiles
331 x 441 px. The grid also lazy loads, so scroll before counting.

### Not found - verified

`/vb_probe_no_such_user_9f3k2/` renders:

- no `header` element at all
- main text "Sorry, this page isn't available."
- document.title is `Page not found • Instagram` at navigation, but Instagram rewrites it to
  `Instagram` after hydration, so **do not test the title**

Detection: wait for either `header button[type="button"]` or the missing-header state, whichever
settles first. Classify a missing header as `Error: profile not found`.

**2026-10-14: the script did not follow this until today.** It waited a fixed 2.5 to 4.5 seconds and
then treated "no `<header>`" as a missing profile, so a slow load was recorded as `profile not found`
(@westonerz: "profile not found" in the morning dry run, "already following" that afternoon).
`waitForProfile()` now polls every 0.5 s for up to 15 s for either a follow-state button
(`findFollowButton()`) or the text `Sorry, this page isn't available.`, whichever appears first.
Neither within 15 s is recorded as `no follow button after 15s` with the header buttons seen, plus
a screenshot.

### Private account - NOT yet verified

No private account was available during the probe. Planned handling, to confirm on the first live
run: a private profile still shows a `Follow` button, and after clicking it the text becomes
`Requested` rather than `Following`. Treat `Requested` as its own CSV status, not an error. The body
text "This account is private" is a secondary signal and is localized.

---

## 4. Post view has two different shapes

This one matters, because the comment-reading action depends on which shape is open.

| How it was opened | Result | Comment container |
|---|---|---|
| Click a grid tile on a profile | `[role="dialog"]` modal, URL becomes `/p/<shortcode>/` | a scrollable `ul` inside the dialog |
| `page.goto('/p/<shortcode>/')` | full page, **no** dialog | comments are in the document flow, the window scrolls |

Verified both. Clicking `/realhappyhippoherbals/p/DdR4_lLRJCO/` produced a dialog whose comment `ul`
had scrollHeight 985 against clientHeight 593. Navigating directly to `/p/DdNOWlDjUzU/` produced no
dialog and no scrollable `ul`, and `window.scrollBy` moved the page from y=0 to y=887.

The script opens posts by clicking, so it normally gets the modal. Handle both anyway:

```js
const dlg = page.locator('[role="dialog"]');
if (await dlg.count()) { /* scroll the inner ul */ } else { /* scroll the window */ }
```

Close the modal with `svg[aria-label="Close"]` or Escape.

### Comment scrolling caveat

Calling `ul.scrollBy()` and assigning `ul.scrollTop` from page JavaScript did **not** move the
comment list in the probe. scrollTop stayed at 0 while the list re-rendered underneath.

Use `page.mouse.move()` over the comment column followed by `page.mouse.wheel(0, delta)` instead,
which produces trusted input events through CDP. Confirm this at build time, since it could not be
tested through the MCP.

Loading older comments needs a "Load more comments" button click. Scrolling alone did not grow the
comment count (15 occurrences of "Reply" before and after four scroll steps). For dwell time this
does not matter.

---

## 5. Action block - NOT yet verified

Nothing triggered a block during the probe. Planned detection, to confirm the first time it fires:

```js
/Action Blocked|Try Again Later|We restrict certain activity|Please wait a few minutes/i
  .test(await page.locator('body').innerText())
```

plus any `[role="dialog"]` that is not the post modal. A second signal that needs no text match: the
Follow button reverts from `Following` to `Follow` within a few seconds of a successful-looking
click. Check the button state again after 3 to 5 seconds before recording `Followed`.

On detection: write `Error: action blocked` on the current row, stop the run, exit non-zero.

---

## Probe actions taken on the account

Three real actions were performed while verifying. All were intentional and none need reverting.

| Action | Target | Why |
|---|---|---|
| Like | `/p/DcY1QXEF8o2/` (al.chl) | Only way to confirm the `Like` to `Unlike` flip. |
| Follow | `/painbcofficial/` (Pain BC) | Only way to confirm the `Follow` to `Following` flip. A real target: `chronic-pain-instagram-canada.md` names Pain BC as the most credible Canadian pain-advocacy account and recommends following it. |
| Watched stories | tippytop2020, coryfuller2026 | Opening the viewer was needed to expose the tray `aria-label` seen state. |

---

## 6. Profile likes (verified live 2026-10-15)

Verified in the Playwright MCP, signed in as @verifiedbotanicals, without clicking any Like, Follow or
Unlike. Used for the profile-likes action; see [profile-likes-progress.md](profile-likes-progress.md).

### Profile grid tiles

```
main a[href*="/p/"], main a[href*="/reel/"]
```

The first match is the top-left tile; matches run left to right, top to bottom (checked by position).
12 tiles were present on load on @american_kratom_assoc.

| Tile type | href | Marker inside the tile |
|---|---|---|
| Photo or carousel post | `/<user>/p/<code>/` | none, or a carousel icon |
| Reel | `/<user>/reel/<code>/` | `svg[aria-label="Clip"]` |
| Pinned post | `/<user>/p/<code>/` | `svg[aria-label="Pinned post icon"]` |

Seen on @augustinafinejewelry: three pinned posts first, then two reels, then plain posts. Pinned posts
and reels are both included in the action (Ildar, 2026-10-15), so the markers are not needed for
selection.

### Opening a tile

Clicking any tile, photo, pinned post or reel, opens a `[role="dialog"]` and changes the URL to
`/p/<code>/`, including for reels. The post code for the record is the last path segment. Escape closes
the dialog and returns to the profile.

### The post's like button

The dialog holds two kinds of like icon: the post's own (24 px, in the action bar) and one per comment
(12 px, inside the comment `ul`). The action bar is the innermost `section` that holds a Comment icon
and a like icon:

```
[role="dialog"] section:not(:has(section)):has(svg[aria-label="Comment"]):has(svg[aria-label="Like"], svg[aria-label="Unlike"])
```

Click target: `<bar> div[role="button"]:has(svg[aria-label="Like"])`. Liked state: `<bar> svg[aria-label="Unlike"]`.

| Case | Bars matched | Like buttons | Unlike icons |
|---|---|---|---|
| Photo post, dialog | 1 | 1 | 0 |
| Reel, dialog | 1 | 1 | 0 |
| Pinned photo post, dialog | 1 | 1 | 0 |
| Already-liked post (`/p/Dd8zjuNpGgj/`), opened by URL, full page | 1 | 0 | 1 |

On the full page there is also an outer `section` wrapping the post and its comments; the
`:not(:has(section))` part is what excludes it.

### Follow state and private profiles

| Case | Example | Header button | Tiles | Page text |
|---|---|---|---|---|
| Followed | @american_kratom_assoc | `Following` (after any bio-links button) | shown | |
| Private, request accepted | @logan_rainer | `Following` | shown (1) | |
| Private, request pending | @aabssmalik | `Requested` | 0 | "This profile is private" |

Use the button and the tile count, not the text: the text was "This account is private" when section 3
was written and is "This profile is private" now.
