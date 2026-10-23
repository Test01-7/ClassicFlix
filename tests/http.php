<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

use ClassicFlix\Database\Migrator;
use ClassicFlix\Repositories\MovieRepository;
use ClassicFlix\Repositories\ResourceRepository;
use ClassicFlix\Repositories\UserRepository;
use ClassicFlix\Support\Config;
use ClassicFlix\Support\Http;

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'ClassicFlix\\')) {
        $path = $root . '/Backend/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});
require_once $root . '/Backend/Support/helpers.php';

final class HttpTestClient
{
    public array $cookies = [];
    public function __construct(private string $base) {}

    public function request(string $method, string $route, array $query = [], array $body = []): array
    {
        $headers = [];
        $curl = curl_init($this->base . '/index.php?' . http_build_query(['r' => $route] + $query));
        if ($curl === false) { throw new RuntimeException('No se pudo iniciar cURL.'); }
        $cookie = implode('; ', array_map(static fn (string $key, string $value): string => $key . '=' . $value, array_keys($this->cookies), array_values($this->cookies)));
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 10, CURLOPT_COOKIE => $cookie, CURLOPT_PROXY => '',
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $length = strlen($line);
                if (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))][] = trim($value); }
                return $length;
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($body));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        } elseif ($method !== 'GET') { curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method); }
        $content = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($content === false) { throw new RuntimeException('HTTP de prueba: ' . $error); }
        foreach ($headers['set-cookie'] ?? [] as $setCookie) {
            [$pair] = explode(';', $setCookie, 2);
            if (str_contains($pair, '=')) { [$name, $value] = explode('=', $pair, 2); $this->cookies[$name] = $value; }
        }
        return ['status' => $status, 'body' => $content, 'headers' => $headers];
    }

    public static function token(array $response): string
    {
        if (!preg_match('/name="_token" value="([a-f0-9]{64})"/', $response['body'], $matches)) { throw new RuntimeException('La respuesta no incluye el token CSRF esperado.'); }
        return $matches[1];
    }

    public static function location(array $response): array
    {
        $location = $response['headers']['location'][0] ?? '';
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        return $query;
    }
}

$count = 0;
$assert = static function (bool $condition, string $name) use (&$count): void {
    if (!$condition) { throw new RuntimeException('HTTP FAILED: ' . $name); }
    $count++;
};
$servers = [];
$runtime = __DIR__ . '/.runtime/http-' . bin2hex(random_bytes(6));
if (!mkdir($runtime, 0700, true) && !is_dir($runtime)) { throw new RuntimeException('No se pudo crear el entorno aislado.'); }
$dbFile = $runtime . '/fixture.sqlite';
$pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new Migrator($pdo, $root))->migrate();
$users = new UserRepository($pdo);
$movies = new MovieRepository($pdo);
$resources = new ResourceRepository($pdo);
$adminId = $users->create('Admin de prueba', 'admin@example.test', password_hash('AdminPassword123', PASSWORD_DEFAULT), 'admin');
$secondId = $users->create('Otro espectador', 'other@example.test', password_hash('OtherPassword123', PASSWORD_DEFAULT));
$categories = array_column($movies->categories(), 'id', 'nombre');
$filmId = $movies->save(['titulo' => 'Fixture Ámbar', 'fechalanzamiento' => 1931, 'categoria_id' => $categories['Drama'], 'genero' => 'Drama', 'director' => 'Directora Prueba', 'sinopsis' => 'Una película ficticia usada únicamente por las pruebas.', 'duracion' => 5400, 'trailer_duracion' => 120]);
$otherFilmId = $movies->save(['titulo' => 'Fixture Niebla', 'fechalanzamiento' => 1933, 'categoria_id' => $categories['Comedia'], 'genero' => 'Comedia', 'director' => 'Director Secundario', 'sinopsis' => 'Otra película ficticia de una categoría distinta.', 'duracion' => 4800, 'trailer_duracion' => 100]);
$draftId = $movies->save(['titulo' => 'Fixture Borrador Oculto', 'fechalanzamiento' => 1930, 'categoria_id' => $categories['Drama']]);
foreach ([$filmId, $otherFilmId] as $id) {
    foreach (['portada' => 'image/png', 'trailer' => 'video/mp4', 'pelicula' => 'video/mp4'] as $type => $mime) {
        $resources->save(['pelicula_id' => $id, 'tipo' => $type, 'clave_s3' => 'classicflix/' . $id . '/' . $type . '/test', 'mime' => $mime, 'tamano' => 50, 'fuente_url' => 'https://example.test/source', 'referencia_derechos' => 'Test fixture only']);
    }
    $movies->setState($id, 'publicado');
}

