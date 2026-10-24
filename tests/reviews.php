<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'ClassicFlix\\')) { require_once $root . '/Backend/' . str_replace('\\', '/', substr($class, 12)) . '.php'; }
});
require_once $root . '/Backend/Support/helpers.php';

use ClassicFlix\Application;
use ClassicFlix\Database\Migrator;
use ClassicFlix\Infrastructure\Cache;
use ClassicFlix\Repositories\{MovieRepository, ReviewRepository, UserRepository};
use ClassicFlix\Services\CatalogService;
use ClassicFlix\Support\{Config, Database, HttpException, ValidationException};

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) { throw new RuntimeException('REVIEWS FAILED: ' . $message); }
    $checks++;
};
$reject = static function (int $status, callable $call, string $message) use ($assert): void {
    try { $call(); } catch (HttpException $e) { $assert($e->status === $status, $message); return; }
    throw new RuntimeException('REVIEWS FAILED: accepted ' . $message);
};
$database = static function (): PDO {
    return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
};
$pdo = $database();
(new Migrator($pdo, $root))->migrate();
$users = new UserRepository($pdo); $movies = new MovieRepository($pdo); $reviews = new ReviewRepository($pdo);
$u1 = $users->create('Primera espectadora', 'one@example.test', 'test-only-hash');
$u2 = $users->create('Segundo espectador', 'two@example.test', 'test-only-hash');
$adminId = $users->create('Administrador', 'admin@example.test', 'test-only-hash', 'admin');
$category = (int) $movies->categories()[0]['id'];
$film = $movies->save(['titulo' => 'Cine ámbar', 'fechalanzamiento' => 1931, 'categoria_id' => $category, 'sinopsis' => 'Un relato sobre una estación.']);
$second = $movies->save(['titulo' => 'Otro cine', 'fechalanzamiento' => 1932, 'categoria_id' => $category]);
$third = $movies->save(['titulo' => 'Tercer cine', 'fechalanzamiento' => 1933, 'categoria_id' => $category]);
$unrated = $movies->save(['titulo' => 'Sin puntuación', 'fechalanzamiento' => 1934, 'categoria_id' => $category]);
$draft = $movies->save(['titulo' => 'Borrador', 'fechalanzamiento' => 1935, 'categoria_id' => $category]);
foreach ([$film, $second, $third, $unrated] as $id) { $movies->setState($id, 'publicado'); }
$pdo->exec('UPDATE Peliculas SET calificacion = 4.9');
$assert($movies->find($film)['valoracion_media'] === null && $movies->find($film)['valoraciones_total'] === 0, 'Legacy calificacion does not become a public vote');

