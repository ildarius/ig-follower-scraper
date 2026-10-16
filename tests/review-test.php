<?php
/**
 * Tests for Review (review.php's queue, decide and undo) and for the way the
 * accounts browser's bulk actions clear a review decision.
 *
 *     php tests/review-test.php
 *
 * Needs the pdo_sqlite extension and nothing else: no config.php, no MySQL.
 * Db's connection is replaced with an in-memory SQLite database through
 * reflection. Review::ensureSchema() reads MySQL's information_schema, so it is
 * not exercised here; the columns are created directly. Exit status is 0 when
 * every case passes.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Review.php';
require dirname(__DIR__) . '/src/AccountsBrowser.php';

$fails = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $fails;
    $ok = $actual === $expected;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  pass  ' : '  FAIL  ') . $name
        . ($ok ? '' : '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)) . PHP_EOL;
}

Config::load(['timezone' => 'America/New_York']);

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$prop = new ReflectionProperty(Db::class, 'pdo');
$prop->setValue(null, $pdo);

$pdo->exec("CREATE TABLE ig_accounts (
    id INTEGER PRIMARY KEY,
    ig_user_id TEXT UNIQUE,
    username TEXT,
    full_name TEXT NULL,
    is_verified INTEGER NOT NULL DEFAULT 0,
    is_private INTEGER NOT NULL DEFAULT 0,
    follow_status TEXT NOT NULL DEFAULT 'pending',
    follow_attempted_at TEXT NULL,
    follow_note TEXT NULL,
    reviewed_at TEXT NULL,
    reviewed_by TEXT NULL,
    review_decision TEXT NULL,
    review_token TEXT NULL
)");
$pdo->exec("CREATE TABLE ig_profile_stats (
    id INTEGER PRIMARY KEY,
    ig_user_id TEXT UNIQUE,
    user_full_name TEXT NULL,
    followers_count INTEGER NULL,
    follows_count INTEGER NULL,
    posts_count INTEGER NULL,
    is_business INTEGER NULL,
    category TEXT NULL,
    biography TEXT NULL,
    external_url TEXT NULL
)");
$ins = $pdo->prepare('INSERT INTO ig_accounts (id, ig_user_id, username, follow_status) VALUES (?, ?, ?, ?)');
foreach ([
    [1, '1001', 'followed_one', 'followed'],
    [2, '1002', 'pending_a',    'pending'],
    [3, '1003', 'pending_b',    'pending'],
    [4, '1004', 'pending_c',    'pending'],
    [5, '1005', 'skipped_one',  'skipped'],
] as $r) {
    $ins->execute($r);
}
$pdo->exec("INSERT INTO ig_profile_stats (ig_user_id, user_full_name, followers_count, biography)
            VALUES ('1002', 'Pending A', 1234, 'bio of a')");

$row = static fn (int $id): array => Db::one('SELECT * FROM ig_accounts WHERE id = :id', ['id' => $id]);

echo "next\n";
$n = Review::next();
check('first pending, lowest id', (int) $n['account']['id'], 2);
check('remaining counts pending unreviewed', $n['remaining'], 3);
check('profile stats joined', (int) $n['account']['followers_count'], 1234);

echo "valid keeps the account pending\n";
$v = Review::decide(2, 'valid', 'alice');
check('valid changed', $v['changed'], true);
check('valid token is 32 hex', preg_match('/^[0-9a-f]{32}$/', $v['token']), 1);
check('still pending for the follow script', $row(2)['follow_status'], 'pending');
check('decision recorded', $row(2)['review_decision'], 'valid');
check('reviewer recorded', $row(2)['reviewed_by'], 'alice');
check('next skips the reviewed row', (int) Review::next()['account']['id'], 3);
check('remaining drops', Review::next()['remaining'], 2);

echo "skip uses the existing do-not-follow status\n";
$s = Review::decide(3, 'skip', 'alice');
check('skip changed', $s['changed'], true);
check('status skipped', $row(3)['follow_status'], 'skipped');
check('note set', $row(3)['follow_note'], Review::REVIEW_SKIP_NOTE);
check('attempted_at set', $row(3)['follow_attempted_at'] !== null, true);

echo "first decision wins\n";
$again = Review::decide(2, 'skip', 'bob');
check('second reviewer cannot skip a validated row', $again['changed'], false);
check('validated row still pending', $row(2)['follow_status'], 'pending');
check('conflict reports current decision', $again['current']['review_decision'], 'valid');
check('a followed row cannot be reviewed', Review::decide(1, 'valid', 'alice')['changed'], false);
check('followed row untouched', $row(1)['reviewed_at'], null);
check('an already skipped row cannot be validated', Review::decide(5, 'valid', 'alice')['changed'], false);

echo "undo\n";
check('wrong reviewer cannot undo', Review::undo(2, $v['token'], 'bob')['changed'], false);
check('wrong token cannot undo', Review::undo(2, str_repeat('a', 32), 'alice')['changed'], false);
check('undo valid', Review::undo(2, $v['token'], 'alice')['changed'], true);
check('undone valid is unreviewed', $row(2)['reviewed_at'], null);
check('undone valid token cleared', $row(2)['review_token'], null);
check('undone valid back first in queue', (int) Review::next()['account']['id'], 2);
check('token is single use', Review::undo(2, $v['token'], 'alice')['changed'], false);
check('undo skip', Review::undo(3, $s['token'], 'alice')['changed'], true);
check('undone skip is pending again', $row(3)['follow_status'], 'pending');
check('undone skip note cleared', $row(3)['follow_note'], null);
check('undone skip attempted_at cleared', $row(3)['follow_attempted_at'], null);

echo "undo refuses a row that changed since\n";
$s2 = Review::decide(3, 'skip', 'alice');
Db::run("UPDATE ig_accounts SET follow_note = 'operator changed it' WHERE id = 3");
check('skip undo refused after note changed', Review::undo(3, $s2['token'], 'alice')['changed'], false);
check('row left as the operator set it', $row(3)['follow_status'], 'skipped');
$v4 = Review::decide(4, 'valid', 'alice');
Db::run("UPDATE ig_accounts SET follow_status = 'followed' WHERE id = 4");
check('valid undo refused after the follow script followed it', Review::undo(4, $v4['token'], 'alice')['changed'], false);
check('followed row keeps its status', $row(4)['follow_status'], 'followed');

echo "bulk actions clear review decisions\n";
$v2 = Review::decide(2, 'valid', 'alice');
// Bulk runs MySQL's multi-table UPDATE ... LEFT JOIN, which SQLite does not
// parse, so the SET clause it builds is checked against the source instead.
$src = file_get_contents(dirname(__DIR__) . '/src/AccountsBrowser.php');
check('bulk clears all four review columns',
    str_contains($src, "a.reviewed_at = NULL, a.reviewed_by = NULL, a.review_decision = NULL, a.review_token = NULL"), true);
// The same effect, applied by hand: a bulk skip then revert must leave no
// usable token behind.
Db::run("UPDATE ig_accounts SET follow_status = 'skipped', follow_note = 'kava bar',
           reviewed_at = NULL, reviewed_by = NULL, review_decision = NULL, review_token = NULL WHERE id = 2");
Db::run("UPDATE ig_accounts SET follow_status = 'pending', follow_note = NULL,
           reviewed_at = NULL, reviewed_by = NULL, review_decision = NULL, review_token = NULL WHERE id = 2");
check('stale token after bulk skip and revert does nothing', Review::undo(2, $v2['token'], 'alice')['changed'], false);
check('reverted row back in the review queue', (int) Review::next()['account']['id'], 2);

echo "input validation\n";
foreach ([[0, 'valid'], [2, 'maybe'], [2, '']] as [$id, $decision]) {
    try { Review::decide($id, $decision, 'alice'); check("decide($id, '$decision') throws", 'no exception', 'exception'); }
    catch (InvalidArgumentException $e) { check("decide($id, '$decision') throws", true, true); }
}
try { Review::undo(2, 'not-a-token', 'alice'); check('bad token throws', 'no exception', 'exception'); }
catch (InvalidArgumentException $e) { check('bad token throws', true, true); }

echo "concurrent schema setup\n";
// Two first requests racing to add the same column: the loser's duplicate-column
// error (MySQL 1060) is treated as success, anything else still fails.
$dup = new PDOException("SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'reviewed_at'");
$dup->errorInfo = ['42S21', 1060, "Duplicate column name 'reviewed_at'"];
check('duplicate column is tolerated', Review::isDuplicateColumn($dup), true);
$other = new PDOException('SQLSTATE[42000]: Syntax error: 1064');
$other->errorInfo = ['42000', 1064, 'syntax'];
check('other errors are not', Review::isDuplicateColumn($other), false);
check('no errorInfo is not', Review::isDuplicateColumn(new PDOException('gone away')), false);

echo "\n" . ($fails === 0 ? 'All passed.' : $fails . ' failed.') . PHP_EOL;
exit($fails === 0 ? 0 : 1);