$startServer = static function (string $environment) use ($root, $dbFile, $runtime, &$servers): string {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if (!$socket) { throw new RuntimeException('No se pudo reservar un puerto de prueba: ' . $errorMessage); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $env = getenv();
    $env = array_replace(is_array($env) ? $env : [], ['CLASSICFLIX_TEST_DB' => $dbFile, 'APP_ENV' => $environment, 'APP_URL' => $base,
        'APP_PREVIEW_ENABLED' => 'true', 'REDIS_ENABLED' => 'false', 'AWS_BUCKET' => '', 'AWS_EC2_METADATA_DISABLED' => 'true']);
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/Frontend', $root . '/tests/http-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $runtime . '/' . $environment . '.log', 'ab'], 2 => ['file', $runtime . '/' . $environment . '.log', 'ab']], $pipes, $root, $env);
    if (!is_resource($process)) { throw new RuntimeException('No se pudo iniciar el servidor de pruebas.'); }
    fclose($pipes[0]);
    $servers[] = $process;
    $ready = false;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, .1);
        if ($connection) { fclose($connection); $ready = true; break; }
        if (!proc_get_status($process)['running']) { break; }
        usleep(50000);
    }
    if (!$ready) { throw new RuntimeException('El servidor de pruebas no respondió. Revisa ' . $runtime); }
    return $base;
};

