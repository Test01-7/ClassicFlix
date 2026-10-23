<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$app = require $root . '/Backend/bootstrap.php';

use ClassicFlix\Database\Migrator;

function readValue(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Falta un valor en la entrada estándar.');
    }
    return trim($value);
}

function readPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    // A terminal must not echo passwords; piped stdin is supported for automation.
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
        if (PHP_OS_FAMILY === 'Windows') {
            $script = '$secure = Read-Host -AsSecureString; $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure); try { [Console]::Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)) } finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }';
            $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
            $value = shell_exec('powershell.exe -NoProfile -EncodedCommand ' . escapeshellarg($encoded));
            fwrite(STDOUT, PHP_EOL);
            if ($value === null) {
                throw new RuntimeException('No fue posible leer la contraseña de forma oculta. Utiliza entrada estándar desde un gestor de secretos.');
            }
            return rtrim($value, "\r\n");
        }
        $mode = shell_exec('stty -g');
        if ($mode === null) {
            throw new RuntimeException('No fue posible ocultar la contraseña. Utiliza entrada estándar.');
        }
        shell_exec('stty -echo');
        try {
            $value = fgets(STDIN);
        } finally {
            shell_exec('stty ' . escapeshellarg(trim($mode)));
            fwrite(STDOUT, PHP_EOL);
        }
        return rtrim((string) $value, "\r\n");
    }
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Falta la contraseña en la entrada estándar.');
    }
    return rtrim($value, "\r\n");
}

try {
    $command = $argv[1] ?? 'help';
    switch ($command) {
        case 'install':
            $config = $app->config();
            $database = (string) $config->get('DB_DATABASE', $config->get('DB_NAME', 'ClassicFlix'));
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)) {
                throw new RuntimeException('DB_DATABASE debe contener únicamente letras, números y guion bajo.');
            }
            $host = (string) $config->get('DB_HOST', '127.0.0.1');
            $port = (int) $config->get('DB_PORT', 3306);
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false];
            if ($ca = $config->get('DB_SSL_CA')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
            $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", (string) $config->get('DB_USERNAME', $config->get('DB_USER', 'root')), (string) $config->get('DB_PASSWORD', ''), $options);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            (new Migrator($app->database()->pdo(), $root))->migrate();
            fwrite(STDOUT, "Base de datos instalada. No se crearon usuarios de ejemplo.\n");
            break;
        case 'migrate':
            (new Migrator($app->database()->pdo(), $root))->migrate();
            fwrite(STDOUT, "Esquema actualizado sin eliminar registros existentes.\n");
            break;
        case 'create-admin':
            $name = readValue('Nombre: ');
            $email = mb_strtolower(readValue('Correo: '));
            $password = readPassword('Contraseña (mínimo 10 caracteres): ');
            $confirmation = readPassword('Repetir contraseña: ');
            if ($name === '' || mb_strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                throw new RuntimeException('Nombre o correo no válido.');
            }
            if (mb_strlen($password) < 10 || strlen($password) > 72 || !hash_equals($password, $confirmation)) {
                throw new RuntimeException('La contraseña debe tener entre 10 caracteres y 72 bytes y coincidir con su confirmación.');
            }
            if ($app->users()->findByEmail($email) !== null) {
                throw new RuntimeException('El correo ya está registrado. No se modificó su rol.');
            }
            $app->users()->create($name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin');
            unset($password, $confirmation);
            fwrite(STDOUT, "Administrador creado.\n");
            break;
        case 'cleanup-uploads':
            $result = $app->uploads()->cleanupExpired();
            fwrite(STDOUT, 'Limpieza terminada: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL);
            break;
        case 'check':
            $failed = false;
            foreach (['MySQL' => static fn() => $app->database()->pdo()->query('SELECT 1')->fetchColumn(), 'Redis' => static fn() => $app->cache()->ping(), 'S3' => static fn() => $app->storage()->check()] as $name => $check) {
                try {
                    $ok = $check();
                    if ($ok === false) {
                        throw new RuntimeException('No disponible.');
                    }
                    fwrite(STDOUT, "$name: disponible.\n");
                } catch (Throwable $exception) {
                    $failed = true;
                    fwrite(STDOUT, "$name: sin verificar. Comprueba configuración, servicio y permisos.\n");
                }
            }
            exit($failed ? 1 : 0);
        default:
            fwrite(STDOUT, "ClassicFlix — herramientas de consola\n\nphp scripts/console.php install          Crear DB y esquema\nphp scripts/console.php migrate          Actualizar esquema existente\nphp scripts/console.php create-admin     Crear administrador\nphp scripts/console.php check            Comprobar MySQL, Redis y S3\nphp scripts/console.php cleanup-uploads   Limpiar cargas abandonadas\n");
    }
} catch (Throwable $exception) {
    // Errors can contain PDO DSNs or AWS signed request data: keep terminal output safe.
    $safe = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'No se pudo completar la operación. Comprueba la configuración y el esquema; no se muestran credenciales.';
    fwrite(STDERR, $safe . PHP_EOL);
    exit(1);
}
