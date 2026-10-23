<?php
declare(strict_types=1);

namespace ClassicFlix\Services;

use ClassicFlix\Application;

final class CatalogService
{
    public function __construct(private Application $app) {}

    public function all(string $query = '', ?int $categoryId = null): array
    {
        $movies = $this->app->cache()->remember('classicflix:catalog:v1',
            fn(): array => $this->app->movies()->all(), (int) $this->app->config()->get('CATALOG_CACHE_TTL', 300));
        $needle = mb_strtolower($query);
        return array_values(array_filter($movies, static fn(array $movie): bool =>
            ($categoryId === null || (int) $movie['categoria_id'] === $categoryId) &&
            ($query === '' || str_contains(mb_strtolower($movie['titulo'] . ' ' . ($movie['director'] ?? '') . ' ' . ($movie['actores'] ?? '') . ' ' . ($movie['sinopsis'] ?? '')), $needle))));
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

    public function invalidate(): void { $this->app->cache()->forget('classicflix:catalog:v1'); }
}
