<?php
/**
 * Shared bootstrap. Every entry point (cli.php, web/public/api.php) requires this.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);

mb_internal_encoding('UTF-8');

$configPath = APP_ROOT . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config.php. Copy config.example.php to config.php and fill it in.\n");
    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Missing config.php. Copy config.example.php to config.php and fill it in.']);
    }
    exit(1);
}

/** @var array $CONFIG */
$CONFIG = require $configPath;

date_default_timezone_set($CONFIG['timezone'] ?? 'America/New_York');

spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/src/' . $class . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

Config::load($CONFIG);
