<?php
declare(strict_types=1);

/**
 * The accounts our own Instagram account follows, and the posts the follow
 * script has liked. Added 2026-10-15 for the profile-likes action of
 * instagram-follower/instagram-follower.js, which picks a followed account at
 * random, opens it and likes one of its recent posts.
 *
 * Two tables:
 *
 *   ig_own_following  One row per account we follow. Filled from a
 *                     following-list scrape of our own account (the import, or
 *                     rebuildFromRun() over a run already imported), and by the
 *                     follow script when it follows someone or finds that a
 *                     pending request was accepted. The script writes
 *                     last_visited_at and last_liked_at.
 *   ig_post_likes     One row per post the script liked, keyed by the post code
 *                     from /p/<code>/, so a post is never liked twice even if
 *                     the page's Like / Unlike state were misread.
 *
 * And one column, ig_accounts.request_checked_at: when the script last opened a
 * profile whose follow request is still pending.
 *
 * Writes use SELECT then UPDATE or INSERT rather than MySQL's ON DUPLICATE KEY
 * UPDATE, so the same code runs against the SQLite database in the tests.
 */
final class OwnFollowing
{
    /** Create the tables and column if missing. Idempotent; never drops anything. */
    public static function ensureSchema(): array
    {
        $pdo = Db::pdo();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ig_own_following` (
              `ig_user_id`      VARCHAR(32)  NOT NULL,
              `username`        VARCHAR(255) NOT NULL,
              `full_name`       VARCHAR(255)     NULL,
              `is_private`      TINYINT(1)   NOT NULL DEFAULT 0,
              `source`          VARCHAR(24)  NOT NULL COMMENT 'following_list, follow_script or request_accepted',
              `first_seen_at`   DATETIME     NOT NULL COMMENT 'America/New_York wall time, written by the app',
              `last_seen_at`    DATETIME     NOT NULL,
              `last_visited_at` DATETIME         NULL COMMENT 'written by the follow script',
              `last_liked_at`   DATETIME         NULL COMMENT 'written by the follow script',
              `visit_note`      VARCHAR(255)     NULL COMMENT 'why the last visit liked nothing, if it did not',
              PRIMARY KEY (`ig_user_id`),
              KEY `idx_own_last_visited` (`last_visited_at`),
              KEY `idx_own_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `ig_post_likes` (
              `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `post_code`      VARCHAR(64)     NOT NULL COMMENT 'the <code> in /p/<code>/, for posts and reels alike',
              `owner_username` VARCHAR(255)        NULL,
              `source`         VARCHAR(16)     NOT NULL COMMENT 'profile or feed',
              `liked_at`       DATETIME        NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uniq_post_code` (`post_code`),
              KEY `idx_post_likes_liked_at` (`liked_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // ADD COLUMN IF NOT EXISTS is not portable, so check first.
        $hasColumn = (int) Db::scalar(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ig_accounts'
                AND COLUMN_NAME = 'request_checked_at'"
        );
        if ($hasColumn === 0) {
            $pdo->exec(
                "ALTER TABLE `ig_accounts`
                   ADD COLUMN `request_checked_at` DATETIME NULL
                   COMMENT 'when the follow script last found this request still pending'
                   AFTER `follow_note`"
            );
        }

        return [
            'own_following_rows' => (int) Db::scalar('SELECT COUNT(*) FROM ig_own_following'),
            'post_likes_rows'    => (int) Db::scalar('SELECT COUNT(*) FROM ig_post_likes'),
            'request_checked_at' => $hasColumn === 0 ? 'added' : 'already present',
        ];
    }

    /**
     * Add or refresh one followed account from an item of the
     * apify/instagram-followers-following-scraper actor (fields userId,
     * username, fullName, isPrivate).
     *
     * A new row gets $source and first_seen_at. An existing row gets the current
     * username, name and privacy and a new last_seen_at; its source, first_seen_at
     * and the script's visit and like history are kept.
     *
     * @return string 'inserted', 'updated' or 'skipped' (no user id or username)
     */
    public static function upsertFromItem(array $item, string $source, string $now): string
    {
        $userId   = isset($item['userId']) ? trim((string) $item['userId']) : '';
        $username = isset($item['username']) ? trim((string) $item['username']) : '';
        if ($userId === '' || $username === '') {
            return 'skipped';
        }
        $fullName  = isset($item['fullName']) && $item['fullName'] !== '' ? (string) $item['fullName'] : null;
        $isPrivate = !empty($item['isPrivate']) ? 1 : 0;

        $exists = Db::scalar('SELECT COUNT(*) FROM ig_own_following WHERE ig_user_id = :id', ['id' => $userId]);
        if ((int) $exists > 0) {
            Db::run(
                'UPDATE ig_own_following
                    SET username = :username, full_name = :full_name, is_private = :is_private,
                        last_seen_at = :last_seen_at
                  WHERE ig_user_id = :ig_user_id',
                [
                    'username'     => $username,
                    'full_name'    => $fullName,
                    'is_private'   => $isPrivate,
                    'last_seen_at' => $now,
                    'ig_user_id'   => $userId,
                ]
            );
            return 'updated';
        }

        Db::run(
            'INSERT INTO ig_own_following
                (ig_user_id, username, full_name, is_private, source, first_seen_at, last_seen_at)
             VALUES
                (:ig_user_id, :username, :full_name, :is_private, :source, :first_seen_at, :last_seen_at)',
            [
                'ig_user_id'    => $userId,
                'username'      => $username,
                'full_name'     => $fullName,
                'is_private'    => $isPrivate,
                'source'        => $source,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]
        );
        return 'inserted';
    }

    /**
     * Rebuild ig_own_following from a following-list run that is already
     * imported, using the payloads kept in ig_raw_items. Costs nothing: no Apify
     * call. Used once to fill the table from run 19, and safe to repeat.
     *
     * @return array{run:int, items:int, inserted:int, updated:int, skipped:int}
     */
    public static function rebuildFromRun(int $runRowId, Scraper $scraper, string $now): array
    {
        $row = $scraper->getRunRow($runRowId);
        if (!$scraper->isOwnFollowingRun($row)) {
            throw new InvalidArgumentException(sprintf(
                'Run %d is @%s %s, not a following list of our own account.',
                $runRowId, (string) $row['source_username'], (string) $row['data_to_scrape']
            ));
        }

        $counts = ['run' => $runRowId, 'items' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];
        foreach (Db::all('SELECT payload FROM ig_raw_items WHERE run_id = :run ORDER BY id', ['run' => $runRowId]) as $raw) {
            $counts['items']++;
            $item = json_decode((string) $raw['payload'], true);
            if (!is_array($item) || isset($item['error'])) {
                $counts['skipped']++;
                continue;
            }
            $counts[self::upsertFromItem($item, 'following_list', $now)]++;
        }
        return $counts;
    }

    /** The most recent following-list run of our own account, or null. */
    public static function latestOwnFollowingRunId(): ?int
    {
        $own = strtolower(ltrim((string) Config::get('own_account', 'verifiedbotanicals'), '@'));
        $id  = Db::scalar(
            "SELECT id FROM ig_scrape_runs
              WHERE data_to_scrape = 'following' AND LOWER(source_username) = :own
              ORDER BY id DESC LIMIT 1",
            ['own' => $own]
        );
        return $id === false || $id === null ? null : (int) $id;
    }
}
