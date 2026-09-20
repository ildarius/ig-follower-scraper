<?php
/**
 * Shared bootstrap. Every entry point (cli.php, web/public/api.php) requires this.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);

mb_internal_encoding('UTF-8');

$configPath = APP_ROOT . '/config.php';
define('APP_CONFIGURED', is_file($configPath));
if (!APP_CONFIGURED && PHP_SAPI === 'cli') {
    fwrite(STDERR, "Missing config.php. Copy config.example.php to config.php and fill it in.\n");
    exit(1);
}

// Web authentication must work even before private DB/Apify settings exist.
// With no auth configuration, Auth uses the parent site's session provider.
/** @var array $CONFIG */
$CONFIG = APP_CONFIGURED ? require $configPath : [];

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

date_default_timezone_set($CONFIG['timezone'] ?? 'America/New_York');

spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

Config::load($CONFIG);
