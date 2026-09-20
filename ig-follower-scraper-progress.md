# IG Follower Scraper - progress / resume file

Project description: [README.md](README.md)
Started 2026-09-16. Status: **DONE. 10,857 followers of @american_kratom_assoc are in MySQL, all `pending`.**

Resume rule: read this file and `README.md`, then continue from the first unchecked item.

---

## Decisions

| # | Decision | Status |
|---|---|---|
| 1 | Target: @american_kratom_assoc followers, 12.4K profiles, about $14.26 at $1.15/1,000 | settled 2026-09-16 |
| 2 | Language PHP, served by Laragon, same localhost-guarded pattern as the Karkusha app | settled 2026-09-16 |
| 3 | Database `sunny_kratom`, three tables | settled 2026-09-16 |
| 4 | MySQL is the follow queue. The instagram-follower script reads and writes `ig_accounts`, the CSV is dropped | settled 2026-09-16 |
| 5 | Capped test run of 500 first (about $0.58), then the uncapped run | settled 2026-09-16 |

## Phase 1 - build

- [x] Schema: `ig_accounts`, `ig_relations`, `ig_scrape_runs`
- [x] `Apify` REST client: start, get, abort, dataset paging
- [x] `Scraper`: start, refresh, resumable `importBatch`, `importAll`, stats
- [x] `cli.php`
- [x] `web/public/api.php`, localhost guarded
- [x] `web/public/index.php` dashboard with an import-until-done loop
- [x] All files pass `php -l`

## Phase 2 - install

- [x] Copy `config.example.php` to `config.php` and paste the Apify token
- [x] Run `sql/schema.sql` in HeidiSQL
- [x] Create the Laragon symlink (admin CMD)
- [x] Confirm the dashboard reports 0 accounts rather than a database error

**Live URL: `http://localhost:8080/ig-follower-scraper/`** (verified 2026-09-16).

Port 80 refuses connections on this machine, so the `:8080` is mandatory. The pretty hostname
`http://ig-follower-scraper.test/` does not resolve, because Laragon writes its hosts entry on
auto-detection and did not pick up the manually created symlink. Laragon menu -> Apache -> Reload
adds it, if wanted.

## Phase 3 - test run

Done 2026-09-16. Apify run `GcoLrrL1qErnEanbj`, dataset `36H9xtjZnvX9sixnH`.

- [x] Started with `resultsLimit` 500
- [x] Polled to `SUCCEEDED`
- [x] Imported: 48 fetched, 48 imported, 0 skipped
- [x] Row count matches `items_total` (48), emoji survived (`Mrs. Pierce` with a 4-byte heart), all rows `pending`
- [ ] Idempotency of the upsert is still unproven. The cursor sits at 48 so a repeat import fetches nothing. To test properly: `UPDATE ig_scrape_runs SET cursor_offset = 0 WHERE id = 1;` then import again and confirm `ig_accounts` stays at 48 rows.

Two bugs found and fixed, both documented in README.md: the item count is not in the run object, and
a repeated named placeholder breaks under native prepares.

Actual cost of the run: about $0.06 for 48 profiles, since billing is per result.

## Phase 4 - full run - DONE, see Completed 2026-09-17 above

A free Apify account returns only the first page. The run log:

```
WARN  Users without paid subscriptions can only access the first page of followers
      for american_kratom_assoc
```

48 of 12,400. `resultsLimit` was 500, so this is not the input and not the account being scraped.

### The account, read from the API on 2026-09-17

`?action=account` calls `GET /v2/users/me`. Account `harmonic_zest` returns:

| Field | Value |
|---|---|
| `plan.id` / `plan.tier` | `FREE` |
| `monthlyBasePriceUsd` | 0 |
| `monthlyUsageCreditsUsd` | 5 |
| `maxMonthlyUsageUsd` | **5** |
| `dataRetentionDays` | 7 |
| `supportLevel` | COMMUNITY |

Prepaid credit is not the same as a paid subscription. The actor gates on plan tier, and
`maxMonthlyUsageUsd` caps this account at $5 of spend per month regardless of balance, so the
$14.26 full scrape cannot run here even ignoring the first-page limit. `/users/me` does not expose a
prepaid balance, so the billing page is the place to check that.

