<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Una activación explícita de la muestra, sin crear ni cambiar el archivo .env.
putenv('APP_ENV=local');
putenv('APP_PREVIEW_ENABLED=true');
echo "ClassicFlix: http://127.0.0.1:8080/index.php?r=/preview\n";
echo "Vista de muestra. Detén el servidor con Ctrl+C.\n";
passthru(escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:8080 -t ' . escapeshellarg(dirname(__DIR__) . '/Frontend'), $exitCode);
exit($exitCode);
