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
    // Reviews are independent of watching a video; all ownership comes from the session.
    $anonymous = new HttpTestClient($base);
    $assert($anonymous->request('POST', '/resenas/guardar', [], ['pelicula_id' => $filmId])['status'] === 401, 'Guest cannot create reviews');
    $assert($anonymous->request('POST', '/resenas/eliminar', [], ['pelicula_id' => $filmId])['status'] === 401, 'Guest cannot delete reviews');
    $assert($anonymous->request('GET', '/resenas/guardar')['status'] === 405, 'Review writes require POST');
    $assert(str_contains($anonymous->request('GET', '/detalle', ['id' => $filmId])['body'], 'Sin valoraciones'), 'No votes are shown as unrated');
    $reviewData = ['_token' => $newCsrf, 'pelicula_id' => $filmId, 'resena' => '<script>alert("review")</script> Mi reseña.', 'valoracion' => '4', 'usuario_id' => $secondId];
    $assert($guest->request('POST', '/resenas/guardar', [], array_replace($reviewData, ['_token' => 'invalid']))['status'] === 419, 'Review creation requires CSRF');
    foreach ([['valoracion' => '0'], ['valoracion' => '6'], ['valoracion' => '4.5'], ['valoracion' => ['5']], ['resena' => '  '], ['resena' => str_repeat('á', 2001)]] as $bad) {
        $invalidReview = $guest->request('POST', '/resenas/guardar', [], array_replace($reviewData, $bad));
        $assert($invalidReview['status'] === 422 && str_contains($invalidReview['body'], 'role="alert"'), 'Invalid review renders errors at detail');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM Resenas')->fetchColumn() === 0, 'Invalid requests do not persist reviews');
    $assert($guest->request('POST', '/resenas/guardar', [], array_replace($reviewData, ['pelicula_id' => $draftId]))['status'] === 404, 'Draft cannot receive reviews');
    $savedReview = $guest->request('POST', '/resenas/guardar', [], $reviewData);
    $assert($savedReview['status'] === 303 && (HttpTestClient::location($savedReview)['r'] ?? '') === '/detalle', 'Review redirects back to film');
    $ownerId = (int) $pdo->query('SELECT usuario_id FROM Resenas')->fetchColumn();
    $assert($ownerId === (int) $newUser['id'], 'Submitted author ID cannot impersonate another user');
    $assert(count($movies->history((int) $newUser['id'])) === 0, 'A review can be posted without playback history');
    $publicReviews = $anonymous->request('GET', '/detalle', ['id' => $filmId]);
    $assert(str_contains($publicReviews['body'], 'Espectadora Nueva') && str_contains($publicReviews['body'], '&lt;script&gt;') && !str_contains($publicReviews['body'], '<script>alert("review")'), 'Public reviews escape HTML and show author name');
    $assert(!str_contains($publicReviews['body'], 'nueva@example.test') && !str_contains($publicReviews['body'], 'other@example.test'), 'Public responses do not expose author email');
    $assert(str_contains($publicReviews['body'], 'next=%2Fdetalle%3Fid%3D' . $filmId), 'Guest review CTA returns to the film after login');
    $ownDetail = $guest->request('GET', '/detalle', ['id' => $filmId]);
    $assert(str_contains($ownDetail['body'], 'Actualizar mi opinión') && str_contains($ownDetail['body'], 'Eliminar mi reseña'), 'Author can edit and delete own opinion');
    $other = new HttpTestClient($base);
    $otherLogin = $other->request('GET', '/login');
    $other->request('POST', '/login', [], ['_token' => HttpTestClient::token($otherLogin), 'email' => 'other@example.test', 'password' => 'OtherPassword123']);
    $otherCsrf = HttpTestClient::token($other->request('GET', '/detalle', ['id' => $filmId]));
    $otherData = ['_token' => $otherCsrf, 'pelicula_id' => $filmId, 'valoracion' => '2', 'resena' => 'Una segunda opinión.', 'usuario_id' => $newUser['id'], 'id' => 1];
    $assert($other->request('POST', '/resenas/guardar', [], $otherData)['status'] === 303 && (int) $pdo->query('SELECT COUNT(*) FROM Resenas')->fetchColumn() === 2, 'Second user creates own vote instead of editing submitted review ID');
    $assert($movies->find($filmId)['valoracion_media'] === 3.0 && $movies->find($filmId)['valoraciones_total'] === 2, 'Mean is computed from public votes');
    $assert($other->request('POST', '/resenas/eliminar', [], $otherData)['status'] === 303 && (int) $pdo->query('SELECT usuario_id FROM Resenas')->fetchColumn() === (int) $newUser['id'], 'Forged deletion removes only current user review');
    $assert($other->request('POST', '/resenas/eliminar', [], $otherData)['status'] === 404, 'User cannot delete remaining opinion of another author');
    $assert($guest->request('POST', '/resenas/eliminar', [], array_replace($reviewData, ['_token' => 'invalid']))['status'] === 419, 'Deleting a review requires CSRF');
    $guest->request('POST', '/resenas/guardar', [], array_replace($reviewData, ['valoracion' => '5']));
    $other->request('POST', '/resenas/guardar', [], array_replace($otherData, ['valoracion' => '5']));
    $other->request('POST', '/resenas/guardar', [], array_replace($otherData, ['pelicula_id' => $otherFilmId, 'valoracion' => '4']));
    $assert((int) $pdo->query('SELECT COUNT(*) FROM Resenas')->fetchColumn() === 3, 'Repeated save updates one opinion per film and user');
    $best = $anonymous->request('GET', '/catalogo', ['orden' => 'valoracion']);
    $assert($best['status'] === 200 && strpos($best['body'], 'Fixture Ámbar') < strpos($best['body'], 'Fixture Niebla'), 'Public catalog can sort by highest average');
    $high = $anonymous->request('GET', '/catalogo', ['valoracion_min' => '5']);
    $assert(str_contains($high['body'], 'Fixture Ámbar') && !str_contains($high['body'], 'Fixture Niebla'), 'Rating minimum excludes lower-rated film');
    $combined = $anonymous->request('GET', '/catalogo', ['q' => 'Fixture', 'categoria' => $categories['Comedia'], 'valoracion_min' => '4', 'orden' => 'valoracion']);
    $assert(str_contains($combined['body'], 'Fixture Niebla') && !str_contains($combined['body'], 'Fixture Ámbar'), 'Minimum, category, query and rating order compose over HTTP');
    $assert(str_contains($combined['body'], 'valoracion_min=4&amp;orden=valoracion'), 'Category tabs preserve rating and order');
    foreach ([['valoracion_min' => '6'], ['valoracion_min' => ['4']], ['orden' => 'sql-injection']] as $bad) {
        $assert($anonymous->request('GET', '/catalogo', $bad)['status'] === 400, 'Invalid rating filter rejected');
    }
    $guest->request('POST', '/resenas/eliminar', [], $reviewData);
    $other->request('POST', '/resenas/eliminar', [], $otherData);
    $assert($movies->find($filmId)['valoracion_media'] === null && $movies->find($filmId)['valoraciones_total'] === 0, 'Removing all votes clears aggregate');
    $emptyRating = $anonymous->request('GET', '/catalogo', ['valoracion_min' => '5']);
    $assert(str_contains($emptyRating['body'], 'Prueba con otra búsqueda, categoría o valoración mínima.') && !str_contains($emptyRating['body'], 'El catálogo aparecerá aquí'), 'Empty rating filter explains no matching results rather than an unconfigured catalog');
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
    $overview = $admin->request('GET', '/admin');
    $assert($overview['status'] === 200 && !str_contains($overview['body'], '<table') && !str_contains($overview['body'], 'id="contenido-form"'), 'Overview only shows summary and links');
    foreach (['/admin/peliculas', '/admin/resenas', '/admin/nuevo', '/admin/archivos'] as $route) {
        $assert($other->request('GET', $route)['status'] === 403 && $anonymous->request('GET', $route)['status'] === 303, 'Every admin page is protected');
    }
    $adminPanel = $admin->request('GET', '/admin/peliculas');
    $assert($adminPanel['status'] === 200 && str_contains($adminPanel['body'], 'Fixture Borrador Oculto'), 'Admin sees real published and draft content');
    $adminCsrf = HttpTestClient::token($adminPanel);
    $adminData = ['_token' => $adminCsrf, 'titulo' => 'Fixture Admin Nueva', 'fechalanzamiento' => '1935', 'categoria_id' => (string) $categories['Drama'], 'sinopsis' => '', 'duracion' => '5400', 'trailer_duracion' => '', 'estado' => 'publicado'];
    $assert($admin->request('POST', '/admin/guardar', [], $adminData)['status'] === 422, 'Publication requires a cover and at least one video');
    $adminData['estado'] = 'borrador';
    $saved = $admin->request('POST', '/admin/guardar', [], $adminData);
    $assert($saved['status'] === 303 && (HttpTestClient::location($saved)['r'] ?? '') === '/admin/editar', 'Admin creates draft then reaches asset editor');
    $savedId = (int) (HttpTestClient::location($saved)['id'] ?? 0);
    $assert($movies->find($savedId, false) !== null && $movies->find($savedId) === null, 'New draft remains absent from public catalog');
    $assert($movies->find($savedId, false)['sinopsis'] === '' && (int) $movies->find($savedId, false)['trailer_duracion'] === 0, 'Empty synopsis and trailer duration persist without invented metadata');
    $editData = $adminData + ['id' => (string) $savedId];
    $assert($admin->request('POST', '/admin/guardar', [], array_replace($editData, ['sinopsis' => str_repeat('a', 10001)]))['status'] === 422, 'Optional synopsis still enforces its maximum length');
    foreach (['-1', '1.5', '86401'] as $invalidDuration) {
        $assert($admin->request('POST', '/admin/guardar', [], array_replace($editData, ['trailer_duracion' => $invalidDuration]))['status'] === 422, 'Optional trailer duration rejects ' . $invalidDuration);
    }
    $assert($admin->request('GET', '/admin/editar', ['id' => $savedId])['status'] === 200, 'Admin asset editor renders saved draft');
    $filteredMovies = $admin->request('GET', '/admin/peliculas', ['q' => 'Niebla']);
    $assert(str_contains($filteredMovies['body'], 'Fixture Niebla') && !str_contains($filteredMovies['body'], 'Fixture Ámbar') && !str_contains($filteredMovies['body'], 'id="contenido-form"'), 'Movie page searches titles without rendering editor');
    $editorPage = $admin->request('GET', '/admin/editar', ['id' => $savedId, 'q' => 'Nueva', 'pagina' => 2]);
    $assert(str_contains($editorPage['body'], 'q=Nueva&amp;pagina=2') && !str_contains($editorPage['body'], 'data-upload-form'), 'Editor preserves list context and separates uploads');
    foreach (['sinopsis', 'trailer_duracion'] as $optionalField) {
        preg_match('~<(?:input|textarea)\b[^>]*\bname="' . $optionalField . '"[^>]*>~', $editorPage['body'], $tag);
        $assert(isset($tag[0]) && !preg_match('/\brequired\b/', $tag[0]), 'Native editor field is optional: ' . $optionalField);
    }
    $filesPage = $admin->request('GET', '/admin/archivos', ['id' => $savedId]);
    foreach (['fuente_url', 'referencia_derechos'] as $optionalField) {
        preg_match_all('~<(?:input|textarea)\b[^>]*\bname="' . $optionalField . '"[^>]*>~', $filesPage['body'], $tags);
        $assert(count($tags[0]) === 3 && !preg_match('/\brequired\b/', implode('', $tags[0])), 'Source metadata optional in all three upload forms: ' . $optionalField);
    }
    $resources->save(['pelicula_id' => $savedId, 'tipo' => 'pelicula', 'clave_s3' => "classicflix/$savedId/pelicula/fixture.mp4", 'mime' => 'video/mp4', 'tamano' => 100]);
    $publishData = array_replace($editData, ['estado' => 'publicado']);
    $assert($admin->request('POST', '/admin/guardar', [], $publishData)['status'] === 422, 'Film alone cannot publish without a cover');
    $resources->save(['pelicula_id' => $savedId, 'tipo' => 'portada', 'clave_s3' => "classicflix/$savedId/portada/fixture.jpg", 'mime' => 'image/jpeg', 'tamano' => 100]);
    $assert($admin->request('POST', '/admin/guardar', [], $publishData)['status'] === 303, 'Cover and full film publish without a trailer or synopsis');
    $publicWithoutTrailer = $anonymous->request('GET', '/detalle', ['id' => $savedId]);
    $assert($publicWithoutTrailer['status'] === 200 && str_contains($publicWithoutTrailer['body'], 'Sinopsis no disponible.') && str_contains($publicWithoutTrailer['body'], 'Ver película') && !str_contains($publicWithoutTrailer['body'], 'Ver tráiler'), 'Public details show only available playback and handle missing synopsis');
    $assert($anonymous->request('GET', '/reproducir', ['id' => $savedId, 'tipo' => 'pelicula'])['status'] === 303 && $anonymous->request('GET', '/media', ['id' => $savedId, 'tipo' => 'pelicula'])['status'] === 401, 'Trailer-free full film still requires authentication');
    $assert($other->request('GET', '/reproducir', ['id' => $savedId, 'tipo' => 'pelicula'])['status'] === 200 && $anonymous->request('GET', '/reproducir', ['id' => $savedId, 'tipo' => 'trailer'])['status'] === 404, 'Members can open the film player and absent trailer is unavailable');
    $assert($admin->request('POST', '/admin/guardar', [], array_replace($publishData, ['sinopsis' => '<b>Hola</b>']))['status'] === 303 && str_contains($anonymous->request('GET', '/detalle', ['id' => $savedId])['body'], '&lt;b&gt;Hola&lt;/b&gt;'), 'Short optional synopsis is accepted and remains escaped');
    $assert(str_contains($admin->request('GET', '/admin/archivos', ['id' => $savedId])['body'], 'data-upload-form'), 'Files have a dedicated page');
    $assert(str_contains($admin->request('GET', '/admin/nuevo')['body'], 'id="contenido-form"'), 'New content has a dedicated page');
    $assert((HttpTestClient::location($admin->request('GET', '/admin', ['id' => $savedId]))['r'] ?? '') === '/admin/editar', 'Legacy admin movie link redirects to editor');
    $assert($admin->request('GET', '/admin/peliculas', ['q' => ['bad']])['status'] === 400 && $admin->request('GET', '/admin/resenas', ['q' => str_repeat('x', 121)])['status'] === 400, 'Admin searches reject invalid inputs');
    $assert($admin->request('POST', '/admin/guardar', [], ['id' => $savedId, 'titulo' => 'Tampered'])['status'] === 419, 'Admin modifications require CSRF');
    $publicReviewRepo = new ClassicFlix\Repositories\ReviewRepository($pdo);
    for ($i = 0; $i < 11; $i++) {
        $reviewer = $users->create('Lector ' . $i, 'reader' . $i . '@example.test', 'unused-test-hash');
        $publicReviewRepo->saveOwn($reviewer, $filmId, 'Opinión numerada ' . $i, 4);
    }
    $firstReviewPage = $anonymous->request('GET', '/detalle', ['id' => $filmId]);
    $secondReviewPage = $anonymous->request('GET', '/detalle', ['id' => $filmId, 'resenas_pagina' => '2']);
    $assert(substr_count($firstReviewPage['body'], 'class="review-entry"') === 10 && substr_count($secondReviewPage['body'], 'class="review-entry"') === 1, 'HTTP pagination displays ten reviews per page');
    $assert(str_contains($firstReviewPage['body'], 'resenas_pagina=2#resenas') && str_contains($secondReviewPage['body'], 'Página 2 de 2'), 'Review pagination links and state');
    $assert(!str_contains($secondReviewPage['body'], '@example.test'), 'Paginated reviews do not expose emails');
    $assert($anonymous->request('GET', '/detalle', ['id' => $filmId, 'resenas_pagina' => ['2']])['status'] === 400, 'Invalid review page rejected');
    $moderatedId = (int) $publicReviewRepo->moderationPage()['items'][0]['id'];
    $moderationData = ['_token' => $adminCsrf, 'resena_id' => $moderatedId, 'resenas_pagina' => 2, 'q' => 'Opinión'];
    $assert($anonymous->request('POST', '/admin/resenas/eliminar', [], $moderationData)['status'] === 401, 'Guest cannot moderate reviews');
    $assert($other->request('POST', '/admin/resenas/eliminar', [], array_replace($moderationData, ['_token' => $otherCsrf]))['status'] === 403, 'Regular account cannot moderate by direct request');
    $assert($admin->request('GET', '/admin/resenas/eliminar')['status'] === 405, 'Moderation requires POST');
    $assert($admin->request('POST', '/admin/resenas/eliminar', [], array_replace($moderationData, ['_token' => 'invalid']))['status'] === 419, 'Moderation requires CSRF');
    $assert($admin->request('POST', '/admin/resenas/eliminar', [], array_replace($moderationData, ['resena_id' => ['1']]))['status'] === 400, 'Invalid moderation identifier rejected');
    $moderationPage = $admin->request('GET', '/admin/resenas', ['resenas_pagina' => 2]);
    $assert($moderationPage['status'] === 200 && substr_count($moderationPage['body'], 'class="moderation-row"') === 2 && str_contains($moderationPage['body'], 'Confirmar eliminación') && !str_contains($moderationPage['body'], 'id="contenido-form"'), 'Review table is paginated on its own page with confirmation');
    $byAuthor = $admin->request('GET', '/admin/resenas', ['q' => 'Lector 10']);
    $assert(substr_count($byAuthor['body'], 'class="moderation-row"') === 1 && str_contains($byAuthor['body'], 'Opinión numerada 10'), 'Moderation searches public author names');
    $byWord = $admin->request('GET', '/admin/resenas', ['q' => 'numerada']);
    $assert(substr_count($byWord['body'], 'class="moderation-row"') === 10 && str_contains($byWord['body'], 'q=numerada&amp;resenas_pagina=2'), 'Moderation text search survives pagination');
    $assert(!str_contains($byWord['body'], '@example.test'), 'Moderation does not expose email addresses');
    $assert($admin->request('GET', '/admin/resenas', ['resenas_pagina' => ['1']])['status'] === 400, 'Invalid moderation page rejected');
    $beforeModeration = $movies->find($filmId)['valoraciones_total'];
    $moderated = $admin->request('POST', '/admin/resenas/eliminar', [], $moderationData);
    $assert($moderated['status'] === 303 && (HttpTestClient::location($moderated)['r'] ?? '') === '/admin/resenas' && (HttpTestClient::location($moderated)['resenas_pagina'] ?? '') === '2' && (HttpTestClient::location($moderated)['q'] ?? '') === 'Opinión', 'Admin deletion preserves search and moderation page');
    $assert($movies->find($filmId)['valoraciones_total'] === $beforeModeration - 1, 'Admin deletion removes its vote from public aggregate');
    $assert($admin->request('POST', '/admin/resenas/eliminar', [], $moderationData)['status'] === 404, 'Moderation cannot delete nonexistent review');
    $lastModerationPage = $admin->request('GET', '/admin/resenas', ['resenas_pagina' => 2]);
    $assert(str_contains($lastModerationPage['body'], 'Página 2 de 2'), 'Pagination remains usable after deletion');
    foreach (['login', 'registro', 'catalogo', 'detalle', 'reproducir', 'admin', 'favoritos', 'historial'] as $screen) {
        $preview = $dupe->request('GET', '/preview', ['screen' => $screen]);
        $assert($preview['status'] === 200 && str_contains($preview['body'], 'Vista de muestra'), 'Preview screen renders: ' . $screen);
        if (in_array($screen, ['login', 'registro'], true)) { $assert(str_contains($preview['body'], '<fieldset disabled'), 'Preview forms disabled: ' . $screen); }
        if ($screen === 'reproducir') { $assert(!str_contains($preview['body'], 'data-player '), 'Preview player makes no media requests'); }
    }
    foreach (['resumen', 'peliculas', 'nuevo', 'editar', 'archivos', 'resenas'] as $panel) {
        $sample = $dupe->request('GET', '/preview', ['screen' => 'admin', 'panel' => $panel]);
        $assert($sample['status'] === 200 && str_contains($sample['body'], 'Vista de muestra'), 'Preview supports independent admin page: ' . $panel);
        if (in_array($panel, ['nuevo', 'editar', 'archivos', 'resenas'], true)) { $assert(str_contains($sample['body'], '<fieldset disabled'), 'Admin preview writes remain disabled: ' . $panel); }
    }
    $sampleSearch = $dupe->request('GET', '/preview', ['screen' => 'admin', 'panel' => 'resenas', 'q' => 'Lucía']);
    $assert(substr_count($sampleSearch['body'], 'class="moderation-row"') === 5 && !str_contains($sampleSearch['body'], 'Andrés Robles'), 'Preview moderation applies author search');
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