`dataRetentionDays: 7` means a dataset is deleted a week after its run. Import promptly.

$5 a month buys roughly 4,300 profiles at $1.15 per 1,000.

### Prepaid credit without a subscription is not possible (checked 2026-09-17)

Apify's help docs: "If you used the prepaid usage on the free plan you can't buy additional credits.
You would need to subscribe to a subscription plan."

The subscription fee is not charged on top of usage, it becomes the usage credit: "The summary cost
of these CUs will be deducted from your prepaid usage." Starter is listed at $19/month and provides
$19 of platform usage. The full 12,400-follower scrape costs about $14.26, so it fits inside one
month of Starter.

Prices move. This said $19 on 2026-09-17; an older figure of $39 was wrong. Read the live page.

**Do not take the Creator plan without checking first.** $1/month, $6 upfront for 6 months, $500 of
platform usage, which looks ideal. Its own page says subscribing "will limit the access to Actors on
Apify Store", and this project depends on a Store actor. Unresolved whether an official `apify/...`
actor is exempt as a "Universal Actor". Ask Apify support before paying.

Not verified: whether a monthly plan can be cancelled after one month. The help article covers
upgrades and billing cycles but not cancellation.

- [ ] Paste the new account's token into `config.php`, then check `?action=account` for its tier,
      `maxMonthlyUsageUsd` and `dataRetentionDays`. New accounts may carry trial credit.
- [ ] Decide: subscribe to Starter for one month, or get the follower list another way
- [ ] If paying: **first** run with `resultsLimit` 100 (about $0.12) and check `?action=log`. The
      gate is worded "users without paid subscriptions", so confirm that warning is gone before
      spending $14. Twelve cents of insurance.
- [ ] Then start uncapped, about $14.26, import until done, record the final row count here

## Completed 2026-09-17

Apify account `clearheaded_spidercrab` on **STARTER**: $19 base arriving as $19 credit,
`maxMonthlyUsageUsd` 19, `dataRetentionDays` 31 (up from 7 on FREE).

**Starter lifted the first-page gate.** Test run 2 with `resultsLimit` 100 paginated 50 + 50 to
100/100 across 2 requests, with no "paid subscription" warning anywhere in the log. On FREE the same
actor made 1 request, returned 48, and warned. The $0.12 test was worth running.

**Run 3** (Apify run `1ZIT2I1MNFqU9ftWA`), `resultsLimit` 13000, SUCCEEDED.

| | |
|---|---|
| Followers returned | **10,857** |
| Imported | 10,857, 0 skipped, 0 errors |
| Private accounts | 4,550 (42%) |
| Public accounts | 6,307 |
| Cost | **$19.00** ($1.75/1,000 actual, not the $1.15 advertised), plus $0.18 for the test |

**Instagram's displayed count is not the enumerable count.** The profile shows 12.4K followers; the
actor could enumerate 10,857, about 12% fewer. Deactivated and deleted accounts are still counted in
the profile total. Budget against the displayed number, but do not treat the shortfall as a failure.

**The upsert is idempotent, now proven by data rather than by reading the SQL.** Runs 1 (48 rows) and
2 (100 rows) had already been imported into the same tables before run 3 imported 10,857. Had the
upsert been inserting duplicates, `ig_accounts` would hold 11,005 rows. It holds exactly 10,857,
matching `items_total`. The earlier open item asking for a manual cursor-reset test is closed.

**New endpoint: `?action=import-all&seconds=N`.** Imports batch after batch inside a time budget
(default 25s, max 120s) instead of one request per 500 rows. The full 10,357-row remainder came in
through a single 60-second call doing 21 batches. The per-batch cursor still persists, so a request
that dies mid-loop resumes rather than repeating.

## What the followers actor returns, and what enrichment would cost

Verified 2026-09-17 against run 3's real dataset, with `clean=false` so hidden fields would show:
`apify/instagram-followers-following-scraper` returns exactly **8 fields** and no profile statistics.

