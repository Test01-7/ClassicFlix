<?php
declare(strict_types=1);

namespace ClassicFlix\Services;

use ClassicFlix\Infrastructure\Cache;
use ClassicFlix\Infrastructure\S3Storage;
use ClassicFlix\Repositories\MovieRepository;
use ClassicFlix\Repositories\ResourceRepository;
use ClassicFlix\Repositories\UploadRepository;
use ClassicFlix\Support\Config;
use ClassicFlix\Support\HttpException;
use PDO;

final class UploadService
{
    private const PART_SIZE = 10485760;
    private const MULTIPART_THRESHOLD = 104857600;

    public function __construct(
        private readonly Config $config,
        private readonly PDO $pdo,
        private readonly UploadRepository $uploads,
        private readonly ResourceRepository $resources,
        private readonly MovieRepository $movies,
        private readonly S3Storage $storage,
        private readonly Cache $cache,
    ) {}

    public function initiate(array $input, int $userId): array
    {
        $movieId = (int) ($input['pelicula_id'] ?? 0);
        if ($movieId < 1 || $this->movies->find($movieId, false) === null) {
            throw new HttpException(404, 'Guarda primero los datos de la película.');
        }
        $kind = (string) ($input['tipo'] ?? '');
        $mime = strtolower(trim((string) ($input['mime'] ?? '')));
        $allowed = $kind === 'portada' ? ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'] : ['video/mp4' => 'mp4'];
        if (!in_array($kind, ['portada', 'trailer', 'pelicula'], true) || !isset($allowed[$mime])) {
            throw new HttpException(422, 'Selecciona una portada JPEG, PNG o WebP, o un video MP4.');
        }
        $size = filter_var($input['tamano'] ?? null, FILTER_VALIDATE_INT);
        $maximum = match ($kind) {
            'portada' => (int) $this->config->get('UPLOAD_POSTER_MAX_BYTES', 5242880),
            'trailer' => (int) $this->config->get('UPLOAD_TRAILER_MAX_BYTES', 104857600),
            default => (int) $this->config->get('UPLOAD_MOVIE_MAX_BYTES', 2147483648),
        };
        if ($size === false || $size < 12 || $size > $maximum) {
            throw new HttpException(422, 'El archivo está vacío o supera el tamaño permitido para este recurso.');
        }
        $source = trim((string) ($input['fuente_url'] ?? ''));
        $rights = trim((string) ($input['referencia_derechos'] ?? ''));
        if (strlen($source) > 2048 || ($source !== '' && (!filter_var($source, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['https', 'http'], true)))) {
            throw new HttpException(422, 'Introduce una dirección de origen válida, con http o https.');
        }
        if (strlen($rights) > 5000) {
            throw new HttpException(422, 'La referencia de derechos es demasiado extensa.');
        }
        $id = bin2hex(random_bytes(16));
        $key = sprintf('classicflix/%d/%s/%s.%s', $movieId, $kind, $id, $allowed[$mime]);
        $multipartId = null;
        try {
            $multipart = $size >= self::MULTIPART_THRESHOLD;
            if ($multipart) {
                $multipartId = $this->storage->initiateMultipart($key, $mime);
            }
            $url = $multipart ? null : $this->storage->presignPut($key, $mime);
            $this->uploads->create([
                'id' => $id, 'usuario_id' => $userId, 'pelicula_id' => $movieId,
                'tipo' => $kind, 'clave_s3' => $key, 'mime' => $mime, 'tamano' => $size,
                'multipart_id' => $multipartId, 'part_size' => self::PART_SIZE,
                'fuente_url' => $source, 'referencia_derechos' => $rights, 'estado' => 'pendiente',
            ]);
            return ['upload_id' => $id, 'mode' => $multipart ? 'multipart' : 'put', 'upload_url' => $url, 'part_size' => self::PART_SIZE, 'headers' => ['Content-Type' => $mime, 'If-None-Match' => '*']];
        } catch (\Throwable $error) {
            if ($multipartId !== null) {
                try { $this->storage->abortMultipart($key, $multipartId); } catch (\Throwable) { /* S3 lifecycle is the final cleanup backstop. */ }
            }
            throw $this->storageError($error);
        }
    }

