<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// These tests never connect to configured MySQL, AWS or Redis.
$root = dirname(__DIR__);
spl_autoload_register(static function(string $class) use ($root): void {
    if (str_starts_with($class, 'ClassicFlix\\')) {
        require_once $root . '/Backend/' . str_replace('\\', '/', substr($class, 12)) . '.php';
    }
});

use ClassicFlix\Database\Migrator;
use ClassicFlix\Repositories\UserRepository;
use ClassicFlix\Repositories\MovieRepository;
use ClassicFlix\Repositories\ResourceRepository;
use ClassicFlix\Repositories\UploadRepository;

$assertions = 0;
function check(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) { throw new RuntimeException($message); }
    $assertions++;
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$migrator = new Migrator($pdo, $root);
$migrator->migrate();
$users = new UserRepository($pdo);
$movies = new MovieRepository($pdo);
$resources = new ResourceRepository($pdo);
$uploads = new UploadRepository($pdo);
$alice = $users->create('Alice', 'ALICE@example.test', password_hash('LongPassword123', PASSWORD_DEFAULT));
$bob = $users->create('Bob', 'bob@example.test', password_hash('LongPassword456', PASSWORD_DEFAULT));
check($users->findByEmail('alice@example.test')['id'] === $alice, 'Emails must normalize.');
check(!isset($users->findById($alice)['contrasena']), 'Public user data must omit password hash.');
check($users->findById($alice)['rol'] === 'usuario', 'Public account defaults to normal user.');
try { $users->create('Duplicate', 'ALICE@example.test', 'hash'); check(false, 'Duplicate email was accepted.'); }
catch (PDOException) { check(true, 'Duplicate email rejected.'); }

$category = (int) $movies->categories()[0]['id'];
$film = $movies->save(['titulo' => 'Un 100% de cine', 'fechalanzamiento' => 1932, 'categoria_id' => $category, 'director' => 'Directora Ejemplo', 'duracion' => 5400]);
check(count($movies->all()) === 0, 'Draft cannot appear in public catalog.');
check($movies->find($film) === null && $movies->find($film, false) !== null, 'Draft direct access must be filtered.');
check(!$resources->readyToPublish($film), 'Draft without resources must not be ready.');
foreach (['portada' => 'image/png', 'trailer' => 'video/mp4'] as $type => $mime) {
    $pdo->beginTransaction();
    $resources->save(['pelicula_id' => $film, 'tipo' => $type, 'clave_s3' => "classicflix/$film/$type/first", 'mime' => $mime, 'tamano' => 100]);
    $pdo->commit();
}
check($resources->readyToPublish($film), 'Verified poster and trailer must permit publication.');
check($movies->find($film) === null, 'Uploads alone must not publish drafts.');
$movies->setState($film, 'publicado');
check(count($movies->all('100%', $category)) === 1, 'Literal wildcard search must work.');
check(count($movies->all('100_')) === 0, 'Underscore must not become a wildcard.');
check($movies->find($film)['has_trailer'] && !$movies->find($film)['has_movie'], 'Media flags must reflect attached objects.');
check($movies->toggleFavorite($alice, $film), 'First toggle must add.');
check(count($movies->favorites($alice)) === 1 && count($movies->favorites($bob)) === 0, 'Favorites must be user isolated.');
check(!$movies->toggleFavorite($alice, $film), 'Second toggle must remove.');

$token = bin2hex(random_bytes(24));
$movies->recordView($alice, $film, $token);
$movies->recordView($alice, $film, $token);
check((int) $pdo->query('SELECT COUNT(*) FROM Vistas')->fetchColumn() === 1, 'Repeated play token must not duplicate history.');
check(count($movies->history($alice)) === 1 && count($movies->history($bob)) === 0, 'History must be user isolated.');
$old = $resources->save(['pelicula_id' => $film, 'tipo' => 'portada', 'clave_s3' => "classicflix/$film/portada/replacement", 'mime' => 'image/png', 'tamano' => 200]);
check($old === "classicflix/$film/portada/first", 'Replaced resource must return old key for safe postcommit cleanup.');
check(count($resources->allForMovie($film)) === 2, 'Resource replacement must not create duplicates.');

$upload = ['id' => bin2hex(random_bytes(16)), 'usuario_id' => $alice, 'pelicula_id' => $film, 'tipo' => 'pelicula', 'clave_s3' => "classicflix/$film/pelicula/new", 'mime' => 'video/mp4', 'tamano' => 200000000];
$uploads->create($upload);
$uploads->setMultipartId($upload['id'], 'multipart-test');
$uploads->setState($upload['id'], 'verificando');
$pdo->prepare('UPDATE Cargas SET actualizado_en = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 90000), $upload['id']]);
check(count($uploads->stale()) === 1, 'Old verification states must be eligible for crash recovery.');
$pdo->beginTransaction();
check($uploads->findForUpdate($upload['id'])['multipart_id'] === 'multipart-test', 'Upload row must preserve provider multipart ID.');
$uploads->setState($upload['id'], 'limpiada');
$pdo->commit();
check(count($uploads->stale()) === 0, 'Already cleaned uploads must not be selected again.');
$migrator->migrate();
check($users->count() === 2 && $movies->find($film) !== null, 'Repeating migration must preserve records.');
check($movies->stats()['usuarios'] === 2, 'Stats must come from real rows.');
fwrite(STDOUT, "$assertions comprobaciones de repositorios aprobadas (SQLite aislado; MySQL/AWS/Redis no verificados por esta prueba).\n");