| Apify field | Stored in |
|---|---|
| `userId` | `ig_accounts.ig_user_id` |
| `username` | `ig_accounts.username` |
| `fullName` | `ig_accounts.full_name` |
| `profilePicUrl` | `ig_accounts.profile_pic_url` |
| `isVerified` | `ig_accounts.is_verified` |
| `isPrivate` | `ig_accounts.is_private` |
| `sourceUsername` | `ig_relations.source_username` |
| `type` | `ig_relations.relation` |

Nothing is dropped. A followers-and-following actor returns list membership, not profile stats, so
**follower counts need a second actor that visits each profile.**

`ig_raw_items` now stores every payload verbatim (10,857 rows, about 8MB), backfilled by rewinding
run 3's cursor and re-importing. Re-reading a dataset is free; only producing results is charged.
It is redundant while the mapping covers all 8 fields, and is kept only so a future run with a richer
actor captures extra fields without a code change first. Drop it if it is not wanted.

### Enrichment actor prices (checked 2026-09-17)

| Actor | Price | Notes |
|---|---|---|
| `apify/instagram-profile-scraper` | $2.30 / 1,000 on Starter | Full profile. **Do not default to this**, it is roughly double the purpose-built tools. |
| `apify/instagram-followers-count-scraper` | from $1.30 / 1,000 | Official. 8 fields, verified by probe. |
| `memo23/instagram-followers-count-scraper` | from $1.30 / 1,000 + $0.001 per run | **Use this one.** 21 fields, a strict superset of the official 8, verified by probe. Input key `usernames` (array). Error rows not charged. |
| `fetch_cat/instagram-followers-count-scraper` | from $1.33 / 1,000 | 15 fields, input key `profiles`, max 1,000 entries per run so 10,857 needs 11 runs. |
| `scrapebase/instagram-followers-count-scraper` | $19.99/month + usage | A **rental** actor. Avoid, this is the subscription model Ildar does not want. |

### Adoption, checked 2026-09-17, which overrides the "most fields wins" reading

| | apify (official) | memo23 | fetch_cat |
|---|---|---|---|
| Rating | 4.8 | 5.0 | 0.0 |
| Reviews | 22 | 1 | 0 |
| Monthly users | 626 | 17 | 1 |
| Last updated | 6 hours ago | 9 days ago | 19 days ago |

Adoption favours the official actor, and that reading was **overturned by probing both** on the same
handle 2026-09-17. Both SUCCEEDED and both reported @american_kratom_assoc at 12,468 followers and
149 following, so accuracy matches. Adoption was only ever a proxy for "does it work", and the probe
answered that directly.

**Exact output, both verified live, not read off a docs page:**

| Actor | Fields | What it returns |
|---|---|---|
| `apify/...` | 8 | `profilePic`, `userName`, `followersCount`, `followsCount`, `timestamp`, `userUrl`, `userFullName`, `userId` |
| `memo23/...` | 21 | all 8 of the above, plus `postsCount`, `isPrivate`, `isVerified`, `isBusiness`, `biography`, `externalUrl`, `publicEmail`, `publicPhone`, `category`, `fbId`, `locationId`, `accountType`, `scrapedAt` |

**Take memo23.** Same price, strict superset, and its extra fields are the target filter this project
needs: `postsCount` separates real accounts from empty shells, `isBusiness`/`category`/`externalUrl`
separate vendors from consumers, `biography` allows keyword filtering against the chronic-pain
audience research, and `publicEmail`/`publicPhone` are outreach data that would otherwise cost a
second pass.

memo23's log prints `user is paying:: true` on a Starter plan, confirming it checks subscription
state the same way the followers actor does.

### `?action=probe` - answer "what does this actor return" for a third of a cent

`?action=probe&actor=<id>&input=<urlencoded JSON>&wait=90` starts any actor with any input, polls to
terminal, and returns the raw dataset with `clean=false` plus the log tail. Both field lists above
came from it. Use it before writing code against any new actor; docs pages do not enumerate output
fields and the summaries of them have been wrong twice in this project.

Cost at $1.30 / 1,000: all 10,857 is $14.11, the 6,307 public accounts are $8.20. Remaining credit
after run 3 is about $6.39, covering roughly 4,900 profiles.