    public function part(string $id, int $part, int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $upload = $this->owned($id, $userId, true);
            if ($upload['estado'] !== 'pendiente' || empty($upload['multipart_id'])) {
                throw new HttpException(409, 'Esta carga ya no admite nuevas partes.');
            }
            $partCount = (int) ceil((int) $upload['tamano'] / (int) $upload['part_size']);
            if ($part < 1 || $part > $partCount) {
                throw new HttpException(422, 'El número de parte no es válido.');
            }
            $this->uploads->setState($id, 'pendiente'); // Keep active multipart uploads out of abandoned-upload cleanup.
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        return ['url' => $this->storage->presignPart((string) $upload['clave_s3'], (string) $upload['multipart_id'], $part), 'part_number' => $part, 'headers' => ['Content-Type' => 'application/octet-stream']];
    }

    public function complete(string $id, int $userId, array $parts = []): array
    {
        // Persist a claim before network work: cancel and duplicate complete cannot race it.
        $this->pdo->beginTransaction();
        try {
            $upload = $this->owned($id, $userId, true);
            if ($upload['estado'] === 'completada') {
                $this->pdo->commit();
                return ['completed' => true, 'pelicula_id' => (int) $upload['pelicula_id']];
            }
            if ($upload['estado'] !== 'pendiente') {
                throw new HttpException(409, $upload['estado'] === 'verificando' ? 'La carga se está verificando. Espera y actualiza la página.' : 'Esta carga ya terminó o fue cancelada.');
            }
            $normalized = empty($upload['multipart_id']) ? [] : $this->normalizeParts($upload, $parts);
            $this->uploads->setState($id, 'verificando');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }

        try {
            if (!empty($upload['multipart_id'])) {
                $this->storage->completeMultipart((string) $upload['clave_s3'], (string) $upload['multipart_id'], $normalized);
            }
            self::validateReceived($upload, $this->storage->inspect((string) $upload['clave_s3']));
            $this->pdo->beginTransaction();
            $current = $this->owned($id, $userId, true);
            if ($current['estado'] !== 'verificando') {
                throw new HttpException(409, 'La carga ya no puede completarse.');
            }
            $oldKey = $this->resources->save([
                'pelicula_id' => (int) $upload['pelicula_id'], 'tipo' => $upload['tipo'],
                'clave_s3' => $upload['clave_s3'], 'mime' => $upload['mime'], 'tamano' => (int) $upload['tamano'],
                'fuente_url' => $upload['fuente_url'], 'referencia_derechos' => $upload['referencia_derechos'],
            ]);
            $this->uploads->setState($id, 'completada');
            $this->pdo->commit();
            $this->cache->forget(CatalogService::CACHE_KEY);
            if ($oldKey !== null && $oldKey !== $upload['clave_s3']) {
                try { $this->storage->delete($oldKey); } catch (\Throwable) { error_log('ClassicFlix: no se pudo retirar un recurso S3 reemplazado.'); }
            }
            return ['completed' => true, 'pelicula_id' => (int) $upload['pelicula_id']];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            // A lost commit acknowledgement must not delete an already attached object.
            try {
                $current = $this->uploads->find($id);
                if (($current['estado'] ?? '') === 'completada') {
                    $this->cache->forget(CatalogService::CACHE_KEY);
                    return ['completed' => true, 'pelicula_id' => (int) $upload['pelicula_id']];
                }
                $this->cleanupDetached($upload);
                $this->uploads->setState($id, 'fallida');
            } catch (\Throwable) { error_log('ClassicFlix: una carga pendiente requiere limpieza posterior.'); }
            throw $this->storageError($error);
        }
    }

