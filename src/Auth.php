<?php
declare(strict_types=1);

/**
 * Authentication for the web entry points.
 *
 * The application was built to run on localhost only, and api.php enforced that
 * by comparing REMOTE_ADDR against 127.0.0.1. On a remote host REMOTE_ADDR is
 * the visitor's address, so that check rejects everyone including the operator.
 * Deleting it without a replacement leaves an application that can spend money
 * through ?action=start and ?action=enrich, rewrite thousands of rows through
 * ?action=accounts-bulk and hand out the whole dataset through ?action=dump.
 *
 * This class is the replacement. It resolves the current visitor to a username
 * and a role, or to null. It does not decide what that role may do; Acl does.
 *
 * Four providers, chosen with the 'auth.provider' config key:
 *
 *   localhost  The original behaviour. Only 127.0.0.1 and ::1, always as admin.
 *              Correct for the PC, wrong for any remote host.
 *   builtin    A session login against bcrypt hashes in config.php. Self
 *              contained, works on any host, carries a per-user role.
 *   basic      HTTP Basic enforced by the web server. PHP reads PHP_AUTH_USER
 *              and maps it to a role through 'auth.basic_roles'.
 *   external   Identity comes from whatever already authenticates the host.
 *              See externalIdentity() below, which is the only function that
 *              needs to be written to adopt an existing sign-in system.
 */