"from $1.30" implies tiered pricing, so confirm the Starter rate on a small run before committing.

## Real prices, read from Apify's accounting 2026-09-17

**Advertised per-result prices on actor pages were wrong by 50 to 80%.** `?action=run-cost&run=N`
reads `usageTotalUsd` from the run object itself. Use it; never quote a cost from an actor page.

| Run | Actor | Results | Advertised | Actual | Real rate |
|---|---|---|---|---|---|
| 3 | `apify/instagram-followers-following-scraper` | 10,857 | $1.15 / 1,000 | **$19.00** | $1.75 / 1,000 |
| 4 | `memo23/instagram-followers-count-scraper` | 100 | from $1.30 / 1,000 | **$0.238** | $2.38 / 1,000 |

The word "from" in "from $1.30" is load-bearing. Earlier notes in this file quoting $1.15 and $1.30,
and the $12.49 figure reported for run 3, are all wrong. Run 3 cost $19.00 and consumed the entire
month's credit by itself.

### Why the advertised prices are wrong: plan-tier store discounts

`?action=actor-pricing&actor=<id>` reads the actor's configured event prices. For
`memo23/instagram-followers-count-scraper`:

```
profile           = $0.0026 per profile   ($2.60 per 1,000)
apify-actor-start = $0.001  per run
```

List is **$2.60 / 1,000**. Run 4 was charged $0.238 for 100, i.e. $2.38 / 1,000, about 8.5% below
list. The account's `plan.tier` is `BRONZE`, and Starter includes a Bronze Apify Store discount, so
the 8.5% is that discount.

The store page's "from $1.30" is exactly half of list. Most likely the price at Apify's largest store
discount, which belongs to a far more expensive plan. That last step is inference from the
arithmetic, not stated by the API. What is established: list $2.60, Bronze pays $2.38, $1.30 is not
available on Starter.

Rule: read `?action=actor-pricing` and `?action=run-cost`. A store page's "from $X" is a best-case
price for someone else's plan.

### Billing cycle

**2026-09-17 to 2026-10-16.** The next $19 of credit arrives 17 October, and only if the plan has not
been cancelled. Everything spent before then is overage on the card, not prepaid credit.

### Budget state after run 4

| | |
|---|---|
| Spent this cycle | $19.44 |
| Included credit (STARTER) | $19.00 |
| Hard cap (`limits.maxMonthlyUsageUsd`) | $26.00 |
| Headroom | $6.56, **all overage billed to the card** |

The account is already $0.44 past the included credit. Further runs are not spending prepaid credit,
they are spending real additional money on top of the $19 subscription. Note `plan.maxMonthlyUsageUsd`
reads 19 while `limits.maxMonthlyUsageUsd` reads 26; the second is the enforced ceiling.

At $2.38 / 1,000, the $6.56 headroom buys about 2,750 profiles. Enriching the remaining 10,757 would
cost about $25.60 and exceed the cap.

`?action=usage` reports spend, cap and remaining. Check it before starting anything billable.

## Enrichment built and tested 2026-09-17

`memo23/instagram-followers-count-scraper` is wired into the app and the dashboard.

- New table `ig_profile_stats`, one row per account, unique on `ig_user_id`, all 21 fields mapped,
  indexed on `followers_count` and `posts_count`.
- `ig_scrape_runs` gained a `kind` column, `followers` or `profiles`. `import` and `import-all`
  route on it, so one set of buttons serves both.
- `Scraper::startEnrich($limit, $publicOnly)` picks only accounts with no stats yet, so repeated
  presses walk the queue without re-paying for anyone.
- Dashboard panel: count, skip-private toggle, start, import, stats.
- `?action=migrate` creates the tables and adds the column idempotently.
- `config.example.php` was recreated, since it had been renamed rather than copied to `config.php`.
  `enrich_actor_id` defaults in code, so an existing `config.php` needs no edit.

**Test run: 100 profiles, run 4, Apify run `f4Y7vgkWZ0wlUaOsO`. 100 imported, 0 skipped, 0 errors,
about $0.13.**

First numbers from those 100:

