<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$argv = [__FILE__, 'cleanup-uploads'];
require __DIR__ . '/console.php';