$redis = new class {
    public array $data = []; public array $deleted = []; public bool $down = false;
    public function get(string $key): ?string { if ($this->down) { throw new RuntimeException('Offline'); } return $this->data[$key] ?? null; }
    public function setex(string $key, int $ttl, string $value): void { $this->data[$key] = $value; }
    public function del(array $keys): void { foreach ($keys as $key) { unset($this->data[$key]); $this->deleted[] = $key; } }
};
$config = new Config(['REDIS_ENABLED' => true]);
$appFor = static function (?int $id) use ($config, $pdo, $redis): Application {
    $_SESSION = $id ? ['user_id' => $id] : [];
    $app = new Application($config, new Database($config, $pdo));
    // Replace only the service boundary; production interfaces stay unchanged.
    $property = new ReflectionProperty(Application::class, 'instances');
    $instances = $property->getValue($app);
    $instances['cache'] = new Cache($config, $redis);
    $property->setValue($app, $instances);
    return $app;
};
$app = $appFor($u1);
$app->catalog()->all();
$assert(isset($redis->data[CatalogService::CACHE_KEY]), 'Catalog metadata cached');
$app->reviewService()->save($film, ['valoracion' => '5', 'resena' => 'Una historia excelente.', 'usuario_id' => $u2]);
$assert(!isset($redis->data[CatalogService::CACHE_KEY]) && in_array(CatalogService::CACHE_KEY, $redis->deleted, true), 'Saving invalidates the shared catalog key');
$assert($reviews->findOwn($u1, $film)['valoracion'] === 5 && $reviews->findOwn($u2, $film) === null, 'Session owns the review, ignoring submitted user ID');
$assert($app->catalog()->all()[3]['valoracion_media'] === 5.0, 'Catalog reloads updated mean after cached empty rating');
$assert(!str_contains($redis->data[CatalogService::CACHE_KEY], 'one@example.test') && !str_contains($redis->data[CatalogService::CACHE_KEY], 'Una historia excelente.'), 'Cache excludes author emails and review text');
$appFor($u2)->reviewService()->save($film, ['valoracion' => 3, 'resena' => 'Otra mirada a la misma película.']);
$assert($movies->find($film)['valoracion_media'] === 4.0 && $movies->find($film)['valoraciones_total'] === 2, 'Public average and vote count');
$appFor($u1)->reviewService()->save($film, ['valoracion' => '3', 'resena' => 'Mi opinión actualizada.']);
$assert($movies->find($film)['valoracion_media'] === 3.0 && $movies->find($film)['valoraciones_total'] === 2, 'Editing replaces one vote without duplicates');
$assert($reviews->findOwn($u2, $film)['resena'] === 'Otra mirada a la misma película.', 'Editing preserves another user review');
$appFor($u2)->reviewService()->delete($film);
$assert($reviews->findOwn($u1, $film) !== null && $reviews->findOwn($u2, $film) === null, 'Deletion removes only the author opinion');
$appFor($u1)->reviewService()->delete($film);
$assert($movies->find($film)['valoracion_media'] === null && $movies->find($film)['valoraciones_total'] === 0, 'Removing last vote restores unrated state');
$reject(404, fn() => $appFor($u2)->reviewService()->delete($film), 'Cannot delete another author review');
$reject(401, fn() => $appFor(null)->reviewService()->save($film, ['valoracion' => 5, 'resena' => 'No session.']), 'Authentication required');
$reject(404, fn() => $appFor($adminId)->reviewService()->save($draft, ['valoracion' => 5, 'resena' => 'Draft.']), 'Even administrators cannot review unpublished films');
$reject(404, fn() => $appFor($u1)->reviewService()->save(99999, []), 'Unknown film rejected');
foreach (['0', '6', '4.5', '4.0', '5e0', '-1', '', ['5'], true] as $value) {
    $reject(422, fn() => $appFor($u1)->reviewService()->save($film, ['valoracion' => $value, 'resena' => 'Texto válido.']), 'Invalid rating rejected');
}
foreach (['', " \n\t", "\u{00A0}", str_repeat('á', 2001), ['bad']] as $text) {
    $reject(422, fn() => $appFor($u1)->reviewService()->save($film, ['valoracion' => '4', 'resena' => $text]), 'Invalid review text rejected');
}
$appFor($u1)->reviewService()->save($film, ['valoracion' => '4', 'resena' => str_repeat('á', 2000)]);
$assert(mb_strlen($reviews->findOwn($u1, $film)['resena']) === 2000, 'Maximum is measured in Unicode characters');
$reviews->saveOwn($u2, $film, 'Segunda opinión.', 4);
$reviews->saveOwn($u1, $second, 'Segunda película.', 4);
$reviews->saveOwn($u1, $third, 'Tercera película.', 5);
$ranked = CatalogService::filter($movies->all(), '', null, null, 'valoracion');
$assert(array_column($ranked, 'id') === [$third, $film, $second, $unrated], 'Rating sort uses mean, vote count and unrated last');
$assert(array_column(CatalogService::filter($movies->all(), 'Otro', $category, 4, 'valoracion'), 'id') === [$second], 'Search, category, minimum and order compose');
$assert(count(CatalogService::filter($movies->all(), '', null, 5)) === 1, 'Five star minimum');
$assert(!in_array($unrated, array_column(CatalogService::filter($movies->all(), '', null, 1), 'id'), true), 'Minimum excludes unrated films');
$assert(array_column(CatalogService::filter($movies->all()), 'id') === [$unrated, $third, $second, $film], 'Recent order remains the default');
foreach ([['valoracion_min' => '4.0'], ['valoracion_min' => ['4']], ['valoracion_min' => '0'], ['orden' => 'DROP TABLE'], ['orden' => ['valoracion']]] as $input) {
    $reject(400, fn() => CatalogService::options($input), 'Invalid filter option');
}
$assert(CatalogService::options(['valoracion_min' => '4', 'orden' => 'valoracion']) === ['minimum' => 4, 'order' => 'valoracion'], 'Filter options normalized');

