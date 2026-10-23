<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Deliberately restricted to a disposable local instance. Never uses .env or the user's DB.
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
function verify(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) { throw new RuntimeException($message); }
    $assertions++;
}

$pdo = new PDO('mysql:host=127.0.0.1;port=33317;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$actual = rtrim(str_replace('\\', '/', (string) $pdo->query('SELECT @@datadir')->fetchColumn()), '/');
$expected = str_replace('\\', '/', $root . '/var/mysql-test');
if (strcasecmp($actual, $expected) !== 0) {
    throw new RuntimeException('Refusing tests: server datadir does not match isolated workspace var/mysql-test.');
}
$pdo->exec("SET time_zone = '+00:00'");
$run = bin2hex(random_bytes(4));
$fresh = 'cf_fresh_' . $run;
$legacy = 'cf_legacy_' . $run;
$sql = (string) file_get_contents($root . '/BaseDatos.sql');
$sql = str_replace(['CREATE DATABASE IF NOT EXISTS ClassicFlix', 'USE ClassicFlix'], ['CREATE DATABASE IF NOT EXISTS ' . $fresh, 'USE ' . $fresh], $sql);
$sql = preg_replace('/--[^\r\n]*/', '', $sql);
foreach (explode(';', (string) $sql) as $statement) {
    if (trim($statement) !== '') { $pdo->exec($statement); }
}
(new Migrator($pdo, $root))->migrate();
(new Migrator($pdo, $root))->migrate();
verify(count($pdo->query('SHOW TABLES')->fetchAll()) === 9, 'Fresh schema should have eight domain tables and migration metadata.');
$users = new UserRepository($pdo); $movies = new MovieRepository($pdo); $resources = new ResourceRepository($pdo); $uploads = new UploadRepository($pdo);
$u1 = $users->create('Primera cuenta', 'one@example.test', password_hash('password-test-long', PASSWORD_DEFAULT));
$u2 = $users->create('Segunda cuenta', 'two@example.test', password_hash('password-test-long', PASSWORD_DEFAULT));
verify($users->findByEmail('ONE@example.test')['id'] === $u1, 'Email lookup must normalize.');
verify(!isset($users->findById($u1)['contrasena']), 'User projection must hide hashes.');
$categoryId = (int) $movies->categories()[0]['id'];
$movieId = $movies->save(['titulo' => 'Cine 100%', 'fechalanzamiento' => 1930, 'categoria_id' => $categoryId]);
verify($movies->find($movieId) === null, 'Draft must be absent from public query.');
verify(!$resources->readyToPublish($movieId), 'No-resource draft not publishable.');
$pdo->beginTransaction();
foreach (['portada' => 'image/png', 'trailer' => 'video/mp4'] as $type => $mime) {
    $resources->save(['pelicula_id' => $movieId, 'tipo' => $type, 'clave_s3' => "classicflix/$movieId/$type/key", 'mime' => $mime, 'tamano' => 123]);
}
$pdo->commit();
verify($resources->readyToPublish($movieId) && $movies->find($movieId) === null, 'Uploading must not auto-publish.');
$movies->setState($movieId, 'publicado');
verify(count($movies->all('100%', $categoryId)) === 1 && count($movies->all('100_')) === 0, 'Search must escape SQL wildcards.');
verify($movies->toggleFavorite($u1, $movieId), 'Favorite add failed.');
verify(count($movies->favorites($u1)) === 1 && count($movies->favorites($u2)) === 0, 'Favorites must isolate users.');
verify(!$movies->toggleFavorite($u1, $movieId), 'Favorite remove failed.');
$token = bin2hex(random_bytes(24));
$movies->recordView($u1, $movieId, $token); $movies->recordView($u1, $movieId, $token);
verify((int) $pdo->query('SELECT COUNT(*) FROM Vistas')->fetchColumn() === 1, 'Duplicate play event inserted twice.');
verify(count($movies->history($u1)) === 1 && count($movies->history($u2)) === 0, 'History must isolate users.');
$pdo->beginTransaction();
$old = $resources->save(['pelicula_id' => $movieId, 'tipo' => 'portada', 'clave_s3' => "classicflix/$movieId/portada/new", 'mime' => 'image/png', 'tamano' => 234]);
$pdo->commit();
verify($old === "classicflix/$movieId/portada/key", 'Replacement must return original key.');
$uploadId = bin2hex(random_bytes(16));
$uploads->create(['id' => $uploadId, 'usuario_id' => $u1, 'pelicula_id' => $movieId, 'tipo' => 'pelicula', 'clave_s3' => "classicflix/$movieId/pelicula/test", 'mime' => 'video/mp4', 'tamano' => 200000000]);
$pdo->beginTransaction();
verify($uploads->findForUpdate($uploadId)['estado'] === 'pendiente', 'Upload lock/read failed.');
$uploads->setState($uploadId, 'verificando');
$pdo->commit();
$pdo->prepare('UPDATE Cargas SET actualizado_en = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 90000), $uploadId]);
verify(count($uploads->stale()) === 1, 'Stale verifying upload not selected.');
$uploads->setState($uploadId, 'limpiada');
verify(count($uploads->stale()) === 0, 'Cleaned upload selected twice.');

$pdo->exec("CREATE DATABASE `$legacy` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$legacy`");
$legacySql = <<<'SQL'
CREATE TABLE Usuarios (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL, email VARCHAR(100) NOT NULL UNIQUE, contrasena VARCHAR(255) NOT NULL, rol ENUM('usuario','admin') DEFAULT 'usuario') ENGINE=InnoDB;
CREATE TABLE Peliculas (id INT AUTO_INCREMENT PRIMARY KEY, titulo VARCHAR(255) NOT NULL, fechalanzamiento INT NOT NULL, genero VARCHAR(100), director VARCHAR(100), actores VARCHAR(255), sinopsis TEXT, imagen VARCHAR(255), duracion INT, calificacion DECIMAL(3,1) DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE Favoritos (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, pelicula_id INT NOT NULL, FOREIGN KEY(usuario_id) REFERENCES Usuarios(id), FOREIGN KEY(pelicula_id) REFERENCES Peliculas(id)) ENGINE=InnoDB;
CREATE TABLE Vistas (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, pelicula_id INT NOT NULL, fecha DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(usuario_id) REFERENCES Usuarios(id), FOREIGN KEY(pelicula_id) REFERENCES Peliculas(id)) ENGINE=InnoDB;
CREATE TABLE Resenas (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, pelicula_id INT NOT NULL, resena TEXT NOT NULL, fecha DATETIME DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(usuario_id) REFERENCES Usuarios(id), FOREIGN KEY(pelicula_id) REFERENCES Peliculas(id)) ENGINE=InnoDB;
INSERT INTO Usuarios(nombre,email,contrasena) VALUES ('Original','old@example.test','legacy-password-preserved');
INSERT INTO Peliculas(titulo,fechalanzamiento,genero,duracion,imagen) VALUES ('Original',1931,' Cine negro ',80,'old-image.jpg');
INSERT INTO Favoritos(usuario_id,pelicula_id) VALUES (1,1);
INSERT INTO Vistas(usuario_id,pelicula_id) VALUES (1,1);
INSERT INTO Resenas(usuario_id,pelicula_id,resena) VALUES (1,1,'Reseña conservada');
SQL;
foreach (explode(';', $legacySql) as $statement) {
    if (trim($statement) !== '') { $pdo->exec($statement); }
}
$migrate = new Migrator($pdo, $root);
$migrate->migrate(); $migrate->migrate();
$row = $pdo->query('SELECT * FROM Peliculas WHERE id=1')->fetch();
verify($row['duracion'] === 80 && $row['imagen'] === 'old-image.jpg' && $row['estado'] === 'borrador', 'Legacy metadata must be preserved and drafts must stay private.');
verify($row['categoria_id'] !== null, 'Trimmed legacy genre must map to category.');
verify((int) $pdo->query('SELECT COUNT(*) FROM Favoritos')->fetchColumn() === 1, 'Existing favorites lost.');
verify((int) $pdo->query('SELECT COUNT(*) FROM Vistas')->fetchColumn() === 1, 'Existing history lost.');
verify($pdo->query('SELECT resena FROM Resenas')->fetchColumn() === 'Reseña conservada', 'Existing review lost.');
verify($pdo->query('SELECT contrasena FROM Usuarios')->fetchColumn() === 'legacy-password-preserved', 'Legacy password must not be silently rewritten.');
try { $pdo->exec('INSERT INTO Favoritos(usuario_id,pelicula_id) VALUES (1,1)'); verify(false, 'Unique favorites constraint missing.'); }
catch (PDOException) { verify(true, 'Unique favorites constraint verified.'); }

// Preflight must reject duplicate favorites before creating any additional table.
$duplicate = 'cf_duplicates_' . $run;
$pdo->exec("CREATE DATABASE `$duplicate` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$duplicate`");
$pdo->exec('CREATE TABLE Favoritos(id INT AUTO_INCREMENT PRIMARY KEY,usuario_id INT,pelicula_id INT) ENGINE=InnoDB');
$pdo->exec('INSERT INTO Favoritos(usuario_id,pelicula_id) VALUES (1,1),(1,1)');
try { (new Migrator($pdo, $root))->migrate(); verify(false, 'Duplicate preflight should reject.'); }
catch (RuntimeException $e) { verify(str_contains($e->getMessage(), 'duplicados'), 'Duplicate diagnosis should be actionable.'); }
verify(count($pdo->query('SHOW TABLES')->fetchAll()) === 1 && (int) $pdo->query('SELECT COUNT(*) FROM Favoritos')->fetchColumn() === 2, 'Duplicate preflight must not mutate data/schema.');

fwrite(STDOUT, "$assertions comprobaciones aprobadas contra MariaDB/MySQL local aislado. Bases retenidas solo en var/mysql-test: $fresh, $legacy, $duplicate.\n");
