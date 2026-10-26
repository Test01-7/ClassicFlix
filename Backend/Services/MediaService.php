<?php
declare(strict_types=1);

namespace ClassicFlix\Services;

use ClassicFlix\Infrastructure\S3Storage;
use ClassicFlix\Repositories\MovieRepository;
use ClassicFlix\Repositories\ResourceRepository;
use ClassicFlix\Support\Config;
use ClassicFlix\Support\HttpException;

final class MediaService
{
    public function __construct(
        private readonly Config $config,
        private readonly MovieRepository $movies,
        private readonly ResourceRepository $resources,
        private readonly S3Storage $storage,
    ) {}

    public function sign(int $movieId, string $kind, ?array $user): array
    {
        if (!in_array($kind, ['portada', 'trailer', 'pelicula'], true)) {
            throw new HttpException(400, 'El tipo de recurso no es válido.');
        }
        // Reject before touching a private resource, including direct AJAX requests.
        if ($kind === 'pelicula' && $user === null) {
            throw new HttpException(401, 'Inicia sesión para ver esta película.');
        }
        $isAdmin = ($user['rol'] ?? '') === 'admin';
        if ($movieId < 1 || $this->movies->find($movieId, !$isAdmin) === null) {
            throw new HttpException(404, 'Este contenido no está disponible.');
        }
        $resource = $this->resources->get($movieId, $kind);
        if ($resource === null) {
            throw new HttpException(404, 'Este archivo todavía no está disponible.');
        }
        $ttl = match ($kind) {
            'pelicula' => (int) $this->config->get('MEDIA_MOVIE_TTL', 3600),
            'trailer' => (int) $this->config->get('MEDIA_TRAILER_TTL', 900),
            default => (int) $this->config->get('MEDIA_POSTER_TTL', 900),
        };
        $ttl = max(60, min($ttl, 14400));
        return [
            'url' => $kind === 'portada'
                ? $this->storage->presignPoster((string) $resource['clave_s3'], $ttl, (string) $resource['mime'])
                : $this->storage->presignGet((string) $resource['clave_s3'], $ttl, (string) $resource['mime']),
            'expires_in' => $ttl,
            'kind' => $kind,
        ];
    }

    public function playbackToken(int $movieId, string $kind): string
    {
        if (!in_array($kind, ['trailer', 'pelicula'], true)) {
            throw new HttpException(400, 'El tipo de reproducción no es válido.');
        }
        $tokens = $_SESSION['playback_tokens'] ?? [];
        foreach ($tokens as $token => $entry) {
            if (($entry['expires'] ?? 0) < time()) {
                unset($tokens[$token]);
            }
        }
        // Bound server session size, while supporting multiple open player tabs.
        if (count($tokens) >= 30) {
            $tokens = array_slice($tokens, -29, null, true);
        }
        $token = bin2hex(random_bytes(32));
        $tokens[$token] = ['movie' => $movieId, 'kind' => $kind, 'expires' => time() + 86400];
        $_SESSION['playback_tokens'] = $tokens;
        return $token;
    }

    public function record(int $movieId, string $kind, string $token, array $user): void
    {
        $binding = $_SESSION['playback_tokens'][$token] ?? null;
        if (!is_array($binding) || $binding['movie'] !== $movieId || $binding['kind'] !== $kind || $binding['expires'] < time()) {
            throw new HttpException(403, 'La autorización de reproducción ha vencido. Abre de nuevo esta página.');
        }
        if (!in_array($kind, ['trailer', 'pelicula'], true) || $this->movies->find($movieId, ($user['rol'] ?? '') !== 'admin') === null || $this->resources->get($movieId, $kind) === null) {
            throw new HttpException(404, 'Este contenido no está disponible.');
        }
        $this->movies->recordView((int) $user['id'], $movieId, $token);
    }
}