$edge = $movies->save(['titulo' => 'Promedio al límite', 'fechalanzamiento' => 1930, 'categoria_id' => $category]);
$movies->setState($edge, 'publicado');
for ($i = 0; $i < 20; $i++) {
    $reviewer = $users->create('Público ' . $i, 'p' . $i . '@example.test', 'test-only-hash');
    $reviews->saveOwn($reviewer, $edge, 'Opinión ' . $i, $i === 0 ? 3 : 4);
    $reviews->saveOwn($reviewer, $film, 'Reseña pública ' . $i, 4);
}
$assert($movies->find($edge)['valoracion_media'] === 4.0 && in_array($edge, array_column(CatalogService::filter($movies->all(), '', null, 4), 'id'), true), 'Threshold uses the displayed rounded average (3.95 → 4.0)');
$pages = [$reviews->paginate($film), $reviews->paginate($film, 2), $reviews->paginate($film, 3)];
$assert(array_map(static fn(array $p): int => count($p['items']), $pages) === [10, 10, 2], 'Review pages are limited to ten');
$assert(count(array_unique(array_merge(...array_map(static fn(array $p): array => array_column($p['items'], 'id'), $pages)))) === 22, 'Pagination neither duplicates nor skips tied timestamps');
$assert(!array_key_exists('email', $pages[0]['items'][0]) && !array_key_exists('usuario_id', $pages[0]['items'][0]), 'Public query selects only public author data');
$assert($reviews->paginate($film, PHP_INT_MAX)['page'] === 3, 'Huge page requests bounded before offset calculation');
$favoritesToken = str_repeat('f', 64);
$movies->toggleFavorite($u1, $film); $movies->recordView($u1, $film, $favoritesToken);
$assert($movies->favorites($u1)[0]['valoracion_media'] === $movies->find($film)['valoracion_media'] && $movies->history($u1)[0]['valoraciones_total'] === 22, 'Details, favorites and history share aggregates');
$redis->down = true;
$assert(count($appFor($u1)->catalog()->all()) === 5, 'Redis outage falls back to database with ratings');

$redis->down = false;
$moderation = $reviews->moderationPage();
$assert(count($moderation['items']) === 10 && $reviews->moderationPage(2)['page'] === 2, 'Moderation listing is paginated across films');
$assert(isset($moderation['items'][0]['pelicula']) && !isset($moderation['items'][0]['email']), 'Moderation shows movie and public author without email');
$target = (int) $reviews->findOwn($u1, $third)['id'];
$reject(401, fn() => $appFor(null)->reviewService()->moderate($target), 'Moderation requires session');
$reject(403, fn() => $appFor($u2)->reviewService()->moderate($target), 'Regular user cannot moderate another review');
$assert($reviews->findOwn($u1, $third) !== null, 'Denied moderation preserves review');
$adminApp = $appFor($adminId);
$adminApp->catalog()->all();
$adminApp->reviewService()->moderate($target);
$assert($reviews->findOwn($u1, $third) === null && $movies->find($third)['valoracion_media'] === null, 'Admin removes another author review and its last vote');
$assert(!isset($redis->data[CatalogService::CACHE_KEY]), 'Moderation invalidates catalog cache');
$reject(404, fn() => $appFor($adminId)->reviewService()->moderate($target), 'Already removed review returns not found');
$movies->setState($second, 'borrador');
$appFor($adminId)->reviewService()->moderate((int) $reviews->findOwn($u1, $second)['id']);
$assert($reviews->findOwn($u1, $second) === null, 'Admin can moderate a previously published movie now in draft');

