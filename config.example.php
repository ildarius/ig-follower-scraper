<?php
/**
 * Copy this file to config.php and fill in the secrets.
 * config.php is gitignored and must never be committed.
 */
declare(strict_types=1);

return [
    // Apify Console -> Settings -> API & Integrations -> Personal API tokens.
    'apify_token' => 'apify_api_PUT_YOURS_HERE',

    // Follower / following list scraper. 8 fields, no profile statistics.
    'actor_id' => 'apify/instagram-followers-following-scraper',

    // Profile enrichment. 21 fields including followersCount, postsCount,
    // biography and business flags. Verified with ?action=probe on 2026-09-17.
    // Optional: the code defaults to this value if the key is absent.
    'enrich_actor_id' => 'memo23/instagram-followers-count-scraper',

    // Laragon defaults: root, empty password.
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'sunny_kratom',
        'user' => 'root',
        'pass' => '',
    ],

    'timezone' => 'America/New_York',

    'import_batch_size' => 500,

    /**
     * Web authentication.
     *
     * Defaults to the parent SEO site's PHP login session. For a standalone
     * local installation, explicitly choose 'localhost' or 'builtin'.
     *
     * Four providers:
     *
     *   localhost  127.0.0.1 and ::1 only, always admin. Explicit local opt-in.
     *   builtin    Session login against the bcrypt hashes in 'users' below.
     *   basic      HTTP Basic enforced by the web server. PHP reads
     *              PHP_AUTH_USER and maps it through 'basic_roles'.
     *   external   Reuse the parent SEO login. Admins stay admins; marketers
     *              become operators. Unknown roles and missing sessions fail
     *              closed. This is the default.
     *
     * Two roles, and they are not interchangeable. 'admin' may do everything.
     * 'operator' may open accounts.php and call four read-or-tag actions, and
     * is refused everything else, including the actions that spend money at
     * Apify and the one that dumps the database.
     */
    'auth' => [
        'provider' => 'external',

        // Generate a hash with:
        //   php -r "echo password_hash('the password', PASSWORD_BCRYPT, ['cost' => 12]), PHP_EOL;"
        // Never put a plaintext password here.
        'users' => [
            // 'ildar'    => ['hash' => '$2y$12$...', 'role' => 'admin'],
            // 'operator' => ['hash' => '$2y$12$...', 'role' => 'operator'],
        ],

        // provider 'basic': which web server username gets which role.
        'basic_roles' => [
            // 'ildar' => 'admin',
        ],

        // Optional email allowlist for provider 'external'. Empty means use
        // the parent's admin/marketer roles. Once any emails are listed, only
        // those addresses may enter; use lowercase email keys.
        'external_roles' => [
            // 'owner@example.com' => 'admin',
            // 'employee@example.com' => 'operator',
        ],

        // Use the same PHP session storage/handler as the parent site.
        'external_session_name' => 'PHPSESSID',
        'external_login_url' => '/login.php',
        'external_logout_url' => '/logout.php',

        // These settings apply only to the separate builtin login session.
        'session_name' => 'igfs',

        // Sliding inactivity window for the builtin-login cookie and its
        // server-side session. 400 days is the practical browser maximum;
        // set 0 only when browser-close expiry is required.
        'session_lifetime' => 34560000,

        // The parent PHPSESSID uses the same 400-day sliding window. This must
        // match the parent site's session policy when provider is 'external'.
        'external_session_lifetime' => 34560000,

        // Sends the session cookie only over HTTPS. Set to false only to test
        // over plain HTTP on the PC, never on the remote host.
        'cookie_secure' => true,

        // The largest number of rows one accounts-bulk call may change for the
        // operator role. Without a cap, one select-all with no filter marks the
        // whole queue and the only visible symptom is the follow script running
        // out of work.
        'operator_bulk_max_rows' => 500,
    ],
];