    public function cancel(string $id, int $userId): array
    {
        $this->pdo->beginTransaction();
        try {
            $upload = $this->owned($id, $userId, true);
            if (in_array($upload['estado'], ['completada', 'verificando'], true)) {
                throw new HttpException(409, 'La carga ya se completó o está siendo verificada.');
            }
            $this->uploads->setState($id, 'cancelada');
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
        try { $this->cleanupDetached($upload); } catch (\Throwable) { /* Daily cleanup retries after signed PUTs have expired. */ }
        return ['cancelled' => true];
    }

    public function cleanupExpired(int $ageSeconds = 86400): int
    {
        $cleaned = 0;
        foreach ($this->uploads->stale(max(3600, $ageSeconds)) as $upload) {
            $this->pdo->beginTransaction();
            try {
                $current = $this->uploads->findForUpdate((string) $upload['id']);
                $cutoff = gmdate('Y-m-d H:i:s', time() - max(3600, $ageSeconds));
                if ($current === null || in_array($current['estado'], ['completada', 'limpiada'], true) || (string) $current['actualizado_en'] >= $cutoff) {
                    $this->pdo->rollBack();
                    continue;
                }
                $this->cleanupDetached($current);
                $this->uploads->setState((string) $current['id'], 'limpiada');
                $this->pdo->commit();
                $cleaned++;
            } catch (\Throwable) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                error_log('ClassicFlix: limpieza S3 pendiente; se volverá a intentar.');
            }
        }
        return $cleaned;
    }

    private function cleanupDetached(array $upload): void
    {
        $attached = $this->resources->get((int) $upload['pelicula_id'], (string) $upload['tipo']);
        if (($attached['clave_s3'] ?? '') === $upload['clave_s3']) {
            return;
        }
        if (!empty($upload['multipart_id'])) {
            $this->storage->abortMultipart((string) $upload['clave_s3'], (string) $upload['multipart_id']);
        }
        $this->storage->delete((string) $upload['clave_s3']);
    }

    private function owned(string $id, int $userId, bool $lock = false): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new HttpException(404, 'No se encontró esta carga.');
        }
        $upload = $lock ? $this->uploads->findForUpdate($id) : $this->uploads->find($id);
        if ($upload === null || (int) $upload['usuario_id'] !== $userId) {
            throw new HttpException(404, 'No se encontró esta carga.');
        }
        return $upload;
    }

    private function normalizeParts(array $upload, array $parts): array
    {
        $count = (int) ceil((int) $upload['tamano'] / (int) $upload['part_size']);
        if (count($parts) !== $count) {
            throw new HttpException(422, 'Todavía faltan partes por cargar.');
        }
        $normalized = [];
        foreach (array_values($parts) as $index => $part) {
            if (!is_array($part) || (int) ($part['PartNumber'] ?? 0) !== $index + 1 || !is_string($part['ETag'] ?? null) || !preg_match('/^"?[A-Za-z0-9+\/=:_-]{1,200}"?$/D', $part['ETag'])) {
                throw new HttpException(422, 'La lista de partes no es válida.');
            }
            $normalized[] = ['PartNumber' => $index + 1, 'ETag' => $part['ETag']];
        }
        return $normalized;
    }

    /** Check the received bytes, not only browser-supplied Content-Type metadata. */
    public static function validateReceived(array $upload, array $received): void
    {
        if ((int) $received['size'] !== (int) $upload['tamano'] || $received['mime'] !== $upload['mime']) {
            throw new HttpException(422, 'El archivo recibido no coincide con el tamaño o formato anunciado.');
        }
        $sample = (string) $received['sample'];
        $valid = match ($upload['mime']) {
            'image/jpeg' => str_starts_with($sample, "\xFF\xD8\xFF"),
            'image/png' => str_starts_with($sample, "\x89PNG\r\n\x1A\n"),
            'image/webp' => substr($sample, 0, 4) === 'RIFF' && substr($sample, 8, 4) === 'WEBP',
            'video/mp4' => substr($sample, 4, 4) === 'ftyp' && in_array(substr($sample, 8, 4), ['isom', 'iso2', 'iso3', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'MSNV', 'dash'], true),
            default => false,
        };
        if (!$valid) {
            throw new HttpException(422, 'El contenido del archivo no corresponde al formato permitido.');
        }
        if ($upload['tipo'] === 'portada' && @getimagesizefromstring($sample) === false) {
            throw new HttpException(422, 'La portada no contiene una imagen válida.');
        }
    }

    private function storageError(\Throwable $error): \Throwable
    {
        if ($error instanceof HttpException) { return $error; }
        error_log('ClassicFlix: fallo al procesar una operación de carga S3.');
        return new HttpException(502, 'No se pudo completar la operación con S3. Revisa la conexión y la configuración e inténtalo de nuevo.');
    }
}
