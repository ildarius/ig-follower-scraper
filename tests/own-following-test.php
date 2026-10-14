<?php
/**
 * Tests for marking accounts we already follow, from a following-list scrape of
 * our own account (Scraper::isOwnFollowingRun and Scraper::markAlreadyFollowing).
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

echo PHP_EOL . ($fails === 0 ? 'ALL PASS' : "$fails FAILED") . PHP_EOL;
exit($fails === 0 ? 0 : 1);
