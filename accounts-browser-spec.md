# Accounts Browser - Developer Specification

**Version 1.0, 2026-09-17. Status: approved, ready to build.**

A filtering and bulk-tagging web interface over the Instagram follow queue, so unwanted accounts can
be excluded before the follow automation reaches them.

This document is self-contained. A developer should not need any other context to build it.

---

## 1. What to build, in one paragraph

A single web page, `accounts.php`, served by the existing local PHP application. It lists rows from
the MySQL table `ig_accounts` joined to `ig_profile_stats`, with stacked filters on nine fields
supporting `%` wildcards. The user ticks rows, or selects every row matching the current filter, and
applies one of two bulk actions: mark as do-not-follow with a free-text note, or revert to pending.
Three new JSON endpoints are added to the existing `api.php`. No new frameworks, no external
dependencies.

---

## 2. Background and business reason

The system scrapes the follower list of a competitor Instagram account into MySQL, then a Node and
Playwright script follows those accounts at a rate of roughly 100 per day, 3 to 5 minutes apart, to
attract follow-backs for the brand @verifiedbotanicals.

The queue is ordered by `id` with no notion of what kind of account each row is. On 2026-09-17 at
17:11 the script followed **@firstcoastkavakombucha**, a kava business. In the same run it also
processed `@leafportbotanicals` and `@mitwellness9`. These are competitors and industry accounts
rather than potential customers. Following them consumes one of the day's limited actions, signals
the brand's activity to competitors, and yields no useful follow-back.

There is currently no way to exclude an account except to notice it going past and intervene. This
interface makes exclusion a bulk operation performed in advance.

Measured scale of the problem across the 2,500 enriched accounts:

| Bio keyword | Rows |
|---|---|
| kava | 94 |
| botanical | 60 |
| kratom | 46 |
| smoke | 35 |
| wholesale | 31 |
| cbd | 16 |
| vape | 13 |
| hemp | 12 |
| delta | 5 |
| distro | 5 |
| dispensary | 1 |

Plus 420 rows flagged `is_business = 1`. These sets overlap. The combined population is in the low
hundreds, roughly 10% of the currently eligible queue.

---

## 3. Environment

| Item | Value |
|---|---|
| Web server | Laragon on Windows, Apache, **port 8080** (port 80 is refused on this machine) |
| Application root | `C:\Users\ildar\Claude\All projects\Sunny Kratom\ig-follower-scraper\` |
| Web root | `<application root>\web\public\`, symlinked to `C:\laragon\www\ig-follower-scraper` |
| Base URL | `http://localhost:8080/ig-follower-scraper/` |
| PHP | 8.x, no Composer, no third-party packages |
| Database | MySQL via Laragon, `127.0.0.1:3306`, database `sunny_kratom`, user `root`, empty password |
| Config | `config.php` at the application root returns an array. Never commit it. |

### Existing files the developer will touch or reuse