final class Auth
{
    public const ROLE_ADMIN    = 'admin';
    public const ROLE_OPERATOR = 'operator';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATOR];

    private static ?array $resolved = null;
    private static bool $sessionStarted = false;

    /**
     * The current visitor as ['username' => string, 'role' => string], or null
     * when nobody is authenticated. Resolved once per request.
     */
    public static function user(): ?array
    {
        if (self::$resolved !== null) {
            return self::$resolved['user'];
        }

        $provider = (string) Config::get('auth.provider', 'localhost');

        $user = match ($provider) {
            'localhost' => self::localhostUser(),
            'builtin'   => self::builtinUser(),
            'basic'     => self::basicUser(),
            'external'  => self::externalIdentity(),
            default     => throw new RuntimeException(
                'Unknown auth.provider "' . $provider . '". Use localhost, builtin, basic or external.'
            ),
        };

        if ($user !== null) {
            $username = trim((string) ($user['username'] ?? ''));
            $role     = (string) ($user['role'] ?? '');

            if ($username === '' || !in_array($role, self::ROLES, true)) {
                // A provider that returns a malformed identity is treated as no
                // identity. Failing closed here means a typo in a role name
                // locks people out rather than granting an unintended role.
                $user = null;
            } else {
                $user = ['username' => $username, 'role' => $role];
            }
        }

        self::$resolved = ['user' => $user];

        return $user;
    }

    /**
     * True when somebody is authenticated and holds the given role.
     */
    public static function hasRole(string $role): bool
    {
        $user = self::user();

        return $user !== null && $user['role'] === $role;
    }

    public static function isAdmin(): bool
    {
        return self::hasRole(self::ROLE_ADMIN);
    }

    /**
     * The provider currently in force. Used by login.php to decide whether a
     * login form makes sense, since only the builtin provider has one.
     */
    public static function provider(): string
    {
        return (string) Config::get('auth.provider', 'localhost');
    }

    // ---------------------------------------------------------------- providers

    /**
     * The original localhost guard, preserved so the PC keeps working unchanged.
     */
    private static function localhostUser(): ?array
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
            return null;
        }

        return ['username' => 'localhost', 'role' => self::ROLE_ADMIN];
    }

    /**
     * Session login against bcrypt hashes held in config.php.
     */
    private static function builtinUser(): ?array
    {
        self::startSession();

        $username = $_SESSION['auth_user'] ?? null;
        $role     = $_SESSION['auth_role'] ?? null;

        if (!is_string($username) || !is_string($role)) {
            return null;
        }

        // Re-read the role from config on every request rather than trusting the
        // copy in the session. Demoting or deleting a user in config.php then
        // takes effect on their next request instead of on their next login.
        $configured = self::configuredUser($username);

        if ($configured === null) {
            self::logout();

            return null;
        }

        return ['username' => $username, 'role' => $configured['role']];
    }

    /**
     * HTTP Basic, enforced at the web server. PHP only reads the username.
     */
    private static function basicUser(): ?array
    {
        $username = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');

        if ($username === '') {
            return null;
        }

        $roles = Config::get('auth.basic_roles', []);

        if (!is_array($roles) || !isset($roles[$username])) {
            // Authenticated by the web server but not known here. No role is
            // assumed, because assuming one would make adding a web server user
            // silently grant access to this application.
            return null;
        }

        return ['username' => $username, 'role' => (string) $roles[$username]];
    }

    /**
     * Identity from an existing sign-in system on the same host.
     *
     * THIS IS THE FUNCTION TO WRITE when adopting the authentication already
     * running on seo.bizousoft.com. It must return either null, meaning nobody
     * is signed in, or ['username' => string, 'role' => 'admin'|'operator'].
     *
     * Whatever it reads, three rules apply.
     *
     * First, it must never trust a value the browser can set directly. A cookie
     * saying role=admin is a value the browser can set. A session id that the
     * other application's own session store resolves to a user is not.
     *
     * Second, the role must be derived here rather than taken from the other
     * system, unless that system already has a role concept that means the same
     * thing. Mapping its usernames through 'auth.external_roles' in config.php
     * is the simplest correct approach, and is what the example below does.
     *
     * Third, an unrecognised user returns null rather than a default role. A
     * user of seo.bizousoft.com who has nothing to do with this application must
     * not become an operator of it by existing.
     *
     * Worked example, for the common case where the other application on the
     * same host stores its login in a PHP session:
     *
     *     session_name('the_other_apps_session_name');
     *     session_start();
     *     $username = $_SESSION['username'] ?? null;
     *     if (!is_string($username) || $username === '') {
     *         return null;
     *     }
     *     $roles = Config::get('auth.external_roles', []);
     *     if (!isset($roles[$username])) {
     *         return null;
     *     }
     *     return ['username' => $username, 'role' => (string) $roles[$username]];
     *
     * Until it is written, it returns null, which denies everyone. That is
     * deliberate. An unwritten authentication function that denies everyone is a
     * locked door; one that returns a default user is an open one.
     */
    private static function externalIdentity(): ?array
    {
        return null;
    }

    // ------------------------------------------------------------------- login

    /**
     * Verify a username and password against config.php and start a session.
     * Only meaningful under the builtin provider. Returns the role on success
     * and null on failure.
     */
    public static function attemptLogin(string $username, string $password): ?string
    {
        if (self::provider() !== 'builtin') {
            throw new RuntimeException('attemptLogin is only valid under the builtin auth provider');
        }

        $configured = self::configuredUser($username);

        // password_verify against a known-invalid hash when the user does not
        // exist, so that a missing username and a wrong password take a similar
        // amount of time and cannot be told apart by timing.
        $hash = $configured['hash'] ?? '$2y$12$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidinv';

        if (!password_verify($password, $hash) || $configured === null) {
            return null;
        }

        self::startSession();
        session_regenerate_id(true);

        $_SESSION['auth_user'] = $username;
        $_SESSION['auth_role'] = $configured['role'];

        self::$resolved = null;

        return $configured['role'];
    }

    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?: 'Lax',
                ]
            );
        }

        session_destroy();

        self::$resolved = null;
    }

    /**
     * Look a username up in 'auth.users'. Returns ['hash' => string,
     * 'role' => string] or null.
     */
    private static function configuredUser(string $username): ?array
    {
        $users = Config::get('auth.users', []);

        if (!is_array($users) || !isset($users[$username]) || !is_array($users[$username])) {
            return null;
        }

        $entry = $users[$username];
        $hash  = (string) ($entry['hash'] ?? '');
        $role  = (string) ($entry['role'] ?? '');

        if ($hash === '' || !in_array($role, self::ROLES, true)) {
            return null;
        }

        return ['hash' => $hash, 'role' => $role];
    }

    /**
     * Start the session with cookie flags that suit a login session.
     *
     * secure defaults to true because the hand-off requires TLS. It is a config
     * key rather than a constant only so that the application can be exercised
     * over plain HTTP on the PC during testing.
     */
    private static function startSession(): void
    {
        if (self::$sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            self::$sessionStarted = true;

            return;
        }

        session_name((string) Config::get('auth.session_name', 'igfs'));

        session_set_cookie_params([
            'lifetime' => (int) Config::get('auth.session_lifetime', 0),
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('auth.cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        self::$sessionStarted = true;
    }
}
