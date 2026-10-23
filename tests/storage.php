<?php
declare(strict_types=1);

// Service-level tests with an in-memory database and deliberately fake S3/Redis.
// These prove authorization/validation/state behavior, not a live AWS connection.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ClassicFlix\\';
    if (str_starts_with($class, $prefix)) {
        $path = dirname(__DIR__) . '/Backend/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) { require_once $path; }
    }
});

use ClassicFlix\Infrastructure\Cache;
use ClassicFlix\Infrastructure\S3Storage;
use ClassicFlix\Repositories\MovieRepository;
use ClassicFlix\Repositories\ResourceRepository;
use ClassicFlix\Repositories\UploadRepository;
use ClassicFlix\Services\MediaService;
use ClassicFlix\Services\UploadService;
use ClassicFlix\Support\Config;
use ClassicFlix\Support\HttpException;

final class TestS3Client
{
    public array $objects = [];
    public array $multiparts = [];
    public array $deleted = [];
    public array $commands = [];
    public array $aborted = [];
    public bool $failComplete = false;
    public function getCommand(string $operation, array $arguments): array
    {
        $this->commands[] = [$operation, $arguments];
        return [$operation, $arguments];
    }
    public function createPresignedRequest(array $command, string $expires): object
    {
        $uri = 'https://fake.invalid/' . rawurlencode($command[1]['Key']) . '?operation=' . $command[0];
        return new class($uri) {
            public function __construct(private string $uri) {}
            public function getUri(): string { return $this->uri; }
        };
    }
    public function createMultipartUpload(array $arguments): array
    {
        $id = bin2hex(random_bytes(8));
        $this->multiparts[$id] = $arguments['Key'];
        return ['UploadId' => $id];
    }
    public function completeMultipartUpload(array $arguments): void
    {
        if ($this->failComplete) { throw new RuntimeException('Simulated network failure'); }
        unset($this->multiparts[$arguments['UploadId']]);
    }
    public function abortMultipartUpload(array $arguments): void
    {
        $this->aborted[] = $arguments['UploadId'];
        unset($this->multiparts[$arguments['UploadId']]);
    }
    public function headObject(array $arguments): array
    {
        if (!isset($this->objects[$arguments['Key']])) { throw new RuntimeException('Object absent'); }
        $object = $this->objects[$arguments['Key']];
        return ['ContentLength' => $object['size'], 'ContentType' => $object['mime']];
    }
    public function getObject(array $arguments): array { return ['Body' => $this->objects[$arguments['Key']]['sample']]; }
    public function deleteObject(array $arguments): void
    {
        $this->deleted[] = $arguments['Key'];
        unset($this->objects[$arguments['Key']]);
    }
    public function headBucket(array $arguments): array { return []; }
}

final class TestRedisClient
{
    public array $data = [];
    public bool $fail = false;
    public function get(string $key): ?string
    {
        if ($this->fail) { throw new RuntimeException('Redis unavailable'); }
        return $this->data[$key] ?? null;
    }
    public function setex(string $key, int $ttl, string $value): void { $this->data[$key] = $value; }
    public function del(array $keys): void { foreach ($keys as $key) { unset($this->data[$key]); } }
    public function ping(): string { return 'PONG'; }
}

$count = 0;
$assert = static function (bool $condition, string $name) use (&$count): void {
    if (!$condition) { throw new RuntimeException('FAILED: ' . $name); }
    $count++;
};
$rejects = static function (int $status, callable $operation, string $name) use ($assert): void {
    try { $operation(); } catch (HttpException $error) {
        $assert($error->status === $status, $name . ' (HTTP ' . $error->status . ')');
        return;
    }
    $assert(false, $name . ' was accepted');
};
$savedEnv = [];
foreach (['AWS_BUCKET' => 'test-bucket', 'REDIS_ENABLED' => 'true', 'MEDIA_TRAILER_TTL' => '900', 'MEDIA_MOVIE_TTL' => '3600', 'UPLOAD_POSTER_MAX_BYTES' => '5242880', 'UPLOAD_TRAILER_MAX_BYTES' => '104857600', 'UPLOAD_MOVIE_MAX_BYTES' => '2147483648'] as $key => $value) {
    $savedEnv[$key] = getenv($key);
    putenv($key . '=' . $value);
}