| File | Role |
|---|---|
| `bootstrap.php` | Loads `config.php`, sets the timezone to America/New_York, registers an autoloader for `src/` |
| `src/Config.php` | Dot-path config reader. `Config::now()` returns a MySQL DATETIME string in America/New_York |
| `src/Db.php` | PDO singleton. utf8mb4, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES` off. Helpers: `Db::one()`, `Db::all()`, `Db::run()`, `Db::scalar()`, `Db::pdo()` |
| `web/public/api.php` | Existing localhost-guarded JSON endpoint, a `switch` on `$_GET['action']`. Add the three new actions here |
| `web/public/index.php` | Existing dashboard. Copy its CSS custom properties and layout conventions |
| `web/public/accounts.php` | **New.** The page this spec describes |

### Two constraints that will bite if ignored

1. **`PDO::ATTR_EMULATE_PREPARES` is false.** MySQL prepares statements natively, so **a named
   placeholder cannot be repeated** in one statement. Using `:term` twice throws
   `SQLSTATE[HY093]: Invalid parameter number`. Give each binding a distinct name.
2. **Timestamps are written by PHP, not MySQL.** Use `Config::now()`. Do not use `NOW()`, which
   returns the server timezone rather than America/New_York.

---

## 4. Data model

### 4.1 `ig_accounts` - the follow queue

```sql
CREATE TABLE `ig_accounts` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ig_user_id`          VARCHAR(32)     NOT NULL,
  `username`            VARCHAR(255)    NOT NULL,
  `full_name`           VARCHAR(255)        NULL,
  `profile_pic_url`     TEXT                NULL,
  `is_verified`         TINYINT(1)      NOT NULL DEFAULT 0,
  `is_private`          TINYINT(1)      NOT NULL DEFAULT 0,
  `first_seen_at`       DATETIME        NOT NULL,
  `last_seen_at`        DATETIME        NOT NULL,
  `follow_status`       ENUM('pending','followed','requested','already_following','error','skipped')
                        NOT NULL DEFAULT 'pending',
  `follow_attempted_at` DATETIME            NULL,
  `follow_note`         VARCHAR(255)        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ig_user_id` (`ig_user_id`),
  KEY `idx_username` (`username`),
  KEY `idx_queue` (`follow_status`, `is_private`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`skipped` already exists in the enum and is the value this interface writes. No migration is needed.

**`follow_note` is VARCHAR(255).** Truncate or reject longer notes explicitly rather than letting
MySQL truncate silently.

### 4.2 `ig_profile_stats` - enrichment, one row per account

```sql
CREATE TABLE `ig_profile_stats` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ig_user_id`      VARCHAR(32)     NOT NULL,
  `username`        VARCHAR(255)    NOT NULL,
  `user_full_name`  VARCHAR(255)        NULL,
  `followers_count` INT UNSIGNED        NULL,
  `follows_count`   INT UNSIGNED        NULL,
  `posts_count`     INT UNSIGNED        NULL,
  `is_private`      TINYINT(1)          NULL,
  `is_verified`     TINYINT(1)          NULL,
  `is_business`     TINYINT(1)          NULL,
  `biography`       TEXT                NULL,
  `external_url`    TEXT                NULL,
  `public_email`    VARCHAR(255)        NULL,
  `public_phone`    VARCHAR(64)         NULL,
  `category`        VARCHAR(255)        NULL,
  `fb_id`           VARCHAR(64)         NULL,
  `location_id`     VARCHAR(64)         NULL,
  `account_type`    INT                 NULL,
  `profile_pic`     TEXT                NULL,
  `user_url`        VARCHAR(255)        NULL,
  `scraped_at`      DATETIME            NULL,
  `run_id`          BIGINT UNSIGNED     NULL,
  `enriched_at`     DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_stats_user` (`ig_user_id`),
  KEY `idx_username` (`username`),
  KEY `idx_followers` (`followers_count`),
  KEY `idx_posts` (`posts_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.3 The join

```sql
FROM ig_accounts a
LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
```

**LEFT, never INNER.** Only 2,500 of 10,857 accounts are enriched. An inner join would silently hide
8,357 rows. A filter on any `ig_profile_stats` column naturally restricts results to enriched rows,
which is correct and expected, but the unfiltered view must show everything.

### 4.4 Row counts as of 2026-09-17

These drift as the follow script runs. Treat them as a starting point and re-derive before testing.

| Measure | Count |
|---|---|
| `ig_accounts` total | 10,857 |
| `ig_profile_stats` total (enriched) | 2,500 |
| `follow_status = 'pending'` | 10,660 |
| `follow_status = 'followed'` | 105 |
| `follow_status = 'requested'` | 87 |
| `follow_status = 'already_following'` | 4 |
| `follow_status = 'error'` | 1 |
| `follow_status = 'skipped'` | 0 |
| `is_business = 1` | 420 |
| `is_business = 0` | 2,080 |
| `biography` NULL or empty | 838 |
| `category` NULL or empty | 2,134 |

---

## 5. Functional requirements

### 5.1 Filters

All filters are ANDed. An empty filter is ignored and must not be turned into a condition such as
`LIKE '%%'` or `= ''`.

| # | Field | Source column | Control | Semantics |
|---|---|---|---|---|
| F1 | Username | `a.username` | text | Wildcard rules, section 5.2 |
| F2 | Full name | `s.user_full_name` | text | Wildcard rules |
| F3 | Biography | `s.biography` | text | Wildcard rules, comma-separated terms ORed |
| F4 | Category | `s.category` | dropdown, populated from the facets endpoint, plus "(none)" | Exact match. "(none)" means `s.category IS NULL OR s.category = ''` |
| F5 | Business account | `s.is_business` | tri-state: Any / Yes / No | Yes is `= 1`, No is `= 0`. Neither matches NULL, which is an unenriched row |
| F6 | Follow status | `a.follow_status` | multi-select checkboxes, all six enum values | `IN (...)`. No selection means no condition |
| F7 | Private | `a.is_private` | tri-state: Any / Yes / No | |
| F8 | Followers | `s.followers_count` | two number inputs, min and max | Each side optional. Inclusive |
| F9 | Posts | `s.posts_count` | two number inputs, min and max | Each side optional. Inclusive |
| F10 | Note | `a.follow_note` | text | Wildcard rules. Lets the user find everything tagged with a given reason |

Each of F1, F2, F3 and F10 additionally offers an **"is empty"** checkbox, which replaces the text
condition with `(col IS NULL OR col = '')`. 838 bios and 2,134 categories are empty, and those are
meaningful groups to isolate.

### 5.2 Wildcard rules

Applies to every text filter. The rule is deliberately simple and must be documented in the UI as a
hint under the field.

| User input | SQL pattern | Meaning |
|---|---|---|
| `kava` | `%kava%` | contains |
| `kava%` | `kava%` | starts with |
| `%bar` | `%bar` | ends with |
| `%kava%` | `%kava%` | contains, written explicitly |
| `k_va` | `k_va` | single-character wildcard |
| `kava, kratom` | `%kava%` OR `%kratom%` | alternatives |
| `kava%, %bar` | `kava%` OR `%bar` | alternatives, each following its own rule |

Rule in words: **split the input on commas and trim each part. If a part contains `%` or `_`, use it
verbatim as the LIKE pattern. Otherwise wrap it in `%...%`. Combine the parts of one field with OR,
and combine different fields with AND.**

Comparison is case-insensitive because both columns use `utf8mb4_unicode_ci`.

Known limitation, accepted: a literal `%` or `_` cannot be searched for. No Instagram bio search
needs this. Do not add escape syntax.

### 5.3 Results table

Columns, left to right:

| Column | Source | Notes |
|---|---|---|
| Checkbox | | Disabled when `follow_status <> 'pending'`, see 5.6 |
| ID | `a.id` | Sortable |
| Username | `a.username` | Links to `https://www.instagram.com/<username>/`, opens in a new tab |
| Full name | `s.user_full_name` | |
| Followers | `s.followers_count` | Sortable, right aligned, thousands separated, `-` when NULL |
| Posts | `s.posts_count` | Sortable, right aligned, `-` when NULL |
| Business | `s.is_business` | Yes / No / `-` for unenriched |
| Private | `a.is_private` | Yes / No |
| Category | `s.category` | `-` when empty |
| Biography | `s.biography` | Truncated to about 80 characters, full text in a `title` attribute |
| Status | `a.follow_status` | Colour-coded chip |
| Note | `a.follow_note` | Truncated, full text in a `title` attribute |

Sorting: `id`, `username`, `followers_count`, `posts_count`, ascending or descending. Default
`id ASC`, which is the order the follow script processes the queue. **The sort column and direction
must come from a whitelist**, never interpolated from raw input.

Pagination: page sizes 50, 100, 200. Default 100. Show "showing X to Y of Z".

Rows where `follow_status <> 'pending'` are rendered dimmed, because they cannot be bulk-tagged.

### 5.4 Selection

Two mechanisms, and the difference matters:

- **Select visible.** A header checkbox ticks every eligible row on the current page.
- **Select all matching.** A separate control selects every row the current filter returns across all
  pages. When active the UI must state it plainly, for example "All 312 matching rows selected", and
  offer a one-click way to clear it.

Changing any filter clears the selection.

### 5.5 Bulk actions

| Action | Writes |
|---|---|
| **Do not follow** | `follow_status = 'skipped'`, `follow_note = <the note>`, `follow_attempted_at = Config::now()` |
| **Revert to pending** | `follow_status = 'pending'`, `follow_note = NULL`, `follow_attempted_at = NULL` |

The note field is required for "Do not follow" and limited to 255 characters, with a live character
counter. Provide quick-fill buttons for the recurring reasons, which set the note text and can then
be edited:

`kava bar`, `kratom vendor`, `smoke shop`, `CBD or hemp seller`, `competitor brand`, `wholesaler`,
`bot or empty account`

**A confirmation step is mandatory before any write.** It states the exact number of rows and the
note. Nothing is written until it is confirmed.

After a successful action the page reloads the current filter and reports what happened, for example
"312 rows marked do-not-follow. 4 rows left unchanged because they are not pending."

### 5.6 Safety rules

1. **Only rows with `follow_status = 'pending'` may be modified by "Do not follow".** A row that is
   `followed`, `requested`, `already_following` or `error` records something that actually happened
   on Instagram, and a bulk tag must never erase it. Enforce this in SQL with
   `AND follow_status = 'pending'`, not only in the UI.
2. "Revert to pending" may act on `skipped` rows only, for the same reason. It must not resurrect a
   `followed` or `error` row.
3. Both actions report a changed count and an unchanged count with the reason.
4. Every write runs inside a transaction.

---

## 6. API

Add to the existing `web/public/api.php`, which already enforces this guard and must continue to:

```php
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) { /* 403 */ }
```

All responses are JSON with an `ok` boolean. On failure, `ok` is false with an `error` string and an
appropriate HTTP status.

### 6.1 `GET ?action=accounts-facets`

No parameters. Used to populate the dropdowns.

```json
{
  "ok": true,
  "categories": [{"category": "Product/service", "n": 42}],
  "category_empty": 2134,
  "follow_status": [{"follow_status": "pending", "n": 10660}],
  "totals": {"accounts": 10857, "enriched": 2500}
}
```

Categories must be returned in full, not a top-N slice.

### 6.2 `GET ?action=accounts-search`

| Parameter | Type | Notes |
|---|---|---|
| `username`, `full_name`, `biography`, `note` | string | Wildcard rules |
| `username_empty`, `full_name_empty`, `biography_empty`, `note_empty` | `0` or `1` | Overrides the matching text field |
| `category` | string | Exact. The literal `__none__` means empty or NULL |
| `is_business`, `is_private` | `''`, `1`, `0` | Empty string means any |
| `follow_status` | comma-separated enum values | Empty means any |
| `followers_min`, `followers_max`, `posts_min`, `posts_max` | integer | Optional |
| `sort` | one of `id`, `username`, `followers_count`, `posts_count` | Whitelist |
| `dir` | `asc` or `desc` | Whitelist |
| `page` | integer, 1-based | |
| `per_page` | one of 50, 100, 200 | Whitelist |

```json
{
  "ok": true,
  "total": 312,
  "page": 1,
  "per_page": 100,
  "pages": 4,
  "eligible_for_action": 308,
  "rows": [
    {
      "id": 41,
      "username": "firstcoastkavakombucha",
      "user_full_name": "First Coast Kava",
      "followers_count": 1204,
      "posts_count": 318,
      "is_business": 1,
      "is_private": 0,
      "category": "Product/service",
      "biography": "Kava bar in Jacksonville",
      "follow_status": "followed",
      "follow_note": null
    }
  ]
}
```

`total` is the count over the whole filter, not the page. `eligible_for_action` is how many of those
are `pending`, so the UI can warn before the user selects all.

### 6.3 `POST ?action=accounts-bulk`

Accepts a JSON body. Reject `GET` for this action.

```json
{
  "operation": "skip",
  "note": "kava bar",
  "ids": [41, 55, 61]
}
```

or, for a whole filter:

```json
{
  "operation": "skip",
  "note": "kava bar",
  "select_all": true,
  "filters": { "biography": "kava", "is_business": "1", "follow_status": "pending" }
}
```

`operation` is `skip` or `revert`. When `select_all` is true the server re-runs the same filter logic
as `accounts-search` and updates the matching rows. **Sending the filter rather than thousands of ids
keeps the request small and guarantees the write matches the count the user was shown.**

```json
{
  "ok": true,
  "operation": "skip",
  "changed": 308,
  "unchanged": 4,
  "unchanged_reason": "not pending",
  "note": "kava bar"
}
```

Validation: `note` is required and non-empty when `operation` is `skip`, maximum 255 characters.
Reject a request with neither `ids` nor `select_all`. Reject `ids` longer than 5,000 entries.

---

## 7. Interface

One page, `accounts.php`, in the visual style of the existing `index.php`: system font stack, CSS
custom properties on `:root` with a `prefers-color-scheme: dark` block, no framework, no CDN, no
build step. All CSS and JS inline in the file, matching how `index.php` is written.

Layout top to bottom:

1. **Header.** Title, and a link back to the dashboard at `index.php`.
2. **Filter panel.** A responsive grid of the ten filters. Buttons: Apply, Reset. Pressing Enter in
   any text field applies. A one-line hint explaining the wildcard rule.
3. **Result bar.** "312 matching, 308 can be actioned". Active filters shown as removable chips. Page
   size selector. The two selection controls.
4. **Table.** Sticky header. Sortable column headers. Dimmed non-pending rows.
5. **Action bar.** Appears only when something is selected. Shows the selection count, the note input
   with its character counter and quick-fill buttons, and the two action buttons.
6. **Pagination.** First, previous, page numbers, next, last.

**Filter state lives in the URL query string**, so a filter can be bookmarked, shared and reached
with the browser back button. Reloading the page must restore the same view.

Empty state: when a filter returns nothing, show a clear message and hide the action bar.

Responsive down to a laptop screen. This is a local tool for one operator; phone support is not
required.

---

## 8. Security

The tool is localhost-only and single-user, but the rules below are not optional because the data
comes from a third-party scrape and contains arbitrary user-controlled text.

1. **Every filter value is a bound parameter.** Only `ORDER BY`, `LIMIT` and `OFFSET` are
   interpolated, and each comes from a whitelist or is cast to an integer.
2. Remember that named placeholders cannot be repeated, see section 3.
3. **Escape all output.** Bios and full names contain arbitrary text, emoji and HTML-significant
   characters. Use `htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` on every rendered
   value, including inside `title` attributes.
4. Keep the existing localhost guard on `api.php`.
5. The bulk endpoint must be POST only.

---

## 9. Performance

`ig_accounts` holds about 11,000 rows and `ig_profile_stats` about 2,500. This is small. Do not add
caching, and do not add indexes beyond the existing ones without measuring first.

A `LIKE '%term%'` on `biography` is a full scan of 2,500 rows, which is immaterial at this size.

The count query and the row query may be run separately. `SQL_CALC_FOUND_ROWS` is deprecated; use a
separate `COUNT(*)` with the same `WHERE`.

---

## 10. Test plan

Every expected count must be verified with an independent SQL query run directly against MySQL, not
by reading the number the page itself displays. Snapshot counts are from 2026-09-17 and will drift;
re-derive them before testing.

| # | Scenario | Expected |
|---|---|---|
| 1 | No filters | `total` equals `SELECT COUNT(*) FROM ig_accounts`, 10,857 at time of writing |
| 2 | Follow status = pending | 10,660 |
| 3 | Business = Yes | 420 |
| 4 | Biography contains `kava` | 94 |
| 5 | Biography `kava, kratom` | equals `COUNT(*) WHERE biography LIKE '%kava%' OR biography LIKE '%kratom%'`, and is less than 94 + 46 because some bios contain both |
| 6 | Business = Yes AND biography `kava` | fewer than either filter alone. Spot-check three returned rows against the database |
| 7 | Business = Yes AND biography `kava` AND status = pending | a subset of test 6 |
| 8 | Biography `kava%` | fewer rows than `kava`, and every row's bio starts with kava |
| 9 | Biography `%bar` | every row's bio ends with bar |
| 10 | Username `mit%` | every username starts with mit |
| 11 | Followers min 50, max 500 | every row within range, no NULLs returned |
| 12 | Category equal to a specific value | matches that value's count from the facets endpoint exactly |
| 13 | Biography "is empty" | 838 |
| 14 | Category "(none)" | 2,134 |
| 15 | Filter matching zero rows | empty state, no PHP notice, action bar hidden |
| 16 | Tick 3 rows, Do not follow, note "test" | exactly 3 rows change to `skipped` with that note and a New York timestamp. Verify in SQL |
| 17 | Select all matching on a filter spanning several pages | `changed` equals the `total` shown before the action |
| 18 | Selection including a `followed` row | that row is untouched. Response reports it as unchanged |
| 19 | Revert those rows | back to `pending`, note and timestamp NULL |
| 20 | Eligible queue count before and after a skip | `SELECT COUNT(*) FROM ig_accounts a JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id WHERE a.follow_status='pending' AND s.followers_count >= 50` drops by exactly the number skipped |
| 21 | Enter `' OR 1=1 --` in each text filter | 0 rows, no error, no SQL leak |
| 22 | Enter `<script>alert(1)</script>` as a note, then view the row | rendered as text, not executed |
| 23 | Note longer than 255 characters | rejected or truncated deliberately, never silently mangled |
| 24 | Reload after applying filters | identical result set, filters restored from the URL |

---

## 11. Acceptance criteria

1. All 24 test cases pass, with counts verified independently in SQL.
2. Stacking three or more filters produces a correct intersection.
3. `%` wildcards work as specified in every text field.
4. Select-all-matching writes exactly the number of rows the interface promised.
5. No bulk action ever modifies a row that is not `pending`.
6. The follow script's eligible queue count drops by exactly the number of rows skipped.
7. Filter state survives a page reload.
8. No PHP notices, warnings or JavaScript console errors in normal use.
9. The page is legible in both light and dark colour schemes.

---

## 12. Out of scope

- Creating or deleting accounts. Rows come from the Apify scrape and are never hand-created.
- Editing any field other than `follow_status` and `follow_note`.
- A priority or ordering concept. The follow queue is strictly `ORDER BY id` and stays that way.
- Authentication. The localhost guard is the access control.
- Undo history beyond the "Revert to pending" action.
- Mobile layout.

---

## 13. Related bug, to fix separately

At 17:08 on 2026-09-17 the follow script recorded, for `@chuck.obin`:

```
error :: unexpected button text: Unblock
```

That account is blocked by the operator, so Instagram renders "Unblock" where the Follow button would
be. The script correctly refused to click an unrecognised button, but being blocked is a permanent
condition rather than an error. `instagram-follower.js` should treat a button reading `Unblock` as
`follow_status = 'skipped'` with the note "blocked account", so it is never retried and does not
inflate the error count.

This is a change to the Node script, not to this interface.
