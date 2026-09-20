# Moving this to a remote host

Written 2026-09-20. Covers the database export, what is already portable, what breaks, and the one
risk that should decide the architecture.

---

## 1. The database export

`?action=dump` writes a full logical backup using PHP, because `mysqldump` is not reachable from
every environment this app gets driven from.

Latest export, 2026-09-20 11:01 New York:

| File | Size |
|---|---|
| `data/dump-sunny_kratom-20260920-110145.sql` | 22.9 MB |
| `data/dump-sunny_kratom-20260920-110145.sql.gz` | 5.0 MB |

| Table | Rows |
|---|---|
| `ig_accounts` | 10,857 |
| `ig_relations` | 10,857 |
| `ig_raw_items` | 13,357 |
| `ig_profile_stats` | 2,500 |
| `ig_scrape_runs` | 5 |
| **Total** | **37,576** |

Verified: 5 `CREATE TABLE`, 191 batched `INSERT` statements at 200 rows each, `DROP TABLE IF EXISTS`
before each table, `LOCK`/`UNLOCK` pairs, `SET NAMES utf8mb4` in the header, `FOREIGN_KEY_CHECKS`
restored at the end, and a 4-byte emoji round-tripped intact.

Restore:

```bash
mysql -u <user> -p -e "CREATE DATABASE sunny_kratom DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c dump-sunny_kratom-20260920-110145.sql.gz | mysql -u <user> -p sunny_kratom
```

Options: `?action=dump&data=0` for schema only, `?action=dump&tables=ig_accounts,ig_profile_stats`
for a subset. `ig_raw_items` is 13 MB of the 23 MB and is only kept so a future actor's extra fields
can be recovered without re-paying. It can be skipped for a working migration.

**Dumps are now git-ignored**, along with `config.php`, verified with `git check-ignore`. A 23 MB
dump containing the whole dataset should not end up in the repository.

---

## 2. What is already portable

The PHP application audits clean. No changes needed for any of this:

| Concern | Status |
|---|---|
| File paths | All derived from `__DIR__` and `APP_ROOT`. No absolute paths anywhere in PHP |
| Database connection | Host, port, name, user and password all come from `config.php` |
| Timezone | `config.php`, read by `bootstrap.php` and `Config::timezone()`. Not the server's |
| Secrets | `config.php` sits at the project root, one level above the docroot `web/public/`. Not web-reachable |
| Data files | `data/` is also above the docroot. Dumps and `already-followed.json` are not web-reachable |
| Dependencies | None. No Composer, no CDN, no build step. PHP plus PDO plus curl |
| Charset | `utf8mb4` declared on every table and set on every connection |

---

## 3. What breaks, in order of severity

### 3.1 The localhost guard is the only access control

`web/public/api.php:24`

```php
if (!in_array($remote, ['127.0.0.1', '::1'], true)) { /* 403 */ }
```

On a remote host `REMOTE_ADDR` is the visitor's address, so this rejects everyone including the
operator. The obvious fix is to delete it. **Do not delete it without putting something in its
place.** Unauthenticated, this application can:

- spend real money: `?action=start` and `?action=enrich` launch billed Apify runs
- mass-modify the queue: `?action=accounts-bulk` updates thousands of rows in one request
- disclose the whole dataset: `?action=dump` writes a 23 MB export, `?action=accounts-search`
  paginates through every row
- expose the Apify token indirectly, since any caller can spend against it

Minimum acceptable replacement, in order of preference:

1. Keep it private. Bind to a VPN or Tailscale address and leave the IP guard, widened to that range.
2. HTTP Basic authentication over TLS, enforced at the web server, plus an IP allowlist.
3. A shared secret in a header or query string. Weakest of the three, and it leaks through logs and
   browser history.

Whichever is chosen, TLS is required. The Apify token and the session travel over this connection.

### 3.1b Roles: a single login is not enough

**Settled 2026-09-20:** only the accounts browser moves to the remote host. The follow script stays
on the PC. A remote employee in Indonesia will use the browser to mark accounts as skip.

Verified 2026-09-20: **no part of the PHP application can start the Node follow script.** A grep for
`exec`, `shell_exec`, `proc_open`, `passthru`, `system` and `popen` across `src/`, `web/` and the
root PHP files returns only `curl_exec` (HTTP to Apify) and `PDO::exec` (SQL). There is no
process-execution primitive. `instagram-follower.js` is started only from PowerShell.

That is not the exposure to worry about. A login grants the whole of `api.php`, and these actions are
not safe in an operator's hands:

| Risk | Actions |
|---|---|
| **Spends money** | `start` (a full scrape cost $19.00), `enrich` ($2.30 per 1,000), **`probe` (runs any Apify actor with any input, unbounded by design)** |
| **Mass writes** | `accounts-bulk` (can hit all 10,660 pending rows in one request), `import-followed&apply=1`, `reset-cursor`, `migrate` |
| **Discloses everything** | `dump` (23 MB of the full dataset), `raw`, `recent-attempts` |
| **Exposes billing** | `usage`, `account`, `run-cost`, `actor-pricing` |

