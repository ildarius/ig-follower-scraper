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
 *   external   The default: reuse the parent SEO site's PHP login session.
 *              Parent admins remain admins; marketers become operators.
 */
final class Auth
{
    public const ROLE_ADMIN    = 'admin';
    public const ROLE_OPERATOR = 'operator';
    private const DEFAULT_SESSION_LIFETIME = 34560000; // 400 days

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

        $provider = self::provider();

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
        return (string) Config::get('auth.provider', 'external');
    }

    public static function loginUrl(string $next = 'accounts.php'): string
    {
        if (self::provider() === 'external') {
            $loginUrl = (string) Config::get('auth.external_login_url', '/login.php');

            // The parent login accepts only root-relative, same-site return
            // paths. Passing one preserves the page that initiated sign-in
            // without turning this endpoint into an open redirect.
            if (str_starts_with($next, '/') && !str_starts_with($next, '//')) {
                $loginUrl .= (str_contains($loginUrl, '?') ? '&' : '?')
                    . 'next=' . rawurlencode($next);
            }

            return $loginUrl;
        }

        return 'login.php?next=' . rawurlencode(basename($next));
    }

    public static function logoutUrl(): string
    {
        return self::provider() === 'external'
            ? (string) Config::get('auth.external_logout_url', '/logout.php')
            : 'login.php?logout=1';
    }

    // ---------------------------------------------------------------- providers

    /**
     * The original localhost guard, available by explicitly choosing localhost.
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
     * Reuse the session written by the parent site's Google OAuth callback.
     * Only server-side session data supplies identity, never request headers or
     * a browser-supplied username/role. Read and close it immediately so scraper
     * work neither rewrites the parent session nor holds its lock.
     *
     * A nonempty external_roles map is an email allowlist. Otherwise the parent
     * app_user must match the signed-in email and have a recognised host role.
     * Legacy sessions without app_user need an explicit email mapping.
     */
    private static function externalIdentity(): ?array
    {
        $name = (string) Config::get('auth.external_session_name', 'PHPSESSID');
        $lifetime = self::sessionLifetime('external_session_lifetime');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== $name) {
                return null;
            }
        } else {
            $id = $_COOKIE[$name] ?? null;
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9,-]{1,256}$/D', $id)) {
                return null;
            }

            session_name($name);
            session_id($id);
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            if (!session_start([
                'read_and_close'   => true,
                'use_strict_mode'  => true,
                'use_only_cookies' => true,
                'use_cookies'      => true,
            ])) {
                return null;
            }
        }

        $user = $_SESSION['user'] ?? null;
        $email = is_array($user) ? ($user['email'] ?? null) : null;
        if (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $email = strtolower(trim($email));

        $appUser = $_SESSION['app_user'] ?? null;
        if ($appUser !== null && (!is_array($appUser)
            || !is_string($appUser['email'] ?? null)
            || strtolower(trim($appUser['email'])) !== $email
            || (isset($appUser['is_active']) && (int) $appUser['is_active'] !== 1))) {
            return null;
        }

        $roles = Config::get('auth.external_roles', []);
        if (!is_array($roles)) {
            return null;
        }
        $role = $roles !== []
            ? ($roles[$email] ?? null)
            : match ($appUser['role'] ?? null) {
                'admin'    => self::ROLE_ADMIN,
                'marketer' => self::ROLE_OPERATOR,
                default    => null,
            };

        if (!is_string($role) || !in_array($role, self::ROLES, true)) {
            return null;
        }

        // The scraper may be the only page a signed-in person uses for a
        // while. Renew the shared cookie here as well, so its expiry slides on
        // legitimate scraper activity instead of only parent-dashboard use.
        self::renewExternalSessionCookie($name, $lifetime);

        return ['username' => $email, 'role' => $role];
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

        $lifetime = self::sessionLifetime('session_lifetime');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_name((string) Config::get('auth.session_name', 'igfs'));

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) Config::get('auth.cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        // Renew an existing cookie too, making the configured lifetime a
        // sliding inactivity window rather than a fixed deadline after login.
        if ($lifetime > 0) {
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'secure'   => (bool) Config::get('auth.cookie_secure', true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        self::$sessionStarted = true;
    }

    private static function sessionLifetime(string $key): int
    {
        return max(0, (int) Config::get('auth.' . $key, self::DEFAULT_SESSION_LIFETIME));
    }

    private static function renewExternalSessionCookie(string $name, int $lifetime): void
    {
        if ($lifetime <= 0) {
            return;
        }

        setcookie($name, session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
