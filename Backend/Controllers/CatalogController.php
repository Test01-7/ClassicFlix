<?php
declare(strict_types=1);
namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\{Csrf, Http, HttpException};

final class CatalogController
{
    public function __construct(private Application $app) {}

    public function index(string $section = 'catalogo'): void
    {
        $user = $this->app->auth()->user();
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
        $category = is_scalar($_GET['categoria'] ?? null) ? (string) $_GET['categoria'] : '';
        $options = \ClassicFlix\Services\CatalogService::options($_GET);
        if ($section === 'catalogo') { $movies = $this->app->catalog()->all($query, (int) $category > 0 ? (int) $category : null, $options['minimum'], $options['order']); }
        else {
            $user = $this->app->auth()->requireUser();
            $movies = $section === 'favoritos' ? $this->app->movies()->favorites((int) $user['id']) : $this->app->movies()->history((int) $user['id']);
        }
        $this->app->render('catalog', ['title' => match ($section) { 'favoritos' => 'Mi lista', 'historial' => 'Mi historial', default => 'La sala de los clásicos' },
            'section' => $section, 'user' => $user, 'movies' => $this->app->catalog()->decorate($movies, $user),
            'categories' => $this->app->movies()->categories(), 'query' => $query, 'category' => $category,
            'minimumRating' => $options['minimum'], 'ratingOrder' => $options['order']]);
    }

    private function movie(): array
    {
        $user = $this->app->auth()->user();
        $movie = $this->app->movies()->find((int) ($_GET['id'] ?? 0), ($user['rol'] ?? '') !== 'admin');
        if (!$movie) { throw new HttpException(404, 'Este título no está disponible.'); }
        return $this->app->catalog()->decorate([$movie], $user)[0];
    }

    public function detail(array $data = []): void
    {
        $movie = $this->movie();
        $user = $this->app->auth()->user();
        $page = filter_var($_GET['resenas_pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) { throw new HttpException(400, 'La página de opiniones no es válida.'); }
        $this->app->render('detail', array_replace(['title' => $movie['titulo'], 'movie' => $movie,
            'reviews' => $this->app->reviews()->paginate((int) $movie['id'], (int) $page),
            'ownReview' => $user ? $this->app->reviews()->findOwn((int) $user['id'], (int) $movie['id']) : null], $data));
    }

    public function player(): void
    {
        $kind = ($_GET['tipo'] ?? '') === 'pelicula' ? 'pelicula' : 'trailer';
        if ($kind === 'pelicula') { $this->app->auth()->requireUser(); }
        $movie = $this->movie();
        if (empty($movie[$kind === 'pelicula' ? 'has_movie' : 'has_trailer'])) {
            throw new HttpException(404, $kind === 'pelicula' ? 'La película completa todavía no está disponible.' : 'Este tráiler todavía no está disponible.');
        }
        $token = $this->app->media()->playbackToken((int) $movie['id'], $kind);
        $this->app->render('player', ['title' => $movie['titulo'], 'movie' => $movie, 'kind' => $kind,
            'playbackToken' => $token, 'mediaEndpoint' => \url('/media', ['id' => $movie['id'], 'tipo' => $kind])]);
    }

    public function favorite(): void
    {
        $user = $this->app->auth()->requireUser();
        Csrf::validate();
        $id = (int) ($_POST['pelicula_id'] ?? 0);
        if (!$this->app->movies()->find($id)) { throw new HttpException(404, 'Este título no está disponible.'); }
        $added = $this->app->movies()->toggleFavorite((int) $user['id'], $id);
        $this->app->flash('success', $added ? 'Título añadido a tu lista.' : 'Título eliminado de tu lista.');
        Http::redirect(Http::safeNext($_POST['return'] ?? '/favoritos'));
    }
}
