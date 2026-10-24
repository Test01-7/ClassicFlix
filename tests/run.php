<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Run in separate PHP processes to isolate sessions, globals and test doubles.
$root = dirname(__DIR__);
$suites = ['Repositorios' => '/scripts/test-repositories.php', 'Reseñas y valoraciones' => '/tests/reviews.php', 'Almacenamiento y caché' => '/tests/storage.php', 'Aplicación HTTP' => '/tests/http.php'];
if (in_array('--mysql', $argv, true)) { $suites['MySQL/MariaDB desechable'] = '/scripts/test-mysql.php'; }
foreach ($suites as $name => $file) {
    fwrite(STDOUT, "\n$name\n");
    $process = proc_open([PHP_BINARY, $root . $file], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('No se pudo iniciar la prueba ' . $name); }
    $code = proc_close($process);
    if ($code !== 0) { fwrite(STDERR, "La prueba $name falló.\n"); exit($code > 0 ? $code : 1); }
}
fwrite(STDOUT, "\nTodas las pruebas locales aprobaron. No sustituyen la verificación de MySQL, S3 y Redis reales.\n");
