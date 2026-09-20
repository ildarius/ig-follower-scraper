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
     * The whole block is optional. Leaving it out means provider 'localhost',
     * which is the behaviour the application had before the remote move: only
     * 127.0.0.1 and ::1 may reach it, always as admin. That is right for the PC
     * and wrong for any host reachable from the internet.
     *
     * Four providers:
     *
     *   localhost  127.0.0.1 and ::1 only, always admin. The PC default.
     *   builtin    Session login against the bcrypt hashes in 'users' below.
     *   basic      HTTP Basic enforced by the web server. PHP reads
     *              PHP_AUTH_USER and maps it through 'basic_roles'.
     *   external   Identity comes from whatever already authenticates the host.
     *              Write Auth::externalIdentity() to adopt it. Until that
     *              function is written this provider denies everybody, which is
     *              deliberate.
     *
     * Two roles, and they are not interchangeable. 'admin' may do everything.
     * 'operator' may open accounts.php and call four read-or-tag actions, and
     * is refused everything else, including the actions that spend money at
     * Apify and the one that dumps the database.
     */
    'auth' => [
        'provider' => 'localhost',

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

        // provider 'external': which username from the host's own sign-in
        // system gets which role. A user not listed here is refused, so that
        // having an account on the host does not by itself grant access here.
        'external_roles' => [
            // 'ildar' => 'admin',
        ],

        'session_name' => 'igfs',

        // 0 means the cookie expires when the browser closes.
        'session_lifetime' => 0,

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
