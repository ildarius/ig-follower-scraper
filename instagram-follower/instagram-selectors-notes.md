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

## 2. Story tray - verified

Story items are **not** links:

```
ul > li > div[role="button"]   innerText is the username
          └ ... > canvas       89 x 89, the ring
```

12 to 17 items present depending on tray scroll position.

**Seen state is readable from the DOM.** Once the story viewer has been opened, the tray buttons
carry:

```
aria-label="Story by <username>, seen"
aria-label="Story by <username>, not seen"
```

So "watch unseen stories only" is a DOM filter, not something the script has to track itself. The
ring canvas does not need to be inspected.

Open the first story:

```js
page.locator('ul li div[role="button"]:has(canvas)').first().click()
```

Verified: opened `/stories/tippytop2020/`, then auto-advanced to
`/stories/coryfuller2026/3986835644155831522/`.

### Story viewer controls

Read from `svg[aria-label]` inside the viewer: `Pause`, `Previous`, `Next`, `Close`, `Like`,
`Direct`, `Menu`.

**Do not click the story `Like`.** Liking a story sends a DM notification to the author, which is a
different action from liking a feed post.

**Escape exits the viewer.** Verified: URL returned from `/stories/...` to `https://www.instagram.com/`.

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