| | |
|---|---|
| Mean followers | 414 |
| Max followers | 4,671 |
| Zero posts | 15% |
| Zero followers | 2% |
| Business accounts | 14% |
| Public email present | 5% |

15% with no posts at all is the clearest argument for enriching before following: those are empty or
abandoned accounts, and each one would otherwise consume a 3-to-5 minute cooldown slot for nothing.

Of the 100 enriched accounts, one (`ig_profile_stats.id = 57`, @annaoreilly08) has the biography
`17 ✝️  👻- annaoreilly08`. A bare number in an Instagram bio usually means age, so this is probably
a 17-year-old, but a bio cannot establish age and it could be a jersey number or a date. Worth a
decision because Verified Botanicals is a 21+ brand, not worth a filter that pretends bios are
reliable. The defensible filter is `posts_count = 0`, which removes 15% of the queue as empty
accounts and happens to catch this one.

### Run 5, 2026-09-17: 2,400 profiles enriched

Apify run `p8PTS9guXyu9pl9mN`. 2,400 requested, 2,400 returned, 2,400 imported, 0 skipped, 0 errors.

| | |
|---|---|
| Cost | **$5.528** |
| Real rate | $2.303 / 1,000 (run 4 measured $2.38, so the rate is stable) |
| Enriched now | **2,500 of 10,857** |
| Not enriched | 8,357 |

Distribution across the 2,500:

| | |
|---|---|
| Median followers | **359** |
| Mean followers | 3,119 |
| Max followers | 1,488,994 |
| Zero posts | 275 (11%) |
| Zero followers | 20 |
| Business accounts | 420 (17%) |
| Public email present | 263 (11%) |

The mean is 8.7x the median because one account has 1.49M followers. Use the median. An earlier
version of `?action=enrich-stats` labelled the mean as `followers_median`, which was wrong; it now
returns `followers_mean` and a real `followers_p50`.

### Budget after run 5

| | |
|---|---|
| Spent this cycle | **$24.97** |
| Included credit | $19.00 |
| Overage on the card | **$5.97** |
| Hard cap | $26.00 |
| Headroom left | **$1.03** |

**Do not start another billable run this cycle.** $1.03 buys about 440 profiles and any overshoot
hits the cap mid-run. The cycle resets 2026-10-17 with a fresh $19, which at $2.30 / 1,000 buys
about 8,260 profiles, enough to finish the remaining 8,357 almost exactly.

**Status: 2,500 of 10,857 enriched. The remaining 8,357 wait for the next cycle.** Decision deferred by Ildar 2026-09-17. When it
happens, use `memo23/instagram-followers-count-scraper` with `{"usernames": [...]}` and land the 21
fields in new `ig_accounts` columns (or a separate `ig_profile_stats` table keyed on `ig_user_id`).

Side fact from the probe: @american_kratom_assoc actually has **12,468 followers**, and run 3
enumerated 10,857 of them, which is 87%.

## The paid-vs-prepaid question, settled 2026-09-17

**Apify has no prepaid credit outside a plan.** Their help doc: "If you used the prepaid usage on the
free plan you can't buy additional credits. You would need to subscribe to a subscription plan." The
$5/month on FREE is the only credit that arrives without a subscription, and `maxMonthlyUsageUsd`
caps the account at $5 regardless.

