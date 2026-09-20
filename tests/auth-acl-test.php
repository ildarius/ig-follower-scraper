<?php
/**
 * Tests for Auth and Acl, the two classes that replaced the localhost guard.
 *
 * Neither class touches the database, so this runs anywhere PHP does and needs
 * no config.php. Run it after deploying to the remote host, before letting
 * anybody in:
 *
 *     php tests/auth-acl-test.php
 *
 * Exit status is 0 when every case passes. The bcrypt cost is 4 here to keep
 * the run fast; real hashes in config.php use cost 12.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/Config.php';
require dirname(__DIR__) . '/src/Auth.php';
require dirname(__DIR__) . '/src/Acl.php';

const CASES = [
    'localhost-allows-loopback',
    'localhost-denies-remote',
    'builtin-no-session',
    'builtin-correct-password',
    'builtin-wrong-password',
    'builtin-unknown-user',
    'builtin-bad-role-in-config',
    'builtin-operator',
    'builtin-revoked-mid-session',
    'builtin-demoted-mid-session',
    'basic-maps-username',
    'basic-unmapped-username',
    'default-requires-parent-session',
    'external-no-session',
    'external-admin',
    'external-marketer',
    'external-unknown-role',
    'external-legacy-session',
    'external-allowlist-maps-email',
    'external-allowlist-denies-unlisted',
    'external-bad-mapped-role',
    'external-mismatched-email',
    'external-inactive-user',
    'external-malformed-identity',
    'external-forged-headers',
    'external-invalid-cookie',
    'external-wrong-session-name',
    'unknown-provider-throws',
    'acl-operator-actions',
    'acl-admin-actions',
    'acl-pages',
    'acl-bulk-limits',
];

// No argument means "run everything". Each case runs in its own process so that
// Auth's per-request cache and PHP's session state start clean, which is what a
// real request gets.
if (!isset($argv[1])) {
    $passed = 0;
    $failed = [];
    foreach (CASES as $name) {
        echo '== ' . $name . PHP_EOL;
        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 '
            . escapeshellarg(__FILE__) . ' ' . escapeshellarg($name) . ' 2>&1';
        exec($cmd, $lines, $status);
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            echo $line . PHP_EOL;
        }
        $lines = [];
        if ($status === 0) {
            $passed++;
        } else {
            $failed[] = $name;
        }
    }
    echo PHP_EOL;
    echo 'cases passed: ' . $passed . '   cases failed: ' . count($failed) . PHP_EOL;
    if ($failed !== []) {
        echo 'failed: ' . implode(', ', $failed) . PHP_EOL;
    }
    exit($failed === [] ? 0 : 1);
}

$case = $argv[1];
$fails = 0;

// Isolate tests from real login sessions and keep output behind session headers.
$sessionDir = sys_get_temp_dir() . '/igfs-auth-' . bin2hex(random_bytes(8));
mkdir($sessionDir, 0700);
ini_set('session.save_path', $sessionDir);
ob_start();
register_shutdown_function(static function () use ($sessionDir): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_abort();
    }
    foreach (glob($sessionDir . '/sess_*') as $file) {
        unlink($file);
    }
    rmdir($sessionDir);
    ob_end_flush();
});

function hostSession(array $data, string $name = 'PHPSESSID'): void
{
    session_name($name);
    session_start();
    $_SESSION = $data;
    $_COOKIE[$name] = session_id();
    session_write_close();
    session_id('');
    $_SESSION = [];
}

function check(string $label, mixed $got, mixed $want): void {
    global $fails;
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("  [%s] %-50s got=%s want=%s\n", $ok ? 'ok' : 'FAIL', $label,
        json_encode($got), json_encode($want));
}

$hashAdmin = password_hash('correct-horse', PASSWORD_BCRYPT, ['cost' => 4]);
$hashOp    = password_hash('operator-pass', PASSWORD_BCRYPT, ['cost' => 4]);

$base = [
    'timezone' => 'America/New_York',
    'auth' => [
        'users' => [
            'ildar'    => ['hash' => $hashAdmin, 'role' => 'admin'],
            'employee' => ['hash' => $hashOp,    'role' => 'operator'],
            'broken'   => ['hash' => $hashOp,    'role' => 'superuser'],
        ],
        'basic_roles'    => ['ildar' => 'admin', 'employee' => 'operator'],
        'external_roles' => [],
        'cookie_secure'  => false,
    ],
];
$cfg = static function (array $over) use ($base): void {
    Config::load(array_replace_recursive($base, ['auth' => $over]));
};

switch ($case) {

case 'localhost-allows-loopback':
    $cfg(['provider' => 'localhost']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    check('loopback is admin', Auth::user(), ['username' => 'localhost', 'role' => 'admin']);
    break;

case 'localhost-denies-remote':
    $cfg(['provider' => 'localhost']);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    check('remote address is nobody', Auth::user(), null);
    break;

case 'builtin-no-session':
    $cfg(['provider' => 'builtin']);
    check('no session is nobody', Auth::user(), null);
    break;

case 'builtin-correct-password':
    $cfg(['provider' => 'builtin']);
    check('admin login returns role', Auth::attemptLogin('ildar', 'correct-horse'), 'admin');
    check('now signed in as admin', Auth::user(), ['username' => 'ildar', 'role' => 'admin']);
    check('isAdmin', Auth::isAdmin(), true);
    break;

case 'builtin-wrong-password':
    $cfg(['provider' => 'builtin']);
    check('wrong password refused', Auth::attemptLogin('ildar', 'not-the-password'), null);
    check('still nobody', Auth::user(), null);
    break;

case 'builtin-unknown-user':
    $cfg(['provider' => 'builtin']);
    check('unknown user refused', Auth::attemptLogin('mallory', 'anything'), null);
    break;

case 'builtin-bad-role-in-config':
    $cfg(['provider' => 'builtin']);
    check('unknown role refused not granted', Auth::attemptLogin('broken', 'operator-pass'), null);
    break;

case 'builtin-operator':
    $cfg(['provider' => 'builtin']);
    check('operator login', Auth::attemptLogin('employee', 'operator-pass'), 'operator');
    check('isAdmin is false', Auth::isAdmin(), false);
    break;

case 'builtin-revoked-mid-session':
    $cfg(['provider' => 'builtin']);
    check('operator logs in', Auth::attemptLogin('employee', 'operator-pass'), 'operator');
    // Rebuild the config outright. array_replace_recursive would merge the
    // users list and leave the deleted user in place, which is what the first
    // version of this test did wrong.
    $shrunk = $base;
    $shrunk['auth']['provider'] = 'builtin';
    $shrunk['auth']['users'] = ['ildar' => ['hash' => $hashAdmin, 'role' => 'admin']];
    Config::load($shrunk);
    $r = new ReflectionClass(Auth::class);
    $p = $r->getProperty('resolved'); $p->setAccessible(true); $p->setValue(null, null);
    check('deleting the user ends the session', Auth::user(), null);
    break;

case 'basic-maps-username':
    $cfg(['provider' => 'basic']);
    $_SERVER['PHP_AUTH_USER'] = 'employee';
    check('basic user gets mapped role', Auth::user(), ['username' => 'employee', 'role' => 'operator']);
    break;

case 'basic-unmapped-username':
    $cfg(['provider' => 'basic']);
    $_SERVER['PHP_AUTH_USER'] = 'unrelated-webserver-account';
    check('unmapped basic user is nobody', Auth::user(), null);
    break;

case 'default-requires-parent-session':
    Config::load([]);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    check('default provider uses parent login', Auth::provider(), 'external');
    check('loopback does not bypass shared login', Auth::user(), null);
    break;

case 'external-no-session':
    $cfg(['provider' => 'external']);
    check('missing parent session denied', Auth::user(), null);
    break;

case 'external-admin':
case 'external-marketer':
case 'external-unknown-role':
case 'external-mismatched-email':
case 'external-inactive-user':
case 'external-allowlist-denies-unlisted':
    $cfg(['provider' => 'external', 'external_roles' => $case === 'external-allowlist-denies-unlisted'
        ? ['another@example.com' => 'admin'] : []]);
    $hostRole = match ($case) {
        'external-marketer' => 'marketer',
        'external-unknown-role' => 'guest',
        default => 'admin',
    };
    hostSession([
        'user' => ['email' => 'Owner@Example.com'],
        'app_user' => [
            'email' => $case === 'external-mismatched-email' ? 'someone@example.com' : 'owner@example.com',
            'role' => $hostRole,
            'is_active' => $case === 'external-inactive-user' ? 0 : 1,
        ],
        'oauth_state' => 'keep-parent-data',
    ]);
    $expected = match ($case) {
        'external-admin' => ['username' => 'owner@example.com', 'role' => 'admin'],
        'external-marketer' => ['username' => 'owner@example.com', 'role' => 'operator'],
        default => null,
    };
    check('host identity and role are checked', Auth::user(), $expected);
    check('parent session lock is released', session_status(), PHP_SESSION_NONE);
    check('parent session data is preserved', $_SESSION['oauth_state'], 'keep-parent-data');
    check('shared cookie keeps Strict SameSite', session_get_cookie_params()['samesite'], 'Strict');
    break;

case 'external-legacy-session':
case 'external-allowlist-maps-email':
case 'external-bad-mapped-role':
    $mappedRole = $case === 'external-bad-mapped-role' ? 'superuser' : 'operator';
    $cfg(['provider' => 'external', 'external_roles' => $case === 'external-legacy-session'
        ? [] : ['owner@example.com' => $mappedRole]]);
    hostSession(['user' => ['email' => 'owner@example.com']]);
    check('legacy session requires a valid explicit mapping', Auth::user(),
        $case === 'external-allowlist-maps-email'
            ? ['username' => 'owner@example.com', 'role' => 'operator'] : null);
    break;

case 'external-malformed-identity':
    $cfg(['provider' => 'external']);
    hostSession(['user' => ['email' => ['owner@example.com']], 'app_user' => 'admin']);
    check('malformed identity denied', Auth::user(), null);
    break;

case 'external-forged-headers':
    $cfg(['provider' => 'external']);
    $_SERVER['HTTP_X_AUTH_USER'] = 'owner@example.com';
    $_SERVER['HTTP_X_AUTH_ROLE'] = 'admin';
    $_SERVER['PHP_AUTH_USER'] = 'owner@example.com';
    $_COOKIE['user'] = 'owner@example.com';
    $_COOKIE['role'] = 'admin';
    check('client identity claims do not authenticate', Auth::user(), null);
    break;

case 'external-invalid-cookie':
    $cfg(['provider' => 'external']);
    $_COOKIE['PHPSESSID'] = ['not-a-session-id'];
    check('invalid cookie denied without a type error', Auth::user(), null);
    break;

case 'external-wrong-session-name':
    $cfg(['provider' => 'external']);
    session_name('igfs');
    session_start();
    $_SESSION = ['user' => ['email' => 'owner@example.com'],
        'app_user' => ['email' => 'owner@example.com', 'role' => 'admin']];
    check('a different app session cannot supply identity', Auth::user(), null);
    break;

case 'unknown-provider-throws':
    $cfg(['provider' => 'typo']);
    try { Auth::user(); check('should have thrown', 'no exception', 'exception'); }
    catch (RuntimeException $e) { check('typo in provider throws', true, true); }
    break;

case 'acl-operator-actions':
    foreach (['accounts-search', 'accounts-facets', 'accounts-bulk', 'queue-stats'] as $a) {
        check('operator may ' . $a, Acl::allowsAction('operator', $a), true);
    }
    foreach (['start', 'enrich', 'dump', 'migrate', 'reset-cursor', 'import', 'import-all',
              'usage', 'raw', 'probe', 'stats', 'queue', 'account', 'abort', 'log',
              'an-action-added-next-month'] as $a) {
        check('operator may not ' . $a, Acl::allowsAction('operator', $a), false);
    }
    break;

case 'acl-admin-actions':
    foreach (['start', 'enrich', 'dump', 'accounts-bulk', 'anything-at-all'] as $a) {
        check('admin may ' . $a, Acl::allowsAction('admin', $a), true);
    }
    break;

case 'acl-pages':
    check('operator opens accounts.php', Acl::allowsPage('operator', 'accounts.php'), true);
    check('operator opens login.php', Acl::allowsPage('operator', 'login.php'), true);
    check('operator does NOT open index.php', Acl::allowsPage('operator', 'index.php'), false);
    check('admin opens index.php', Acl::allowsPage('admin', 'index.php'), true);
    check('unknown role opens nothing', Acl::allowsPage('guest', 'accounts.php'), false);
    break;

case 'acl-bulk-limits':
    Config::load($base);
    check('admin is unlimited', Acl::bulkLimits('admin'), null);
    check('operator limits', Acl::bulkLimits('operator'),
        ['operations' => ['skip', 'revert'], 'max_rows' => 500]);
    $cfg(['operator_bulk_max_rows' => 50]);
    check('cap is configurable', Acl::bulkLimits('operator')['max_rows'], 50);
    break;

case 'builtin-demoted-mid-session':
    $cfg(['provider' => 'builtin']);
    check('admin logs in', Auth::attemptLogin('ildar', 'correct-horse'), 'admin');
    $demoted = $base;
    $demoted['auth']['provider'] = 'builtin';
    $demoted['auth']['users']['ildar']['role'] = 'operator';
    Config::load($demoted);
    $p = (new ReflectionClass(Auth::class))->getProperty('resolved');
    $p->setAccessible(true);
    $p->setValue(null, null);
    check('demotion applies on the next request', Auth::user(), ['username' => 'ildar', 'role' => 'operator']);
    break;

default:
    echo "  unknown case\n"; exit(2);
}

exit($fails === 0 ? 0 : 1);
