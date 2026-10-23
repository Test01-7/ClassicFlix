<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'ClassicFlix\\';
    if (str_starts_with($class, $prefix)) {
        $file = $root . '/Backend/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
require_once __DIR__ . '/Support/helpers.php';
$config = \ClassicFlix\Support\Config::load($root . '/.env');
date_default_timezone_set((string) $config->get('APP_TIMEZONE', 'America/Lima'));
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $sessionPath = $root . '/var/sessions';
    if (!is_dir($sessionPath)) { mkdir($sessionPath, 0700, true); }
    session_save_path($sessionPath);
    session_name('classicflix_session');
    session_set_cookie_params(['httponly' => true, 'secure' => str_starts_with((string) $config->get('APP_URL', ''), 'https://'),
        'samesite' => 'Lax', 'path' => '/']);
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}
return new \ClassicFlix\Application($config);