**Starter meets the actual requirement** (API calls from this app, 12,400 followers, nothing done by
hand in Apify's web UI). $19/month arrives as $19 of usage credit rather than a fee on top, so it is
prepayment wearing a subscription's clothes. The job costs $14.26 with the current actor or $7.44
with `scraping_solutions`. Unproven: that a paid plan lifts the first-page gate. Test with
`resultsLimit` 100, about $0.12, and read `?action=log` before spending the rest.

Anything in these notes suggesting a run be started from Apify's web interface was a free-tier
workaround only. On a paid plan it is unnecessary and Ildar does not want it.

### Bright Data, the genuine pay-as-you-go alternative

https://brightdata.com/products/web-scraper/instagram/followers

- "$1.5/1K record", pay as you go, no monthly subscription
- 5,000 records per month free, no card
- API to "Trigger runs with parameters, schedule recurring jobs" and deliver results
- "Only pay for what's successfully delivered"

12,400 records is about $18.60, or about $11.10 if the free 5,000 apply first. Unverified: their
account requirements. Bright Data commonly asks for business verification on social media scrapers.

Trade-off: Apify Starter is cheaper and needs no new code, since `src/Apify.php` already speaks that
API. Bright Data needs a new client but never charges a subscription.

## Alternative actors (researched 2026-09-17)

The blocker was never Apify's billing model. Pay-per-result actors already work on the FREE plan:
run 1 spent about $0.06 of the $5 monthly credit and returned data. What stopped us was a check
inside `apify/instagram-followers-following-scraper` itself, which truncates free accounts to the
first page. Other actors do not necessarily have that check.

Apify Store actors are priced one of three ways, and only the second needs a plan commitment:

| Model | What you pay | Works on FREE plan |
|---|---|---|
| Free actor | platform compute units only | yes |
| Rental | a fixed monthly fee per actor | no, needs a paid plan |
| Pay per result / per event | per row returned | yes, out of the $5 monthly credit |

### Candidates

| Actor | Price | Free-tier wording |
|---|---|---|
| `apify/instagram-followers-following-scraper` (current) | $1.15 / 1,000 | "Users without paid subscriptions can only access the first page" - 48 rows |
| `scraping_solutions/instagram-scraper-followers-following-no-cookies` | from $0.60 / 1,000, 14K users | "Free users are limited by the available run budget." and "free users running this Actor through the API are limited to a maximum of 1,000 results per run" - the API cap does not apply from the web interface |
| `memo23/instagram-following-scraper` | from $0.99 / 1,000 | "No hidden per-account cap on paid runs" - the words "on paid runs" suggest free runs may be capped, unverified |

### The scraping_solutions actor looks like the way through

Inputs: `Account` (array), `resultsLimit` (25 to 500,000, default 200), `dataToScrape`
("Followers" or "Followings"), and **`continuationToken`**, which resumes a previous extraction.
That token matters twice: it works around the 1,000-per-run API cap on free accounts, and it lets a
large scrape be split across billing months.

Cost at $0.60 / 1,000, against a FREE plan whose `maxMonthlyUsageUsd` is 5:

| Followers | Cost | Fits in one month of free credit |
|---|---|---|
| 8,000 | $4.80 | yes |
| 12,400 (all of them) | $7.44 | no, needs two months or a paid plan |

8,000 targets is 80 days of runway at 100 follows per day, so the full list is not actually required
to start.

Note the price is quoted as "from $0.60", so followers may cost more than the headline. Confirm on a
small run before assuming the table above.

### Next step, and why it is shaped this way

Do not trust the input key names above. The schema page renders titles, not keys, so `Account` may
really be `accounts` or `usernames`. The reliable method is the one that worked for the first actor:
run it once, then read the run's `INPUT` record and its dataset to get the exact keys and output
fields, and only then write code against them.

- [ ] Run `scraping_solutions/...` once from the Apify **web interface** (not the API, to dodge the
      1,000 cap) with a small `resultsLimit`
- [ ] Read that run's `INPUT` and dataset to capture exact input keys and output field names
- [ ] Map its output fields onto `ig_accounts` in `Scraper::importBatch()`
- [ ] Add an "import an existing dataset by id" path, so a run started in the web UI can still be
      imported by this app
- [ ] Add `continuationToken` support for paging across runs

## Phase 5 - hand off to instagram-follower

- [ ] Update the instagram-follower script spec to read the queue from MySQL
- [ ] Decide whether private accounts belong in the queue

## Open questions

- The Apify token was not findable on disk. A literal scan of the whole vault for `apify_api_` found
  nothing, and `AppData\Roaming\Claude` is a protected path. `Human Design/setup-dashboard.gs`
  documents that it lives in the Apps Script Script Properties as `APIFY_TOKEN`. It is now in
  `config.php`, which is gitignored.
- 48 targets is not enough to feed a 100-follows-per-day script. The instagram-follower project is
  effectively blocked on this one.
- Nothing decides yet how targets are prioritized inside the queue. Right now the Playwright script
  would take them in insert order, which is the order Apify returned them.
