<?php
/**
 * Tests for ProfilePhoto, the review screen's Fetch photo button.
 *
 *     php tests/profile-photo-test.php
 *
 * Needs pdo_sqlite and gd (for a real JPEG to "download"), nothing else: no
 * config.php, no MySQL, no HikerAPI, no network. The HikerAPI lookup and the
 * download are replaced through ProfilePhoto::$lookup and ::$download, and
 * photos go to a temporary folder. Exit status is 0 when every case passes.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/HikerApi.php';
require dirname(__DIR__) . '/src/ProfilePhoto.php';

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

/** Run $fn and return the exception message, or null if it did not throw. */
function error_of(callable $fn): ?string
{
    try { $fn(); return null; } catch (Throwable $e) { return $e->getMessage(); }
}

$dir = sys_get_temp_dir() . '/profile-photo-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
Config::load(['timezone' => 'America/New_York', 'photos_dir' => $dir, 'hikerapi' => ['daily_photo_budget' => 5]]);

// A file, not :memory:, so the lock test's second process sees the same rows.
$dbFile = $dir . '/test.sqlite';
$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);
$pdo->exec("CREATE TABLE ig_accounts (id INTEGER PRIMARY KEY, ig_user_id TEXT, username TEXT, follow_status TEXT)");
foreach ([
    [1, '1001', 'alpha',    'pending'],
    [2, '1002', 'beta',     'pending'],
    [3, '1003', 'gamma',    'followed'],
    [4, 'abc',  'badid',    'pending'],
    [5, '1005', 'renamed',  'pending'],
    [6, '1006', 'evilhost', 'pending'],
    [7, '1007', 'notimage', 'pending'],
    [8, '1008', 'budget1',  'pending'],
    [9, '1009', 'budget2',  'pending'],
] as $r) {
    $pdo->prepare('INSERT INTO ig_accounts VALUES (?, ?, ?, ?)')->execute($r);
}

$img = imagecreatetruecolor(4, 4);
ob_start(); imagejpeg($img); $jpeg = ob_get_clean();

$lookups = [];
ProfilePhoto::$lookup = static function (string $username) use (&$lookups): array {
    $lookups[] = $username;
    $pk = ['alpha' => '1001', 'beta' => '1002', 'renamed' => '9999', 'evilhost' => '1006',
           'notimage' => '1007', 'budget1' => '1008', 'budget2' => '1009'][$username] ?? '0';
    $host = $username === 'evilhost' ? 'https://evil.example.com/x.jpg' : 'https://scontent-iad6-1.cdninstagram.com/v/p.jpg?oe=1';
    return ['pk' => $pk, 'username' => $username, 'profile_pic_url_hd' => $host];
};
$downloads = 0;
ProfilePhoto::$download = static function (string $url, string $dest) use (&$downloads, $jpeg): void {
    $downloads++;
    file_put_contents($dest, str_contains($url, 'notimage') ? '<html>nope</html>' : $jpeg);
};

echo "success saves the photo\n";
check('fetch alpha', ProfilePhoto::fetch(1), ['id' => 1, 'cached' => false]);
check('one paid lookup', $lookups, ['alpha']);
check('saved', ProfilePhoto::has('1001'), true);
check('saved file is the image', file_get_contents($dir . '/1001.img'), $jpeg);
check('no temp files left', glob($dir . '/*.tmp-*'), []);

echo "a saved photo is never paid for again\n";
check('second fetch is cached', ProfilePhoto::fetch(1), ['id' => 1, 'cached' => true]);
check('still one lookup', count($lookups), 1);