`index.php` also carries **Start run** and **Start enrichment** buttons behind the same login.

#### Required: an action allowlist by role

Two roles. The check belongs in `api.php` immediately after authentication, before the `switch`, so a
new action is denied by default rather than exposed by omission.

| Role | Pages | Actions |
|---|---|---|
| `operator` (the employee) | `accounts.php` only | `accounts-search`, `accounts-facets`, `accounts-bulk`, `queue-stats` |
| `admin` (Ildar) | all | all |

Additional constraints on the operator role:

1. `accounts-bulk` must accept only `operation` of `skip` or `revert`. Reject anything else.
2. **Cap a single bulk operation for an operator**, suggested at 500 rows. Without a cap, one
   select-all with no filter marks the entire queue. That is recoverable via revert, but only if
   somebody notices.
3. Requesting `index.php` as an operator returns 403, not a redirect that leaks the page's existence.

#### Recommended: an audit log

Once a second person can modify 10,000 rows, "who changed this and when" stops being answerable.

```sql
CREATE TABLE IF NOT EXISTS `ig_audit_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at`         DATETIME     NOT NULL COMMENT 'America/New_York, written by PHP',
  `actor`      VARCHAR(64)  NOT NULL COMMENT 'login name',
  `action`     VARCHAR(64)  NOT NULL,
  `operation`  VARCHAR(32)      NULL,
  `note`       VARCHAR(255)     NULL,
  `filters`    TEXT             NULL COMMENT 'JSON of the filter used, when select_all',
  `row_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `ip`         VARCHAR(45)      NULL,
  PRIMARY KEY (`id`),
  KEY `idx_at` (`at`),
  KEY `idx_actor` (`actor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Write one row per `accounts-bulk` call. It costs nothing and makes a mistaken mass-skip a two-minute
investigation instead of a guess.

### 3.2 The follow script does not belong on a server

`instagram-follower.js:51` hardcodes `C:\chrome-automation-profile`, and lines 561 to 563 list
Windows Chrome paths with Linux and macOS fallbacks. Those are trivial to parameterise. The real
problems are not path constants:

- It attaches over CDP to a **real Chrome carrying an aged, signed-in Instagram session**. That
  profile is the reason Instagram tolerates it. A fresh profile on a server has no history.
- It runs **headed**. A Linux server needs xvfb or similar, and headless mode is detected more
  aggressively by Meta.
- Section 4 below is the reason this matters more than either of the above.

### 3.2b The Node script needs hardening once the database is remote

The script was written against `127.0.0.1`, where a connection never drops. Pointing it at a host
across the internet changes that assumption, and a run lasts 6 to 8 hours.

`instagram-follower.js:147`

```js
pool = await mysql.createPool({ ...DB, waitForConnections: true, connectionLimit: 4 });
```

Three problems on a remote database:

1. **No reconnect.** A transient drop, a NAT timeout or a MySQL `wait_timeout` throws from
   `nextTarget()` or `recordAttempt()`. That propagates to the main loop's catch, which sets
   `stopReason` and ends the run. One network blip kills a six-hour session.
2. **No TLS.** `mysql2` connects in the clear by default. Credentials and the whole queue would cross
   the internet unencrypted. Either set the `ssl` option or tunnel the connection.
3. **A lost write is worse than a lost read.** If `recordAttempt()` fails after the Follow click
   succeeded, Instagram has the follow but the database still says `pending`, so the account gets
   followed twice on the next run.

Recommended changes, in priority order:

- Wrap `nextTarget()`, `recordAttempt()` and `queueCounts()` in a retry helper: 3 attempts with
  backoff, retrying only on connection-level errors (`PROTOCOL_CONNECTION_LOST`, `ECONNRESET`,
  `ETIMEDOUT`, `EPIPE`), never on a SQL syntax or constraint error.
- Add `enableKeepAlive: true` and `keepAliveInitialDelay: 10000` to the pool options. The script
  queries roughly every four minutes, which is usually enough to stay warm, but keepalive removes the
  dependence on that.
- Make `recordAttempt()` specifically durable, since it is the write that must not be lost. If all
  retries fail, append the intended update to a local file so it can be replayed, and log loudly.
- Preferred deployment: an **SSH tunnel** from the PC, with the script still pointing at
  `127.0.0.1:3306`. That gives encryption and authentication without exposing MySQL to the internet,
  and it needs no code change beyond the retry work.

### 3.3 Smaller items

| Item | Detail |
|---|---|
| `set_time_limit(0)` | Used by `import-all`, `probe` and `dump`. Disabled on some shared hosts. The import endpoints already work in time-budgeted slices, so they degrade rather than fail |
| MySQL version | `ig_raw_items.payload` is `JSON`, needing MySQL 5.7.8+ or MariaDB 10.2+. MariaDB aliases JSON to LONGTEXT, which is fine |
| `ON DUPLICATE KEY UPDATE ... VALUES()` | Used in `Scraper.php`. Deprecated in MySQL 8.0.20 and still functional. It will need the `AS new` alias form on a much newer MySQL |
| Outbound HTTPS | The host must reach `api.apify.com`. Some shared hosts block outbound connections |
| Writable directories | `data/` for dumps, `run-screenshots/` for failures. Both need write permission for the web server user |
| Apache config | The docroot must be `web/public/`, not the project root. Getting this wrong exposes `config.php` and the whole `data/` directory |

---

## 4. The risk that should decide the architecture

**Instagram evaluates where a session connects from.** The account has been operating from a
residential Quebec IP with an aged Chrome profile. Moving that session to a datacenter IP is one of
the most common triggers for a checkpoint or a ban, and the account is already performing automated
following, which raises the baseline scrutiny. Datacenter ranges are widely and cheaply identifiable.

The consequence is asymmetric. The web application being unavailable for an evening costs nothing.
Losing @verifiedbotanicals costs the follower base, the queue progress, and the account itself.

### Confirmed split, 2026-09-20

| Component | Where | Why |
|---|---|---|
| MySQL | Remote host | Just data. Nothing about it is IP-sensitive |
| PHP app and accounts browser | Remote host | Reachable from anywhere, which is presumably the point of moving |
| `instagram-follower.js` | **Stays on the PC** | Keeps the residential IP and the aged Chrome profile |

This is the agreed architecture. The employee gets the accounts browser only. The follow script keeps
reading its queue from MySQL, which now lives on the remote host, so the two halves stay in sync
without either one moving.

The Node script already reads its database settings from `.env`, so pointing it at the remote MySQL
is a configuration change rather than a code change:

```
DB_HOST=your-host.example.com
DB_PORT=3306
DB_USER=ig_follower
DB_PASS=<strong password>
DB_NAME=sunny_kratom
```

For that to work:

1. MySQL must accept remote connections and the firewall must allow the PC's address. Do not open
   3306 to the internet. Use an SSH tunnel or a VPN.
2. Create a dedicated user, not `root`, with rights only on `sunny_kratom`.
3. Enable TLS on the MySQL connection, or tunnel it.

This gets remote access to the queue and the browser while the part that touches Instagram keeps the
identity Instagram already trusts.

If the script genuinely has to run on the server later, do it deliberately: a residential proxy in
the same region, the Chrome profile copied over, a ramp starting well below 100 follows a day, and an
acceptance that the account may still be checkpointed.

---

## 5. Migration steps

1. Provision PHP 8 with PDO MySQL and curl, plus MySQL 5.7.8+ or MariaDB 10.2+.
2. Create the database and a dedicated user with rights on it alone.
3. Restore the dump, per section 1.
4. Copy the project, excluding `config.php`, `data/*.sql*`, `run-screenshots/` and `.git` if
   preferred.
5. Create `config.php` from `config.example.php` with the new database credentials and the Apify
   token. Set permissions to 600.
6. Point the virtual host's docroot at `web/public/`. Confirm that requesting `/config.php` and
   `/../config.php` both return 404.
7. Put authentication in front of it, per section 3.1, and enable TLS.
7b. Implement the role allowlist and the bulk cap from section 3.1b, and create `ig_audit_log`.
    Verify as an operator that `?action=start`, `?action=probe` and `?action=dump` all return 403.
8. Verify: `?action=stats` returns 10,857 accounts, `?action=queue-stats&min_followers=50` returns
   the expected eligible count, and `?action=usage` reaches Apify.
9. Point the PC's `instagram-follower/.env` at the remote database and run
   `node instagram-follower.js --dry-run --follows 3`.
10. Check that `follow_status` writes land in the remote database before running for real.

## 6. Post-migration check

Row counts must match exactly. Run on both sides and compare:

```sql
SELECT 'ig_accounts' AS t, COUNT(*) AS n FROM ig_accounts
UNION ALL SELECT 'ig_relations', COUNT(*) FROM ig_relations
UNION ALL SELECT 'ig_raw_items', COUNT(*) FROM ig_raw_items
UNION ALL SELECT 'ig_profile_stats', COUNT(*) FROM ig_profile_stats
UNION ALL SELECT 'ig_scrape_runs', COUNT(*) FROM ig_scrape_runs;
```

Expected at the time of export: 10,857 / 10,857 / 13,357 / 2,500 / 5.

Also confirm the emoji survived, which proves the charset held end to end:

```sql
SELECT username, full_name FROM ig_accounts WHERE full_name LIKE '%🩵%' LIMIT 1;
```
