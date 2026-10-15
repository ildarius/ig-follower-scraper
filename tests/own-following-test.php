<?php
/**
 * Tests for marking accounts we already follow, from a following-list scrape of
 * our own account (Scraper::isOwnFollowingRun and Scraper::markAlreadyFollowing),
 * and for the followed-accounts table the profile-likes action picks from
 * (OwnFollowing::upsertFromItem, rebuildFromRun, latestOwnFollowingRunId).
 *
 *     php tests/own-following-test.php
 *
 * Needs the pdo_sqlite extension and nothing else: no config.php, no MySQL, no
 * Apify. Db's connection is replaced with an in-memory SQLite database through
 * reflection, and the Apify client is constructed with a dummy token and never
 * called. Exit status is 0 when every case passes.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/Apify.php';
require dirname(__DIR__) . '/src/Scraper.php';
require dirname(__DIR__) . '/src/OwnFollowing.php';

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

Config::load(['apify_token' => 'test-token-not-used', 'timezone' => 'America/New_York']);

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
    follow_status TEXT NOT NULL DEFAULT 'pending',
    follow_attempted_at TEXT NULL,
    follow_note TEXT NULL
)");
$rows = [
    [1, '1001', 'pending_one',  'pending',   null,                  null],
    [2, '1002', 'followed_one', 'followed',  '2026-10-14 13:31:43', null],
    [3, '1003', 'skipped_one',  'skipped',   '2026-10-14 05:09:14', 'operator note'],
    [4, '1004', 'requested_one','requested', '2026-10-14 13:35:01', null],
    [5, '1005', 'pending_two',  'pending',   null,                  null],
];
$ins = $pdo->prepare('INSERT INTO ig_accounts VALUES (?, ?, ?, ?, ?, ?)');
foreach ($rows as $r) {
    $ins->execute($r);
}

$scraper = new Scraper();

echo '== which runs are our own following list' . PHP_EOL;
check('following of verifiedbotanicals', $scraper->isOwnFollowingRun(['data_to_scrape' => 'following', 'source_username' => 'verifiedbotanicals']), true);
check('handle with @ and capitals', $scraper->isOwnFollowingRun(['data_to_scrape' => 'following', 'source_username' => '@VerifiedBotanicals']), true);
check('followers of verifiedbotanicals', $scraper->isOwnFollowingRun(['data_to_scrape' => 'followers', 'source_username' => 'verifiedbotanicals']), false);
check('following of another account keeps old behaviour', $scraper->isOwnFollowingRun(['data_to_scrape' => 'following', 'source_username' => 'american_kratom_assoc']), false);
check('missing fields', $scraper->isOwnFollowingRun([]), false);

echo '== only pending rows are marked' . PHP_EOL;
$now = '2026-10-17 10:00:00';
check('pending row changes', $scraper->markAlreadyFollowing('1001', 'verifiedbotanicals', 42, $now), 1);
$r = Db::one('SELECT follow_status, follow_attempted_at, follow_note FROM ig_accounts WHERE id = 1');
check('pending row status', $r['follow_status'], 'already_following');
check('pending row timestamp', $r['follow_attempted_at'], $now);
check('pending row note', $r['follow_note'], 'in @verifiedbotanicals following list, Apify run 42');

check('followed row untouched', $scraper->markAlreadyFollowing('1002', 'verifiedbotanicals', 42, $now), 0);
check('followed row status kept', Db::scalar('SELECT follow_status FROM ig_accounts WHERE id = 2'), 'followed');

check('skipped row untouched', $scraper->markAlreadyFollowing('1003', 'verifiedbotanicals', 42, $now), 0);
$r = Db::one('SELECT follow_status, follow_note FROM ig_accounts WHERE id = 3');
check('skipped row status and note kept', [$r['follow_status'], $r['follow_note']], ['skipped', 'operator note']);

check('requested row untouched', $scraper->markAlreadyFollowing('1004', 'verifiedbotanicals', 42, $now), 0);
check('already-marked row is not marked twice', $scraper->markAlreadyFollowing('1001', 'verifiedbotanicals', 43, $now), 0);
check('unknown account changes nothing', $scraper->markAlreadyFollowing('9999', 'verifiedbotanicals', 42, $now), 0);
check('other pending row still pending', Db::scalar('SELECT follow_status FROM ig_accounts WHERE id = 5'), 'pending');
check('no rows added', (int) Db::scalar('SELECT COUNT(*) FROM ig_accounts'), 5);

echo '== ig_own_following: add and refresh one account' . PHP_EOL;
$pdo->exec("CREATE TABLE ig_own_following (
    ig_user_id TEXT PRIMARY KEY,
    username TEXT NOT NULL,
    full_name TEXT NULL,
    is_private INTEGER NOT NULL DEFAULT 0,
    source TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    last_visited_at TEXT NULL,
    last_liked_at TEXT NULL,
    visit_note TEXT NULL
)");
$t1 = '2026-10-15 09:00:00';
$t2 = '2026-10-22 09:00:00';
check('new account inserted', OwnFollowing::upsertFromItem(
    ['userId' => '2001', 'username' => 'alpha', 'fullName' => 'Alpha A', 'isPrivate' => false], 'following_list', $t1), 'inserted');
$r = Db::one('SELECT * FROM ig_own_following WHERE ig_user_id = ?', ['2001']);
check('new row fields', [$r['username'], $r['full_name'], (int) $r['is_private'], $r['source'], $r['first_seen_at'], $r['last_seen_at']],
    ['alpha', 'Alpha A', 0, 'following_list', $t1, $t1]);

Db::run("UPDATE ig_own_following SET last_visited_at = '2026-10-16 10:00:00', last_liked_at = '2026-10-16 10:01:00' WHERE ig_user_id = '2001'");
check('existing account updated', OwnFollowing::upsertFromItem(
    ['userId' => '2001', 'username' => 'alpha_renamed', 'fullName' => '', 'isPrivate' => true], 'follow_script', $t2), 'updated');
$r = Db::one('SELECT * FROM ig_own_following WHERE ig_user_id = ?', ['2001']);
check('refresh takes the new username, name and privacy', [$r['username'], $r['full_name'], (int) $r['is_private'], $r['last_seen_at']],
    ['alpha_renamed', null, 1, $t2]);
check('refresh keeps source, first seen and visit history', [$r['source'], $r['first_seen_at'], $r['last_visited_at'], $r['last_liked_at']],
    ['following_list', $t1, '2026-10-16 10:00:00', '2026-10-16 10:01:00']);
check('item without user id skipped', OwnFollowing::upsertFromItem(['username' => 'nobody'], 'following_list', $t1), 'skipped');
check('item without username skipped', OwnFollowing::upsertFromItem(['userId' => '2999'], 'following_list', $t1), 'skipped');
check('one row so far', (int) Db::scalar('SELECT COUNT(*) FROM ig_own_following'), 1);

echo '== ig_own_following: rebuild from a stored run' . PHP_EOL;
$pdo->exec("CREATE TABLE ig_scrape_runs (id INTEGER PRIMARY KEY, actor_id TEXT, kind TEXT, apify_run_id TEXT, dataset_id TEXT,
    source_username TEXT, data_to_scrape TEXT, results_limit INTEGER, status TEXT, started_at TEXT, finished_at TEXT,
    items_total INTEGER, items_imported INTEGER, cursor_offset INTEGER, error TEXT)");
$pdo->exec("INSERT INTO ig_scrape_runs (id, source_username, data_to_scrape, status, started_at) VALUES
    (3, 'american_kratom_assoc', 'followers', 'SUCCEEDED', '2026-09-16 10:00:00'),
    (19, 'verifiedbotanicals', 'following', 'SUCCEEDED', '2026-10-14 15:40:01'),
    (20, 'american_kratom_assoc', 'following', 'SUCCEEDED', '2026-10-14 16:00:00')");
$pdo->exec("CREATE TABLE ig_raw_items (id INTEGER PRIMARY KEY, run_id INTEGER, ig_user_id TEXT, payload TEXT, imported_at TEXT)");
$raw = $pdo->prepare('INSERT INTO ig_raw_items (run_id, ig_user_id, payload, imported_at) VALUES (?, ?, ?, ?)');
foreach ([
    ['2001', ['userId' => '2001', 'username' => 'alpha_again', 'isPrivate' => false]],
    ['2002', ['userId' => '2002', 'username' => 'beta', 'fullName' => 'Beta B', 'isPrivate' => true]],
    ['2003', ['userId' => '2003', 'username' => 'gamma', 'isPrivate' => false]],
    [null,   ['error' => 'no_items']],
] as [$uid, $payload]) {
    $raw->execute([19, $uid, json_encode($payload), $t1]);
}
$raw->execute([3, '9001', json_encode(['userId' => '9001', 'username' => 'not_ours']), $t1]);

$scraperForRebuild = new Scraper();
$res = OwnFollowing::rebuildFromRun(19, $scraperForRebuild, $t2);
check('rebuild counts', $res, ['run' => 19, 'items' => 4, 'inserted' => 2, 'updated' => 1, 'skipped' => 1]);
check('rows after rebuild', (int) Db::scalar('SELECT COUNT(*) FROM ig_own_following'), 3);
check('other runs ignored', (int) Db::scalar("SELECT COUNT(*) FROM ig_own_following WHERE ig_user_id = '9001'"), 0);
check('private account kept as private', (int) Db::scalar("SELECT is_private FROM ig_own_following WHERE ig_user_id = '2002'"), 1);
check('repeat rebuild adds nothing', OwnFollowing::rebuildFromRun(19, $scraperForRebuild, $t2), ['run' => 19, 'items' => 4, 'inserted' => 0, 'updated' => 3, 'skipped' => 1]);

$refused = null;
try {
    OwnFollowing::rebuildFromRun(3, $scraperForRebuild, $t2);
} catch (InvalidArgumentException $e) {
    $refused = 'refused';
}
check('rebuild refuses a followers run', $refused, 'refused');
$refused = null;
try {
    OwnFollowing::rebuildFromRun(20, $scraperForRebuild, $t2);
} catch (InvalidArgumentException $e) {
    $refused = 'refused';
}
check('rebuild refuses another account\'s following list', $refused, 'refused');

check('latest own following run found', OwnFollowing::latestOwnFollowingRunId(), 19);

echo PHP_EOL . ($fails === 0 ? 'ALL PASS' : "$fails FAILED") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
