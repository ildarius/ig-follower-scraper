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
];