$assert($reviews->moderationPage(1, 'Segunda opinión.')['total'] === 1, 'Moderation searches review text');
$assert($reviews->moderationPage(1, 'Público 19')['total'] === 2, 'Moderation searches author name across films');
$assert($reviews->moderationPage(1, '%')['total'] === 0 && $reviews->moderationPage(1, '_')['total'] === 0, 'Moderation treats SQL wildcard characters literally');
$assert($movies->adminPage(1, 'Cine ámbar')['total'] === 1 && $movies->adminPage(1, '%')['total'] === 0, 'Movie admin search matches titles literally');
$assert($movies->adminPage(1, 'Borrador')['items'][0]['estado'] === 'borrador', 'Movie admin search includes drafts');
for ($i = 0; $i < 22; $i++) { $movies->save(['titulo' => 'Página de prueba ' . $i, 'fechalanzamiento' => 1930, 'categoria_id' => $category]); }
$assert(count($movies->adminPage(1, 'Página de prueba')['items']) === 20 && count($movies->adminPage(2, 'Página de prueba')['items']) === 2, 'Movie admin pages limit results and keep search');
$assert($movies->adminPage(PHP_INT_MAX, 'Página de prueba')['page'] === 2 && $movies->adminPage(1, 'No existe')['total'] === 0, 'Movie admin handles empty and out of range pages');

$legacy = $database();
(new Migrator($legacy, $root))->migrate();
$legacy->exec('DROP TABLE Resenas');
$legacy->exec('CREATE TABLE Resenas(id INTEGER PRIMARY KEY,usuario_id INTEGER NOT NULL,pelicula_id INTEGER NOT NULL,resena TEXT NOT NULL,fecha TEXT)');
$legacy->exec("INSERT INTO Resenas VALUES(1,1,1,'Reseña anterior conservada','2020-02-03 12:00:00')");
(new Migrator($legacy, $root))->migrate(); (new Migrator($legacy, $root))->migrate();
$old = $legacy->query('SELECT * FROM Resenas')->fetch();
$assert($old['resena'] === 'Reseña anterior conservada' && $old['fecha'] === '2020-02-03 12:00:00' && $old['valoracion'] === null && $old['actualizado_en'] === null, 'Repeat migration preserves legacy text and dates without inventing a vote');
$duplicate = $database();
$duplicate->exec('CREATE TABLE Resenas(id INTEGER PRIMARY KEY,usuario_id INTEGER,pelicula_id INTEGER,resena TEXT,fecha TEXT)');
$duplicate->exec("INSERT INTO Resenas VALUES(1,1,1,'Primera','2020-01-01'),(2,1,1,'Segunda','2020-01-02')");
try { (new Migrator($duplicate, $root))->migrate(); throw new LogicException('Duplicate migration accepted'); }
catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'reseñas duplicadas'), 'Legacy duplicate diagnostic');
}
$assert((int) $duplicate->query('SELECT COUNT(*) FROM Resenas')->fetchColumn() === 2 && count($duplicate->query('PRAGMA table_info(Resenas)')->fetchAll()) === 5, 'Duplicate preflight preserves all data and schema');
fwrite(STDOUT, "Reseñas y valoraciones: $checks comprobaciones aprobadas con SQLite y doble de caché.\n");
