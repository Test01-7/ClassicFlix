<?php
declare(strict_types=1);

namespace ClassicFlix\Services;

use ClassicFlix\Application;

final class CatalogService
{
    public const CACHE_KEY = 'classicflix:catalog:v2';
    public function __construct(private Application $app) {}

    public function all(string $query = '', ?int $categoryId = null, ?int $minimumRating = null, string $order = 'recientes'): array
    {
        $movies = $this->app->cache()->remember(self::CACHE_KEY,
            fn(): array => $this->app->movies()->all(), (int) $this->app->config()->get('CATALOG_CACHE_TTL', 300));
        return self::filter($movies, $query, $categoryId, $minimumRating, $order);
    }

    public static function options(array $input): array
    {
        $minimum = $input['valoracion_min'] ?? '';
        $order = $input['orden'] ?? 'recientes';
        if ((!is_string($minimum) && !is_int($minimum)) || ($minimum !== '' && !preg_match('/^[1-5]$/D', (string) $minimum)) || !is_string($order) || !in_array($order, ['recientes', 'valoracion'], true)) {
            throw new \ClassicFlix\Support\HttpException(400, 'Selecciona una valoración mínima y un orden válidos.');
        }
        return ['minimum' => $minimum === '' ? null : (int) $minimum, 'order' => $order];
    }

    public static function filter(array $movies, string $query = '', ?int $categoryId = null, ?int $minimumRating = null, string $order = 'recientes'): array
    {
        $needle = mb_strtolower($query);
        $movies = array_values(array_filter($movies, static fn(array $movie): bool =>
            ($categoryId === null || (int) $movie['categoria_id'] === $categoryId) &&
            ($minimumRating === null || ((int) ($movie['valoraciones_total'] ?? 0) > 0 && round((float) $movie['valoracion_media'], 1) >= $minimumRating)) &&
            ($query === '' || str_contains(mb_strtolower($movie['titulo'] . ' ' . ($movie['director'] ?? '') . ' ' . ($movie['actores'] ?? '') . ' ' . ($movie['sinopsis'] ?? '')), $needle))));
        usort($movies, static function (array $a, array $b) use ($order): int {
            if ($order === 'valoracion') {
                $rating = ($b['valoracion_media'] ?? -1) <=> ($a['valoracion_media'] ?? -1);
                if ($rating !== 0) { return $rating; }
                $votes = ((int) ($b['valoraciones_total'] ?? 0)) <=> ((int) ($a['valoraciones_total'] ?? 0));
                if ($votes !== 0) { return $votes; }
            }
            return (int) $b['id'] <=> (int) $a['id'];
        });
        return $movies;
    }

    public function decorate(array $movies, ?array $user = null): array
    {
        $favoriteIds = [];
        if ($user) {
            foreach ($this->app->movies()->favorites((int) $user['id']) as $fav) { $favoriteIds[(int) $fav['id']] = true; }
        }
        return array_map(static function (array $movie) use ($favoriteIds): array {
            $movie['poster_url'] = !empty($movie['poster_key']) ? \url('/portada', ['id' => $movie['id']]) : '';
            $movie['has_poster'] = !empty($movie['poster_key']);
            $movie['is_favorite'] = isset($favoriteIds[(int) $movie['id']]);
            return $movie;
        }, $movies);
    }

    public function invalidate(): void { $this->app->cache()->forget(self::CACHE_KEY); }
}
