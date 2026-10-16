<?php
declare(strict_types=1);

/**
 * One-profile-at-a-time review of the follow queue, for review.php on a phone.
 *
 * Two decisions:
 *
 *   valid  The account stays follow_status = 'pending', so the follow script
 *          still follows it. Only the review columns are stamped, which takes it
 *          out of the review screen.
 *   skip   The account becomes follow_status = 'skipped' with the note
 *          REVIEW_SKIP_NOTE, exactly what the accounts browser's "Do not follow"
 *          writes, so the follow script never picks it and "Revert to pending"
 *          undoes it.
 *
 * Four columns on ig_accounts, added by ensureSchema():
 *
 *   reviewed_at      America/New_York wall time of the decision
 *   reviewed_by      the signed-in username (an email under the parent login)
 *   review_decision  'valid' or 'skip'
 *   review_token     random, returned to the page so it can undo that one
 *                    decision and nothing else
 *
 * The review queue is pending rows with reviewed_at NULL, lowest id first,
 * which is the order the follow script works in, so review stays ahead of it.
 *
 * Every write is a single guarded UPDATE. A decision applies only to a row
 * that is still pending and unreviewed, so with two reviewers on the same card
 * the first one wins, and a row the follow script reached first is left alone.
 *
 * SQL here avoids MySQL-only syntax so tests/review-test.php can run it against
 * SQLite, except ensureSchema(), which reads information_schema.
 */
final class Review
{
    public const REVIEW_SKIP_NOTE = 'skipped in review';

    private static bool $schemaChecked = false;

    /** Add the review columns if missing. Idempotent; never drops anything. */
    public static function ensureSchema(): array
    {
        if (self::$schemaChecked) {
            return ['checked' => true];
        }

        $columns = [
            'reviewed_at'     => "DATETIME NULL COMMENT 'America/New_York wall time, written by review.php'",
            'reviewed_by'     => 'VARCHAR(255) NULL',
            'review_decision' => "ENUM('valid','skip') NULL",
            'review_token'    => "CHAR(32) NULL COMMENT 'lets review.php undo exactly one decision'",
        ];
        $existing = array_map('strval', array_column(Db::all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'ig_accounts'"
        ), 'COLUMN_NAME'));

        $added = [];
        foreach ($columns as $name => $definition) {
            if (!in_array($name, $existing, true)) {
                // Nullable with no default, so MySQL 8 adds it in place without
                // rebuilding the table the follow script is reading.
                try {
                    Db::pdo()->exec('ALTER TABLE `ig_accounts` ADD COLUMN `' . $name . '` ' . $definition);
                    $added[] = $name;
                } catch (PDOException $e) {
                    // Two first requests can both see the column missing. The
                    // one that loses the race gets "duplicate column", which
                    // means the column now exists: exactly what was wanted.
                    if (!self::isDuplicateColumn($e)) {
                        throw $e;
                    }
                }
            }
        }

        self::$schemaChecked = true;
        return ['added' => $added];
    }

    /** MySQL error 1060, ER_DUP_FIELDNAME. */
    public static function isDuplicateColumn(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1060;
    }

    /** The next account to review and how many are left, or null when done. */
    public static function next(): array
    {
        $remaining = (int) Db::scalar(
            "SELECT COUNT(*) FROM ig_accounts
              WHERE follow_status = 'pending' AND reviewed_at IS NULL"
        );

        $account = Db::one(
            "SELECT a.id, a.username, a.full_name, a.is_verified, a.is_private,
                    s.user_full_name, s.followers_count, s.follows_count, s.posts_count,
                    s.is_business, s.category, s.biography, s.external_url
               FROM ig_accounts a
               LEFT JOIN ig_profile_stats s ON s.ig_user_id = a.ig_user_id
              WHERE a.follow_status = 'pending' AND a.reviewed_at IS NULL
              ORDER BY a.id ASC
              LIMIT 1"
        );

        return ['remaining' => $remaining, 'account' => $account];
    }

    /**
     * Record one decision. Returns changed = false, with the row's current
     * state, when someone or the follow script got to it first.
     */
    public static function decide(int $id, string $decision, string $reviewer): array
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('id must be a positive account id');
        }
        if (!in_array($decision, ['valid', 'skip'], true)) {
            throw new InvalidArgumentException('decision must be valid or skip');
        }

        $token = bin2hex(random_bytes(16));
        $params = [
            'id'       => $id,
            'now'      => Config::now(),
            'reviewer' => $reviewer,
            'decision' => $decision,
            'token'    => $token,
        ];
        $set = 'reviewed_at = :now, reviewed_by = :reviewer, review_decision = :decision, review_token = :token';
        if ($decision === 'skip') {
            $set .= ", follow_status = 'skipped', follow_note = :note, follow_attempted_at = :now2";
            $params['note'] = self::REVIEW_SKIP_NOTE;
            $params['now2'] = $params['now'];
        }

        $changed = Db::run(
            'UPDATE ig_accounts SET ' . $set . "
              WHERE id = :id AND follow_status = 'pending' AND reviewed_at IS NULL",
            $params
        );

        if ($changed === 1) {
            return ['changed' => true, 'id' => $id, 'decision' => $decision, 'token' => $token];
        }

        $row = Db::one(
            'SELECT follow_status, review_decision, reviewed_by FROM ig_accounts WHERE id = :id',
            ['id' => $id]
        );
        return [
            'changed' => false,
            'id'      => $id,
            'current' => $row,
        ];
    }

    /**
     * Undo one decision made on this page. Applies only when the token matches,
     * the same reviewer asks, and the row is still in the state that decision
     * left it in, so a later bulk action or a follow is never reversed.
     */
    public static function undo(int $id, string $token, string $reviewer): array
    {
        if ($id <= 0 || preg_match('/^[0-9a-f]{32}$/', $token) !== 1) {
            throw new InvalidArgumentException('id and a valid token are required');
        }

        $params = ['id' => $id, 'token' => $token, 'reviewer' => $reviewer];
        $clear = 'reviewed_at = NULL, reviewed_by = NULL, review_decision = NULL, review_token = NULL';
        $guard = ' WHERE id = :id AND review_token = :token AND reviewed_by = :reviewer';

        $changed = Db::run(
            'UPDATE ig_accounts SET ' . $clear . $guard .
            " AND review_decision = 'valid' AND follow_status = 'pending'",
            $params
        );
        if ($changed === 0) {
            $changed = Db::run(
                'UPDATE ig_accounts SET ' . $clear .
                ", follow_status = 'pending', follow_note = NULL, follow_attempted_at = NULL" . $guard .
                " AND review_decision = 'skip' AND follow_status = 'skipped' AND follow_note = :note",
                $params + ['note' => self::REVIEW_SKIP_NOTE]
            );
        }

        return ['changed' => $changed === 1, 'id' => $id];
    }
}