echo "only pending accounts\n";
check('followed account refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(3)), 'no longer pending'), true);
check('unknown id refused', error_of(fn () => ProfilePhoto::fetch(999)) !== null, true);
check('non-numeric ig id refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(4)), 'numeric'), true);
check('path() refuses traversal', ProfilePhoto::path('../../etc/passwd'), null);
check('no lookups for refused accounts', count($lookups), 1);

echo "a different account is not saved\n";
check('renamed account refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(5)), 'different account'), true);
check('renamed: nothing saved', ProfilePhoto::has('1005'), false);
check('renamed: failure recorded', is_file($dir . '/1005.fail'), true);

echo "failure cooldown\n";
$before = count($lookups);
check('retry within cooldown refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(5)), 'failed a few minutes ago'), true);
check('retry within cooldown is free', count($lookups), $before);
touch($dir . '/1005.fail', time() - ProfilePhoto::FAIL_COOLDOWN_SECONDS - 5);
error_of(fn () => ProfilePhoto::fetch(5));
check('after cooldown it tries again', count($lookups), $before + 1);

echo "download restrictions\n";
check('non-Instagram host refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(6)), 'no usable picture'), true);
check('bad host never downloaded', $downloads, 1);
check('cdninstagram https ok', ProfilePhoto::isInstagramCdnUrl('https://scontent.cdninstagram.com/a.jpg'), true);
check('fbcdn https ok', ProfilePhoto::isInstagramCdnUrl('https://instagram.fman4-2.fna.fbcdn.net/a.jpg'), true);
check('http refused', ProfilePhoto::isInstagramCdnUrl('http://scontent.cdninstagram.com/a.jpg'), false);
check('lookalike host refused', ProfilePhoto::isInstagramCdnUrl('https://cdninstagram.com.evil.net/a.jpg'), false);
check('suffix trick refused', ProfilePhoto::isInstagramCdnUrl('https://evilcdninstagram.com/a.jpg'), false);
check('userinfo refused', ProfilePhoto::isInstagramCdnUrl('https://x@scontent.cdninstagram.com/a.jpg'), false);
check('port refused', ProfilePhoto::isInstagramCdnUrl('https://scontent.cdninstagram.com:8443/a.jpg'), false);

echo "non-images are not saved\n";
ProfilePhoto::$lookup = (static function (Closure $inner): Closure {
    return static fn (string $u): array => $u === 'notimage'
        ? ['pk' => '1007', 'profile_pic_url_hd' => 'https://scontent.cdninstagram.com/notimage.jpg']
        : $inner($u);
})(ProfilePhoto::$lookup);
check('html refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(7)), 'not a JPEG'), true);
check('html not saved', ProfilePhoto::has('1007'), false);
check('temp file removed', glob($dir . '/*.tmp-*'), []);

echo "daily budget\n";
// Lookups so far today: alpha, renamed x2, evilhost, notimage = 5 = the budget.
$state = json_decode((string) file_get_contents($dir . '/.budget.json'), true);
check('budget counted every paid lookup', $state['count'], 5);
$before = count($lookups);
check('over budget refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(8)), 'budget'), true);
check('over budget makes no lookup', count($lookups), $before);
check('cached photo still free over budget', ProfilePhoto::fetch(1)['cached'], true);
file_put_contents($dir . '/.budget.json', json_encode(['day' => '2000-01-01', 'count' => 99]));
check('a new day resets the budget', ProfilePhoto::fetch(8)['cached'], false);

echo "a damaged budget file stops fetching\n";
file_put_contents($dir . '/.budget.json', 'not json');
$before = count($lookups);
check('corrupt budget refused', str_contains((string) error_of(fn () => ProfilePhoto::fetch(9)), 'unreadable'), true);
check('corrupt budget makes no lookup', count($lookups), $before);
file_put_contents($dir . '/.budget.json', json_encode(['day' => '2000-01-01', 'count' => 0]));

echo "eligibility is checked again after waiting for the lock\n";
// A second process holds the photo lock, flips account 9 to followed (as the
// follow script would) and releases. This process passes the first pending
// check, waits on the lock, and must then refuse without a paid lookup.
$child = <<<'PHP'
$lock = fopen($argv[1] . '/.lock', 'c'); flock($lock, LOCK_EX);
echo "locked\n"; fflush(STDOUT);
usleep(400000);
$db = new PDO('sqlite:' . $argv[2]); $db->exec("UPDATE ig_accounts SET follow_status = 'followed' WHERE id = 9");
$db = null; flock($lock, LOCK_UN);
PHP;
$proc = proc_open([PHP_BINARY, '-r', $child, $dir, $dbFile], [1 => ['pipe', 'w']], $pipes);
check('helper holds the lock', trim((string) fgets($pipes[1])), 'locked');
$before = count($lookups);
check('queued fetch refused after the row changed', str_contains((string) error_of(fn () => ProfilePhoto::fetch(9)), 'no longer pending'), true);
check('queued fetch made no lookup', count($lookups), $before);
fclose($pipes[1]); proc_close($proc);
Db::run("UPDATE ig_accounts SET follow_status = 'pending' WHERE id = 9");

echo "missing folder\n";
Config::load(['timezone' => 'America/New_York', 'photos_dir' => $dir . '/missing']);
check('missing folder explains itself', str_contains((string) error_of(fn () => ProfilePhoto::fetch(9)), 'not writable'), true);

$pdo = null;
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, null);
foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
    if (is_file($f)) {
        unlink($f);
    }
}
@rmdir($dir);

echo "\n" . ($fails === 0 ? 'All passed.' : $fails . ' failed.') . PHP_EOL;
exit($fails === 0 ? 0 : 1);