try {
    foreach (['https://evil.example/path', '//evil.example', '/admin/../secret', "\r\nLocation: https://evil.example", '/index.php', ['bad'], null] as $badNext) {
        $assert(Http::safeNext($badNext) === '/catalogo', 'Unsafe next rejected');
    }
    $assert(Http::safeNext('/reproducir?id=1&tipo=pelicula&untrusted=yes') === '/reproducir?id=1&tipo=pelicula', 'Safe next keeps only allowed player arguments');
    $assert(!(new Config(['APP_ENV' => 'production', 'APP_PREVIEW_ENABLED' => true]))->previewEnabled(), 'Production preview cannot be enabled');
    $base = $startServer('local');
    $guest = new HttpTestClient($base);
    $response = $guest->request('GET', '/catalogo');
    $assert($response['status'] === 200 && str_contains($response['body'], 'Fixture Ámbar') && str_contains($response['body'], 'Fixture Niebla'), 'Public catalog renders published titles');
    $assert(!str_contains($response['body'], 'Fixture Borrador Oculto'), 'Draft excluded from public catalog');
    $assert(($response['headers']['x-content-type-options'][0] ?? '') === 'nosniff' && ($response['headers']['x-frame-options'][0] ?? '') === 'DENY', 'Security headers emitted');
    $cookieHeader = implode(';', $response['headers']['set-cookie'] ?? []);
    $assert(str_contains(strtolower($cookieHeader), 'httponly') && str_contains(strtolower($cookieHeader), 'samesite=lax'), 'Session cookie HttpOnly and SameSite');
    $filtered = $guest->request('GET', '/catalogo', ['q' => 'Ámbar']);
    $assert($filtered['status'] === 200 && str_contains($filtered['body'], 'Fixture Ámbar') && !str_contains($filtered['body'], 'Fixture Niebla'), 'Title search filters catalog');
    $filtered = $guest->request('GET', '/catalogo', ['categoria' => $categories['Comedia']]);
    $assert(str_contains($filtered['body'], 'Fixture Niebla') && !str_contains($filtered['body'], 'Fixture Ámbar'), 'Category filters catalog');
    $escaped = $guest->request('GET', '/catalogo', ['q' => '"><script>alert(1)</script>']);
    $assert(!str_contains($escaped['body'], '<script>alert(1)</script>') && str_contains($escaped['body'], '&lt;script&gt;'), 'Search input safely escaped');
    $assert($guest->request('GET', '/detalle', ['id' => $filmId])['status'] === 200, 'Details are public');
    $assert($guest->request('GET', '/detalle', ['id' => $draftId])['status'] === 404, 'Draft details hidden');
    $trailer = $guest->request('GET', '/reproducir', ['id' => $filmId, 'tipo' => 'trailer']);
    $assert($trailer['status'] === 200 && str_contains($trailer['body'], 'data-history-enabled="0"'), 'Trailer page is public without anonymous history');
    $film = $guest->request('GET', '/reproducir', ['id' => $filmId, 'tipo' => 'pelicula']);
    $assert($film['status'] === 303 && (HttpTestClient::location($film)['r'] ?? '') === '/login', 'Guest full film redirects to login');
    $next = HttpTestClient::location($film)['next'] ?? '';
    $assert($next === '/reproducir?id=' . $filmId . '&tipo=pelicula', 'Film redirect preserves safe return path');
    $media = $guest->request('GET', '/media', ['id' => $filmId, 'tipo' => 'pelicula']);
    $assert($media['status'] === 401 && isset(json_decode($media['body'], true)['error']), 'Direct film signer refuses guest');
    $assert($guest->request('GET', '/media', ['id' => $filmId, 'tipo' => '../secret'])['status'] === 400, 'Signer refuses arbitrary resource kind');
    $assert($guest->request('GET', '/admin')['status'] === 303, 'Guest admin route requires login');
    $assert($guest->request('GET', '/favoritos')['status'] === 303 && $guest->request('GET', '/historial')['status'] === 303, 'Personal collections require login');
    $assert($guest->request('POST', '/upload/iniciar')['status'] === 401, 'Direct upload requires authentication');
    $assert($guest->request('GET', '/logout')['status'] === 405, 'Logout cannot be triggered with GET');
    $assert($guest->request('POST', '/login', [], ['email' => 'x@example.test', 'password' => 'not-known'])['status'] === 419, 'Login requires CSRF');
    $registerPage = $guest->request('GET', '/registro');
    $csrf = HttpTestClient::token($registerPage);
    $invalid = $guest->request('POST', '/registro', [], ['_token' => $csrf, 'nombre' => 'A', 'email' => 'invalid', 'password' => 'short', 'password_confirmation' => 'different']);
    $assert($invalid['status'] === 422 && $users->count() === 2, 'Invalid registration does not persist user');
    $oldSession = $guest->cookies['classicflix_session'] ?? '';
    $password = '  ClassicPassword123  ';
    $registered = $guest->request('POST', '/registro', [], ['_token' => $csrf, 'nombre' => 'Espectadora Nueva', 'email' => 'NUEVA@example.test', 'password' => $password,
        'password_confirmation' => $password, 'rol' => 'admin', 'next' => $next]);
    $assert($registered['status'] === 303 && (HttpTestClient::location($registered)['r'] ?? '') === '/reproducir', 'Registration returns to chosen film');
    $assert(($guest->cookies['classicflix_session'] ?? '') !== $oldSession, 'Registration regenerates session ID');
    $newUser = $users->findByEmail('nueva@example.test');
    $assert($newUser !== null && $newUser['rol'] === 'usuario', 'Public registration cannot grant admin role');
    $assert($newUser['email'] === 'nueva@example.test' && password_verify($password, $newUser['contrasena']) && $newUser['contrasena'] !== $password, 'Email normalized and password hashed without trimming');
    $authenticated = $guest->request('GET', '/reproducir', ['id' => $filmId, 'tipo' => 'pelicula']);
    $assert($authenticated['status'] === 200 && str_contains($authenticated['body'], 'data-history-enabled="1"'), 'Registered user opens full-film player');
    $newCsrf = HttpTestClient::token($authenticated);
    $assert($newCsrf !== $csrf, 'Authentication rotates CSRF token');
    $assert($guest->request('GET', '/admin')['status'] === 403 && $guest->request('POST', '/admin/guardar', [], ['_token' => $newCsrf])['status'] === 403, 'Non-admin cannot read or modify admin');
    $assert($guest->request('POST', '/upload/iniciar', [], ['_token' => $newCsrf])['status'] === 403, 'Non-admin cannot request upload authorization');
    $beforeFavorite = (int) $pdo->query('SELECT COUNT(*) FROM Favoritos')->fetchColumn();
    $assert($guest->request('POST', '/favoritos', [], ['_token' => $csrf, 'pelicula_id' => $filmId])['status'] === 419, 'Stale CSRF cannot mutate favorite');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM Favoritos')->fetchColumn() === $beforeFavorite, 'Failed CSRF does not persist favorite');
    $added = $guest->request('POST', '/favoritos', [], ['_token' => $newCsrf, 'pelicula_id' => $filmId, 'return' => '/favoritos']);
    $assert($added['status'] === 303 && $movies->isFavorite((int) $newUser['id'], $filmId), 'Favorite added for current user');
    $assert(!$movies->isFavorite($secondId, $filmId), 'Favorite does not affect another account');
    $favorites = $guest->request('GET', '/favoritos');
    $assert(str_contains($favorites['body'], 'Fixture Ámbar') && !str_contains($favorites['body'], 'Fixture Niebla'), 'Personal favorite listing is filtered');
    $guest->request('POST', '/favoritos', [], ['_token' => $newCsrf, 'pelicula_id' => $filmId]);
    $assert(!$movies->isFavorite((int) $newUser['id'], $filmId), 'Favorite can be removed');
    preg_match('/data-history-token="([a-f0-9]{64})"/', $authenticated['body'], $tokenMatch);
    $historyData = ['_token' => $newCsrf, 'pelicula_id' => $filmId, 'tipo' => 'pelicula', 'playback_token' => $tokenMatch[1] ?? ''];
    $assert($guest->request('POST', '/historial/registrar', [], $historyData)['status'] === 200, 'Authorized playback records history');
    $assert($guest->request('POST', '/historial/registrar', [], $historyData)['status'] === 200, 'Repeated playing event accepted idempotently');
    $assert(count($movies->history((int) $newUser['id'])) === 1 && count($movies->history($secondId)) === 0, 'History deduplicated and isolated per user');
    $historyData['pelicula_id'] = $otherFilmId;
    $assert($guest->request('POST', '/historial/registrar', [], $historyData)['status'] === 403, 'History token cannot be used for another film');
    $historyPage = $guest->request('GET', '/historial');
    $assert(str_contains($historyPage['body'], 'Fixture Ámbar') && !str_contains($historyPage['body'], 'Fixture Niebla'), 'History page shows only current user playback');
    $loggedSession = $guest->cookies['classicflix_session'] ?? '';
    $logout = $guest->request('POST', '/logout', [], ['_token' => $newCsrf]);
    $assert($logout['status'] === 303 && ($guest->cookies['classicflix_session'] ?? '') !== $loggedSession, 'Logout clears authentication and regenerates session');
    $assert($guest->request('GET', '/reproducir', ['id' => $filmId, 'tipo' => 'pelicula'])['status'] === 303, 'Full film protected again after logout');
    $loginPage = $guest->request('GET', '/login', ['next' => 'https://evil.example']);
    $assert(str_contains($loginPage['body'], 'name="next" value="/catalogo"'), 'External return URL removed from login form');
    $csrf = HttpTestClient::token($loginPage);
    $assert($guest->request('POST', '/login', [], ['_token' => $csrf, 'email' => 'nueva@example.test', 'password' => 'incorrect'])['status'] === 422, 'Incorrect password rejected');
    $login = $guest->request('POST', '/login', [], ['_token' => $csrf, 'email' => 'NUEVA@example.test', 'password' => $password, 'next' => $next]);
    $assert($login['status'] === 303 && (HttpTestClient::location($login)['id'] ?? '') === (string) $filmId, 'Login returns to original film');
    $dupe = new HttpTestClient($base);
    $dupePage = $dupe->request('GET', '/registro');
    $duplicate = $dupe->request('POST', '/registro', [], ['_token' => HttpTestClient::token($dupePage), 'nombre' => 'Duplicada', 'email' => 'nueva@example.test', 'password' => 'AnotherSecret123', 'password_confirmation' => 'AnotherSecret123']);
    $assert($duplicate['status'] === 422 && $users->count() === 3, 'Duplicate email rejected without extra account');
    $admin = new HttpTestClient($base);
    $adminPage = $admin->request('GET', '/login');
    $adminLogin = $admin->request('POST', '/login', [], ['_token' => HttpTestClient::token($adminPage), 'email' => 'admin@example.test', 'password' => 'AdminPassword123', 'next' => '/admin']);
    $assert($adminLogin['status'] === 303, 'Admin can authenticate');
    $adminPanel = $admin->request('GET', '/admin');
    $assert($adminPanel['status'] === 200 && str_contains($adminPanel['body'], 'Fixture Borrador Oculto'), 'Admin sees real published and draft content');
    $adminCsrf = HttpTestClient::token($adminPanel);
    $adminData = ['_token' => $adminCsrf, 'titulo' => 'Fixture Admin Nueva', 'fechalanzamiento' => '1935', 'categoria_id' => (string) $categories['Drama'], 'sinopsis' => 'Una nueva sinopsis válida para crear un borrador.', 'duracion' => '5400', 'trailer_duracion' => '120', 'estado' => 'publicado'];
    $assert($admin->request('POST', '/admin/guardar', [], $adminData)['status'] === 422, 'Publication requires existing trailer and cover');
    $adminData['estado'] = 'borrador';
    $saved = $admin->request('POST', '/admin/guardar', [], $adminData);
    $assert($saved['status'] === 303 && (HttpTestClient::location($saved)['r'] ?? '') === '/admin/editar', 'Admin creates draft then reaches asset editor');
    $savedId = (int) (HttpTestClient::location($saved)['id'] ?? 0);
    $assert($movies->find($savedId, false) !== null && $movies->find($savedId) === null, 'New draft remains absent from public catalog');
    $assert($admin->request('GET', '/admin/editar', ['id' => $savedId])['status'] === 200, 'Admin asset editor renders saved draft');
    $assert($admin->request('POST', '/admin/guardar', [], ['id' => $savedId, 'titulo' => 'Tampered'])['status'] === 419, 'Admin modifications require CSRF');
    foreach (['login', 'registro', 'catalogo', 'detalle', 'reproducir', 'admin', 'favoritos', 'historial'] as $screen) {
        $preview = $dupe->request('GET', '/preview', ['screen' => $screen]);
        $assert($preview['status'] === 200 && str_contains($preview['body'], 'Vista de muestra'), 'Preview screen renders: ' . $screen);
        if (in_array($screen, ['login', 'registro', 'admin'], true)) { $assert(str_contains($preview['body'], '<fieldset disabled'), 'Preview forms disabled: ' . $screen); }
        if ($screen === 'reproducir') { $assert(!str_contains($preview['body'], 'data-player '), 'Preview player makes no media requests'); }
    }
    $before = [$users->count(), $movies->stats()['peliculas']];
    $assert($dupe->request('POST', '/preview', ['screen' => 'registro'], ['nombre' => 'Unsafe preview'])['status'] === 405, 'Preview rejects POST');
    $assert($before === [$users->count(), $movies->stats()['peliculas']], 'Preview mutation did not change authoritative data');
    $notFound = $dupe->request('GET', '/missing-page');
    $assert($notFound['status'] === 404 && str_contains($notFound['body'], 'Ver el diseño de muestra'), 'Local error links to enabled design preview');
    $productionBase = $startServer('production');
    $production = new HttpTestClient($productionBase);
    $previewDenied = $production->request('GET', '/preview');
    $assert($previewDenied['status'] === 404 && !str_contains($previewDenied['body'], 'Datos ficticios') && !str_contains($previewDenied['body'], 'Ver el diseño de muestra'), 'Production preview is inaccessible even with enabled flag');
    fwrite(STDOUT, "Aplicación HTTP: $count comprobaciones aprobadas con SQLite aislado y sesiones reales; sin conexiones AWS/Redis/MySQL.\n");
} finally {
    foreach ($servers as $process) {
        if (is_resource($process)) {
            if (proc_get_status($process)['running']) { proc_terminate($process); }
            proc_close($process);
        }
    }
    // Keep ignored logs/fixture for failure diagnosis; this harness never deletes user data.
}
