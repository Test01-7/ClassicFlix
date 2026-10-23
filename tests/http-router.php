<?php
declare(strict_types=1);

// Test-only adapter OUTSIDE the public document root. Production never reads this variable.
$databaseFile = getenv('CLASSICFLIX_TEST_DB');
$runtime = realpath(__DIR__ . '/.runtime');
$databasePath = is_string($databaseFile) ? realpath($databaseFile) : false;
if (PHP_SAPI !== 'cli-server' || !$runtime || !$databasePath || !str_starts_with(strtolower($databasePath), strtolower($runtime . DIRECTORY_SEPARATOR))) {
    http_response_code(404);
    exit;
}
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestPath) && str_starts_with($requestPath, '/assets/') && !str_contains($requestPath, '..')) { return false; }
$realApp = require dirname(__DIR__) . '/Backend/bootstrap.php';
$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('PRAGMA busy_timeout = 5000');
$app = new ClassicFlix\Application($realApp->config(), new ClassicFlix\Support\Database($realApp->config(), $pdo));
$app->run();