try {
    $_SESSION = [];
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec("CREATE TABLE Usuarios (id INTEGER PRIMARY KEY,nombre TEXT,email TEXT,contrasena TEXT,rol TEXT);
        CREATE TABLE Categorias (id INTEGER PRIMARY KEY,nombre TEXT);
        CREATE TABLE Peliculas (id INTEGER PRIMARY KEY AUTOINCREMENT,titulo TEXT,fechalanzamiento INTEGER,genero TEXT,director TEXT,actores TEXT,sinopsis TEXT,duracion INTEGER,trailer_duracion INTEGER,categoria_id INTEGER,estado TEXT DEFAULT 'borrador',actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE Recursos (id INTEGER PRIMARY KEY AUTOINCREMENT,pelicula_id INTEGER,tipo TEXT,clave_s3 TEXT,mime TEXT,tamano INTEGER,fuente_url TEXT,referencia_derechos TEXT,UNIQUE(pelicula_id,tipo));
        CREATE TABLE Cargas (id TEXT PRIMARY KEY,usuario_id INTEGER,pelicula_id INTEGER,tipo TEXT,clave_s3 TEXT,mime TEXT,tamano INTEGER,multipart_id TEXT,part_size INTEGER,fuente_url TEXT,referencia_derechos TEXT,estado TEXT,creado_en TEXT DEFAULT CURRENT_TIMESTAMP,actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE Vistas (id INTEGER PRIMARY KEY AUTOINCREMENT,usuario_id INTEGER,pelicula_id INTEGER,playback_token TEXT,fecha TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(usuario_id,playback_token));
        INSERT INTO Usuarios VALUES(1,'Admin','admin@example.test','hash','admin'),(2,'Member','member@example.test','hash','usuario');
        INSERT INTO Categorias VALUES(1,'Drama');");
    $config = new Config(['AWS_BUCKET' => 'test-bucket', 'REDIS_ENABLED' => 'true']);
    $client = new TestS3Client();
    $redis = new TestRedisClient();
    $storage = new S3Storage($config, $client);
    $cache = new Cache($config, $redis);
    $movies = new MovieRepository($pdo);
    $resources = new ResourceRepository($pdo);
    $uploads = new UploadRepository($pdo);
    $service = new UploadService($config, $pdo, $uploads, $resources, $movies, $storage, $cache);
    $media = new MediaService($config, $movies, $resources, $storage);
    $movie = $movies->save(['titulo' => 'Service fixture', 'fechalanzamiento' => 1928, 'categoria_id' => 1]);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aB9sAAAAASUVORK5CYII=');
    $mp4 = pack('N', 24) . 'ftypisom' . pack('N', 0) . 'isommp42';
    $input = ['pelicula_id' => $movie, 'tipo' => 'portada', 'mime' => 'image/png', 'tamano' => strlen($png)];
    $rejects(422, fn() => $service->initiate(array_replace($input, ['tamano' => 6 * 1024 ** 2]), 1), 'Poster limit');
    $rejects(422, fn() => $service->initiate(array_replace($input, ['mime' => 'text/html']), 1), 'Rejected MIME');
    $rejects(422, fn() => $service->initiate(array_replace($input, ['fuente_url' => 'javascript:alert(1)']), 1), 'Source URL scheme');
    $rejects(404, fn() => $service->initiate(array_replace($input, ['pelicula_id' => 999]), 1), 'Unknown movie');

    $result = $service->initiate($input, 1);
    $assert($result['mode'] === 'put', 'Small upload uses direct PUT');
    $assert($result['headers']['If-None-Match'] === '*' && $client->commands[array_key_last($client->commands)][1]['IfNoneMatch'] === '*', 'Signed PUT prevents overwriting a verified object');
    $row = $uploads->find($result['upload_id']);
    $assert(str_starts_with($row['clave_s3'], "classicflix/$movie/portada/"), 'Server owns upload key');
    $client->objects[$row['clave_s3']] = ['size' => strlen($png), 'mime' => 'image/png', 'sample' => $png];
    $rejects(404, fn() => $service->complete($result['upload_id'], 2), 'Upload bound to owner');
    $service->complete($result['upload_id'], 1);
    $assert($uploads->find($result['upload_id'])['estado'] === 'completada', 'Upload finalized');
    $assert($resources->get($movie, 'portada')['clave_s3'] === $row['clave_s3'], 'Verified object attached');
    $service->complete($result['upload_id'], 1);
    $rejects(409, fn() => $service->cancel($result['upload_id'], 1), 'Cannot cancel completed object');
    $assert(isset($client->objects[$row['clave_s3']]), 'Duplicate completion never removes resource');
    $assert($movies->find($movie) === null, 'Uploading does not silently publish draft');

    $replacement = $service->initiate($input, 1);
    $new = $uploads->find($replacement['upload_id']);
    $client->objects[$new['clave_s3']] = ['size' => strlen($png), 'mime' => 'image/png', 'sample' => $png];
    $service->complete($replacement['upload_id'], 1);
    $assert(!isset($client->objects[$row['clave_s3']]) && isset($client->objects[$new['clave_s3']]), 'Replaced resource removed only after successful attachment');

    $bad = $service->initiate(['pelicula_id' => $movie, 'tipo' => 'trailer', 'mime' => 'video/mp4', 'tamano' => strlen($mp4)], 1);
    $badRow = $uploads->find($bad['upload_id']);
    $client->objects[$badRow['clave_s3']] = ['size' => strlen($mp4), 'mime' => 'video/mp4', 'sample' => '<html>not video</html>'];
    $rejects(422, fn() => $service->complete($bad['upload_id'], 1), 'Reject bytes masquerading as video');
    $assert($resources->get($movie, 'trailer') === null && !isset($client->objects[$badRow['clave_s3']]), 'Rejected file stays out of catalog and is cleaned');

    $large = $service->initiate(['pelicula_id' => $movie, 'tipo' => 'trailer', 'mime' => 'video/mp4', 'tamano' => 104857600], 1);
    $assert($large['mode'] === 'multipart' && $large['upload_url'] === null, 'Large video uses multipart');
    $largeRow = $uploads->find($large['upload_id']);
    $rejects(422, fn() => $service->part($large['upload_id'], 11, 1), 'Reject extra multipart part');
    $rejects(404, fn() => $service->part($large['upload_id'], 1, 2), 'Part signing bound to owner');
    $assert(isset($service->part($large['upload_id'], 1, 1)['url']), 'Known multipart part can be signed');
    $rejects(422, fn() => $service->complete($large['upload_id'], 1, []), 'Missing parts rejected before assembly');
    $assert($uploads->find($large['upload_id'])['estado'] === 'pendiente', 'Missing part does not lose upload');
    $parts = array_map(static fn(int $number): array => ['PartNumber' => $number, 'ETag' => '"' . str_repeat('a', 32) . '"'], range(1, 10));
    $client->objects[$largeRow['clave_s3']] = ['size' => 104857600, 'mime' => 'video/mp4', 'sample' => $mp4];
    $service->complete($large['upload_id'], 1, $parts);
    $assert($resources->readyToPublish($movie), 'Poster and trailer permit explicit publication');

    $cancel = $service->initiate(['pelicula_id' => $movie, 'tipo' => 'pelicula', 'mime' => 'video/mp4', 'tamano' => 104857600], 1);
    $cancelRow = $uploads->find($cancel['upload_id']);
    $service->cancel($cancel['upload_id'], 1);
    $assert(in_array($cancelRow['multipart_id'], $client->aborted, true), 'Cancellation aborts multipart upload');
    $rejects(409, fn() => $service->complete($cancel['upload_id'], 1, $parts), 'Cancelled upload cannot attach');

    $film = $service->initiate(['pelicula_id' => $movie, 'tipo' => 'pelicula', 'mime' => 'video/mp4', 'tamano' => strlen($mp4)], 1);
    $filmRow = $uploads->find($film['upload_id']);
    $client->objects[$filmRow['clave_s3']] = ['size' => strlen($mp4), 'mime' => 'video/mp4', 'sample' => $mp4];
    $service->complete($film['upload_id'], 1);
    $rejects(401, fn() => $media->sign($movie, 'pelicula', null), 'Anonymous film request rejected');
    $rejects(404, fn() => $media->sign($movie, 'trailer', null), 'Draft trailer hidden publicly');
    $assert(isset($media->sign($movie, 'trailer', ['id' => 1, 'rol' => 'admin'])['url']), 'Admin can preview draft');
    $movies->setState($movie, 'publicado');
    $assert($media->sign($movie, 'trailer', null)['expires_in'] === 900, 'Published trailer accessible anonymously with short TTL');
    $assert($media->sign($movie, 'pelicula', ['id' => 2, 'rol' => 'usuario'])['expires_in'] === 3600, 'Authenticated film signed');
    $rejects(400, fn() => $media->sign($movie, '../secret', ['id' => 1, 'rol' => 'admin']), 'Arbitrary resource types rejected');

    $token = $media->playbackToken($movie, 'pelicula');
    $user = ['id' => 2, 'rol' => 'usuario'];
    $media->record($movie, 'pelicula', $token, $user);
    $media->record($movie, 'pelicula', $token, $user);
    $assert((int) $pdo->query('SELECT COUNT(*) FROM Vistas')->fetchColumn() === 1, 'Repeat playing event deduplicated');
    $rejects(403, fn() => $media->record($movie + 1, 'pelicula', $token, $user), 'History token bound to movie');
    $rejects(403, fn() => $media->record($movie, 'trailer', $token, $user), 'History token bound to resource kind');
    $_SESSION['playback_tokens'][$token]['expires'] = time() - 1;
    $rejects(403, fn() => $media->record($movie, 'pelicula', $token, $user), 'Expired history token rejected');
    for ($i = 0; $i < 35; $i++) { $media->playbackToken($movie, 'trailer'); }
    $assert(count($_SESSION['playback_tokens']) <= 30, 'Playback session tokens bounded');

    $loads = 0;
    $loader = static function () use (&$loads): array { $loads++; return ['catalog' => $loads]; };
    $cache->remember('test', $loader);
    $cache->remember('test', $loader);
    $assert($loads === 1, 'Redis cache hit avoids database loader');
    $cache->forget('test');
    $cache->remember('test', $loader);
    $assert($loads === 2, 'Catalog invalidation reloads metadata');
    $redis->fail = true;
    $fallback = new Cache($config, $redis);
    $assert($fallback->remember('failed', $loader) === ['catalog' => 3], 'Redis failure falls back to loader');
    try { $fallback->remember('db-failure', static fn() => throw new RuntimeException('Database unavailable')); $assert(false, 'Database error swallowed'); }
    catch (RuntimeException $error) { $assert($error->getMessage() === 'Database unavailable', 'Database errors remain visible'); }

    $old = gmdate('Y-m-d H:i:s', time() - 90000);
    $statement = $pdo->prepare('UPDATE Cargas SET actualizado_en = ? WHERE id = ?');
    $statement->execute([$old, $cancel['upload_id']]);
    $assert($service->cleanupExpired() >= 1, 'Abandoned upload cleanup runs');
    $assert($uploads->find($cancel['upload_id'])['estado'] === 'limpiada', 'Cleanup state persisted');
    $assert(isset($client->objects[$filmRow['clave_s3']]), 'Cleanup preserves attached film');
    $vendor = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($vendor)) { require_once $vendor; }
    if (class_exists(\Aws\S3\S3Client::class)) {
        // Dummy credentials only: serialization/signing does not contact AWS.
        $sdk = new \Aws\S3\S3Client([
            'version' => '2006-03-01', 'region' => 'us-east-1',
            'credentials' => ['key' => 'offline-test-key', 'secret' => 'offline-test-secret'],
            'request_checksum_calculation' => 'when_required',
            'signature_provider' => S3Storage::signatureProvider(),
        ]);
        $realSigner = new S3Storage($config, $sdk);
        $signedPut = $realSigner->presignPut('classicflix/1/portada/offline.png', 'image/png');
        parse_str((string) parse_url($signedPut, PHP_URL_QUERY), $query);
        $assert(str_contains((string) ($query['X-Amz-SignedHeaders'] ?? ''), 'if-none-match'), 'Real SDK signs overwrite protection');
        $assert(str_contains((string) ($query['X-Amz-SignedHeaders'] ?? ''), 'content-type'), 'Real SDK binds announced upload MIME');
        $assert(!isset($query['x-amz-checksum-crc32']), 'Real SDK does not bind an empty-body checksum');
        $assert(isset($query['X-Amz-Signature']) && $query['X-Amz-Expires'] === '900', 'Real SDK generates expiring SigV4 PUT');
        $signedPart = $realSigner->presignPart('classicflix/1/pelicula/offline.mp4', 'offline-upload-id', 2);
        parse_str((string) parse_url($signedPart, PHP_URL_QUERY), $partQuery);
        $partRequest = \Aws\serialize($sdk->getCommand('UploadPart', ['Bucket' => 'test-bucket', 'Key' => 'classicflix/1/pelicula/offline.mp4', 'UploadId' => 'offline-upload-id', 'PartNumber' => 2]));
        $assert(($partQuery['X-Amz-SignedHeaders'] ?? '') === 'content-type;host' && $partRequest->getHeaderLine('Content-Type') === 'application/octet-stream', 'Real multipart signature matches headers returned to browser');
        $assert(($partQuery['partNumber'] ?? '') === '2' && ($partQuery['uploadId'] ?? '') === 'offline-upload-id', 'Real SDK binds signed part to upload and number');
        $request = \Aws\serialize($sdk->getCommand('CompleteMultipartUpload', [
            'Bucket' => 'test-bucket', 'Key' => 'classicflix/1/pelicula/offline.mp4', 'UploadId' => 'offline-upload-id',
            'IfNoneMatch' => '*', 'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => str_repeat('a', 32)]]],
        ]));
        $assert($request->getHeaderLine('If-None-Match') === '*', 'Real SDK supports conditional multipart completion');
        $assert(str_contains((string) $request->getBody(), '<PartNumber>1</PartNumber>'), 'Real SDK serializes complete part list');
        $signedGet = $realSigner->presignGet('classicflix/1/trailer/offline.mp4', 900, 'video/mp4');
        parse_str((string) parse_url($signedGet, PHP_URL_QUERY), $getQuery);
        $assert(($getQuery['X-Amz-SignedHeaders'] ?? '') === 'host', 'Video GET can be sent by native HTML5 without custom headers');
        $assert(($getQuery['response-content-disposition'] ?? '') === 'inline' && ($getQuery['response-content-type'] ?? '') === 'video/mp4', 'Real SDK signs inline video metadata');
        echo "Real AWS SDK: offline serialization/signing checked; no AWS requests.\n";
    } else {
        echo "Real AWS SDK contract checks skipped: run composer install.\n";
    }
    echo "Storage services: $count assertions passed (fake S3/Redis; no live AWS test).\n";
} finally {
    foreach ($savedEnv as $key => $value) { $value === false ? putenv($key) : putenv($key . '=' . $value); }
}
