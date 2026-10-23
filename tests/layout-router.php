<?php
declare(strict_types=1);
// Solo para revisar las medidas reales de CSS en un servidor local de pruebas.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__layout') {
    $width = ($_GET['width'] ?? '') === '390' ? 390 : 1440;
    $screen = in_array($_GET['screen'] ?? '', ['login', 'registro', 'catalogo', 'admin', 'detalle'], true) ? $_GET['screen'] : 'catalogo';
    $scale = $width === 390 ? 1 : 0.43;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>ClassicFlix · revisión de medidas</title><style>body{margin:0;background:#292929}iframe{border:0;width:' . $width . 'px;height:1800px;transform:scale(' . $scale . ');transform-origin:top left}</style><iframe title="Vista a ' . $width . ' píxeles" src="index.php?r=/preview&amp;screen=' . $screen . '"></iframe></html>';
    return true;
}
if ($path !== '/' && $path !== '/index.php' && is_file(dirname(__DIR__) . '/Frontend' . $path)) { return false; }
$app = require dirname(__DIR__) . '/Backend/bootstrap.php';
ob_start();
$app->run();
header_remove('X-Frame-Options'); // Exclusivo de este servidor de inspección local.
echo str_replace('loading="lazy"', 'loading="eager"', (string) ob_get_clean());
