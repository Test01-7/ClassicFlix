<?php
declare(strict_types=1);
namespace ClassicFlix;

use ClassicFlix\Controllers\{AdminController, AuthController, CatalogController, MediaController, UploadController, ReviewController};
use ClassicFlix\Infrastructure\{Cache, S3Storage};
use ClassicFlix\Repositories\{MovieRepository, ResourceRepository, UploadRepository, UserRepository, ReviewRepository};
use ClassicFlix\Services\{AuthService, CatalogService, MediaService, UploadService, ReviewService};
use ClassicFlix\Support\{Config, Database, Http, HttpException};

final class Application
{
    private array $instances = [];
    public function __construct(private Config $settings, ?Database $database = null)
    {
        if ($database) { $this->instances['database'] = $database; }
    }
    public function config(): Config { return $this->settings; }
    public function database(): Database { return $this->instances['database'] ??= new Database($this->settings); }
    public function users(): UserRepository { return $this->instances['users'] ??= new UserRepository($this->database()->pdo()); }
    public function movies(): MovieRepository { return $this->instances['movies'] ??= new MovieRepository($this->database()->pdo()); }
    public function reviews(): ReviewRepository { return $this->instances['reviews'] ??= new ReviewRepository($this->database()->pdo()); }
    public function reviewService(): ReviewService { return $this->instances['reviewService'] ??= new ReviewService($this); }
    public function resources(): ResourceRepository { return $this->instances['resources'] ??= new ResourceRepository($this->database()->pdo()); }
    public function uploadRepository(): UploadRepository { return $this->instances['uploadRepository'] ??= new UploadRepository($this->database()->pdo()); }
    public function auth(): AuthService { return $this->instances['auth'] ??= new AuthService($this); }
    public function catalog(): CatalogService { return $this->instances['catalog'] ??= new CatalogService($this); }
    public function cache(): Cache { return $this->instances['cache'] ??= new Cache($this->settings); }
    public function storage(): S3Storage { return $this->instances['storage'] ??= new S3Storage($this->settings); }
    public function uploads(): UploadService
    {
        return $this->instances['uploads'] ??= new UploadService($this->settings, $this->database()->pdo(), $this->uploadRepository(), $this->resources(), $this->movies(), $this->storage(), $this->cache());
    }
    public function media(): MediaService
    {
        return $this->instances['media'] ??= new MediaService($this->settings, $this->movies(), $this->resources(), $this->storage());
    }
    public function flash(string $type, string $message): void { $_SESSION['flash'] = compact('type', 'message'); }
    public function render(string $template, array $data = []): void
    {
        $defaultUser = null;
        if (!array_key_exists('user', $data)) {
            try { $defaultUser = $this->auth()->user(); } catch (\Throwable) { /* Render errors without a database. */ }
        }
        $defaults = ['title' => 'ClassicFlix', 'preview' => false, 'previewEnabled' => $this->settings->previewEnabled(), 'user' => $defaultUser, 'errors' => [], 'old' => [], 'flash' => $_SESSION['flash'] ?? null, 'movies' => [], 'categories' => [], 'section' => 'catalogo', 'query' => '', 'category' => '', 'editingMovie' => null, 'stats' => ['peliculas' => 0, 'usuarios' => 0, 'categorias' => 0]];
        $defaults['uploadLimits'] = ['portada' => (int) $this->settings->get('UPLOAD_POSTER_MAX_BYTES', 5242880), 'trailer' => (int) $this->settings->get('UPLOAD_TRAILER_MAX_BYTES', 104857600), 'pelicula' => (int) $this->settings->get('UPLOAD_MOVIE_MAX_BYTES', 2147483648)];
        $defaults += ['minimumRating' => null, 'ratingOrder' => 'recientes', 'ownReview' => null, 'reviewErrors' => [], 'reviewOld' => [],
            'adminSection' => 'resumen', 'adminQuery' => '', 'listPage' => 1, 'movieListing' => ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0],
            'reviews' => ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0],
            'moderation' => ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0]];
        unset($_SESSION['flash']);
        extract(array_replace($defaults, $data), EXTR_SKIP);
        $GLOBALS['classicflix_preview'] = $preview;
        $contentTemplate = __DIR__ . '/Views/' . $template . '.php';
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/Views/layout.php';
    }
    public function run(): void
    {
        $route = is_string($_GET['r'] ?? null) ? $_GET['r'] : '/catalogo';
        if ($route === '/') { $route = '/catalogo'; }
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $json = str_starts_with($route, '/upload/') || in_array($route, ['/media', '/historial/registrar'], true);
        header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('X-Frame-Options: DENY'); header('Cache-Control: no-store');
        try {
            if ($route === '/preview') {
                if ($method !== 'GET') { throw new HttpException(405, 'La vista previa es de solo lectura.'); }
                $this->preview(); return;
            }
            $getRoutes = ['/catalogo', '/login', '/registro', '/detalle', '/reproducir', '/favoritos', '/historial', '/admin', '/admin/peliculas', '/admin/nuevo', '/admin/editar', '/admin/archivos', '/admin/resenas', '/media', '/portada'];
            $postRoutes = ['/login', '/registro', '/logout', '/favoritos', '/admin/guardar', '/admin/resenas/eliminar', '/historial/registrar', '/upload/iniciar', '/upload/parte', '/upload/completar', '/upload/cancelar', '/resenas/guardar', '/resenas/eliminar'];
            if (!in_array($route, array_unique([...$getRoutes, ...$postRoutes]), true)) { throw new HttpException(404, 'No encontramos esta página.'); }
            if (($method === 'GET' && !in_array($route, $getRoutes, true)) || ($method === 'POST' && !in_array($route, $postRoutes, true)) || !in_array($method, ['GET', 'POST'], true)) {
                header('Allow: ' . (in_array($route, $postRoutes, true) ? 'POST' : 'GET'));
                throw new HttpException(405, 'Esta acción no admite ese método.');
            }
            if (str_starts_with($route, '/upload/')) { (new UploadController($this))->handle(substr($route, strlen('/upload/'))); return; }
            if ($method === 'POST') {
                match ($route) {
                    '/login' => (new AuthController($this))->submit(false), '/registro' => (new AuthController($this))->submit(true),
                    '/logout' => (new AuthController($this))->logout(), '/favoritos' => (new CatalogController($this))->favorite(),
                    '/admin/guardar' => (new AdminController($this))->save(), '/historial/registrar' => (new MediaController($this))->record(),
                    '/resenas/guardar' => (new ReviewController($this))->save(), '/resenas/eliminar' => (new ReviewController($this))->delete(),
                    '/admin/resenas/eliminar' => (new AdminController($this))->deleteReview(),
                }; return;
            }
            match ($route) {
                '/login' => (new AuthController($this))->form(false), '/registro' => (new AuthController($this))->form(true),
                '/catalogo' => (new CatalogController($this))->index(), '/favoritos' => (new CatalogController($this))->index('favoritos'),
                '/historial' => (new CatalogController($this))->index('historial'), '/detalle' => (new CatalogController($this))->detail(),
                '/reproducir' => (new CatalogController($this))->player(), '/admin' => (new AdminController($this))->index(),
                '/admin/peliculas' => (new AdminController($this))->index('peliculas'), '/admin/nuevo' => (new AdminController($this))->index('nuevo'),
                '/admin/editar' => (new AdminController($this))->index('editar'), '/admin/archivos' => (new AdminController($this))->index('archivos'),
                '/admin/resenas' => (new AdminController($this))->index('resenas'),
                '/media' => (new MediaController($this))->playback(), '/portada' => (new MediaController($this))->poster(),
            };
        } catch (HttpException $e) {
            if ($json) { Http::json(['error' => $e->getMessage()], $e->status); }
            if ($e->status === 401 && $method === 'GET') {
                $params = array_intersect_key($_GET, ['id' => true, 'tipo' => true]);
                Http::redirect('/login', ['next' => Http::safeNext($route . ($params ? '?' . http_build_query($params) : ''))]);
            }
            http_response_code($e->status);
            $this->render('error', ['title' => 'Una pausa en la función', 'message' => $e->getMessage(), 'status' => $e->status]);
        } catch (\Throwable $e) {
            error_log(sprintf('ClassicFlix: %s at %s:%d', $e::class, basename($e->getFile()), $e->getLine()));
            if ($json) { Http::json(['error' => 'El servicio no está disponible. Comprueba la configuración e inténtalo de nuevo.'], 503); }
            http_response_code(503);
            $this->render('error', ['title' => 'Preparando la sala', 'message' => 'No se pudo completar la conexión con los servicios. Revisa la configuración de ClassicFlix o vuelve a intentarlo en unos momentos.', 'status' => 503]);
        }
    }
    private function preview(): void
    {
        if (!$this->settings->previewEnabled()) { throw new HttpException(404, 'No encontramos esta página.'); }
        $screen = is_string($_GET['screen'] ?? null) ? $_GET['screen'] : 'catalogo';
        if (!in_array($screen, ['login', 'registro', 'catalogo', 'detalle', 'reproducir', 'admin', 'favoritos', 'historial'], true)) { throw new HttpException(404, 'Esta pantalla de muestra no existe.'); }
        $movies = \ClassicFlix\Preview\Fixtures::movies(); $movie = $movies[0];
        foreach ($movies as $candidate) { if ((int) $candidate['id'] === (int) ($_GET['id'] ?? 0)) { $movie = $candidate; } }
        $user = in_array($screen, ['login', 'registro', 'catalogo', 'detalle'], true) ? null : \ClassicFlix\Preview\Fixtures::user();
        if ($screen === 'admin') { $user['rol'] = 'admin'; }
        $query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 120) : '';
        $category = is_scalar($_GET['categoria'] ?? null) ? (string) $_GET['categoria'] : '';
        $options = CatalogService::options($_GET);
        $filtered = CatalogService::filter($movies, $query, (int) $category > 0 ? (int) $category : null, $options['minimum'], $options['order']);
        if ($screen === 'favoritos') { $filtered = array_values(array_filter($movies, static fn(array $item): bool => !empty($item['is_favorite']))); }
        if ($screen === 'historial') { $filtered = array_slice($movies, 0, 3); }
        $template = match ($screen) { 'login', 'registro' => 'auth', 'detalle' => 'detail', 'reproducir' => 'player', 'admin' => 'admin', default => 'catalog' };
        $sampleReviews = \ClassicFlix\Preview\Fixtures::reviews((int) $movie['id']);
        $adminSection = is_string($_GET['panel'] ?? null) ? $_GET['panel'] : (isset($_GET['id']) ? 'editar' : 'resumen');
        if ($screen === 'admin' && !in_array($adminSection, ['resumen', 'peliculas', 'nuevo', 'editar', 'archivos', 'resenas'], true)) { throw new HttpException(404, 'Esta sección no existe.'); }
        $adminQuery = $screen === 'admin' ? AdminController::search($_GET['q'] ?? '') : '';
        $adminPage = $screen === 'admin' ? AdminController::page($_GET[$adminSection === 'resenas' ? 'resenas_pagina' : 'pagina'] ?? 1) : 1;
        $sampleModeration = [];
        foreach ($movies as $film) { foreach (\ClassicFlix\Preview\Fixtures::reviews((int) $film['id']) as $review) { $sampleModeration[] = $review + ['pelicula_id' => $film['id'], 'pelicula' => $film['titulo']]; } }
        usort($sampleModeration, static fn(array $a, array $b): int => [$b['fecha'], $b['id']] <=> [$a['fecha'], $a['id']]);
        $sampleModeration = array_values(array_filter($sampleModeration, static fn(array $r): bool => $adminQuery === '' || str_contains(mb_strtolower($r['autor'] . ' ' . $r['resena']), mb_strtolower($adminQuery))));
        $sampleFilms = array_values(array_filter($movies, static fn(array $m): bool => $adminQuery === '' || str_contains(mb_strtolower($m['titulo']), mb_strtolower($adminQuery))));
        $reviewPages = max(1, (int) ceil(count($sampleModeration) / 10));
        $reviewPage = min($adminPage, $reviewPages);
        $sampleListing = ['items' => $sampleFilms, 'page' => 1, 'pages' => 1, 'total' => count($sampleFilms)];
        $this->render($template, ['title' => 'Vista previa · ClassicFlix', 'preview' => true, 'user' => $user, 'register' => $screen === 'registro', 'next' => '/catalogo',
            'movies' => $screen === 'admin' ? $sampleFilms : $filtered, 'movie' => $movie, 'categories' => \ClassicFlix\Preview\Fixtures::categories(), 'stats' => \ClassicFlix\Preview\Fixtures::stats(),
            'adminSection' => $adminSection, 'adminQuery' => $adminQuery, 'listPage' => $adminPage, 'movieListing' => $sampleListing,
            'query' => $query, 'category' => $category, 'section' => in_array($screen, ['historial', 'favoritos'], true) ? $screen : 'catalogo',
            'minimumRating' => $options['minimum'], 'ratingOrder' => $options['order'],
            'reviews' => ['items' => $sampleReviews, 'page' => 1, 'pages' => 1, 'total' => count($sampleReviews)],
            'moderation' => ['items' => array_slice($sampleModeration, ($reviewPage - 1) * 10, 10), 'page' => $reviewPage, 'pages' => $reviewPages, 'total' => count($sampleModeration)],
            'kind' => ($_GET['tipo'] ?? '') === 'pelicula' ? 'pelicula' : 'trailer', 'playbackToken' => '', 'mediaEndpoint' => '',
            'editingMovie' => $screen === 'admin' && in_array($adminSection, ['editar', 'archivos'], true) ? $movie : null]);
    }
}
