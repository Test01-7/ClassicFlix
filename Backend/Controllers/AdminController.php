<?php
declare(strict_types=1);
namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\{Csrf, Http, HttpException, ValidationException};

final class AdminController
{
    public function __construct(private Application $app) {}

    public function index(string $section = 'resumen', ?array $errors = null, ?array $old = null): void
    {
        $this->app->auth()->requireAdmin();
        if ($section === 'resumen' && isset($_GET['id'])) { Http::redirect('/admin/editar', ['id' => self::page($_GET['id'])]); }
        if ($section === 'resumen' && isset($_GET['resenas_pagina'])) { Http::redirect('/admin/resenas', ['resenas_pagina' => self::page($_GET['resenas_pagina'])]); }
        $query = self::search($_GET['q'] ?? '');
        $page = self::page($_GET[$section === 'resenas' ? 'resenas_pagina' : 'pagina'] ?? 1);
        $id = in_array($section, ['editar', 'archivos'], true) ? (int) (filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0) : 0;
        $editing = $id > 0 ? $this->app->movies()->find($id, false) : null;
        if (in_array($section, ['editar', 'archivos'], true) && !$editing) { throw new HttpException(404, 'No existe esa ficha.'); }
        if ($editing) {
            $editing = $this->app->catalog()->decorate([$editing])[0];
            $editing['recursos'] = $this->app->resources()->allForMovie($id);
        }
        $listing = $section === 'peliculas' ? $this->app->movies()->adminPage($page, $query) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        $this->app->render('admin', ['title' => 'El estudio', 'adminSection' => $section, 'adminQuery' => $query, 'listPage' => $page, 'editingMovie' => $editing,
            'moderation' => $section === 'resenas' ? $this->app->reviews()->moderationPage($page, $query) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0],
            'movieListing' => $listing, 'movies' => $this->app->catalog()->decorate($listing['items']),
            'categories' => $this->app->movies()->categories(), 'stats' => $this->app->movies()->stats(),
            'errors' => $errors ?? [], 'old' => $old ?? []]);
    }

    public static function search(mixed $value): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > 120) { throw new HttpException(400, 'La búsqueda admite hasta 120 caracteres.'); }
        return trim($value);
    }

    public static function page(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$page) { throw new HttpException(400, 'La página solicitada no es válida.'); }
        return (int) $page;
    }

    public function deleteReview(): void
    {
        $this->app->auth()->requireAdmin();
        Csrf::validate();
        $id = filter_var($_POST['resena_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = filter_var($_POST['resenas_pagina'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !$page) { throw new HttpException(400, 'Selecciona una reseña y una página válidas.'); }
        $query = self::search($_POST['q'] ?? '');
        $this->app->reviewService()->moderate((int) $id);
        $this->app->flash('success', 'Reseña eliminada por moderación. La valoración del público se ha actualizado.');
        Http::redirect('/admin/resenas', ['resenas_pagina' => $page, 'q' => $query]);
    }

    public function save(): void
    {
        $this->app->auth()->requireAdmin();
        Csrf::validate();
        $query = self::search($_POST['q'] ?? '');
        $page = self::page($_POST['pagina'] ?? 1);
        $id = max(0, (int) Http::input('id')) ?: null;
        if ($id && !$this->app->movies()->find($id, false)) { throw new HttpException(404, 'No existe esa ficha.'); }
        $data = [];
        foreach (['titulo', 'fechalanzamiento', 'categoria_id', 'director', 'actores', 'sinopsis', 'duracion', 'trailer_duracion', 'estado'] as $field) {
            $data[$field] = Http::input($field);
        }
        $errors = [];
        foreach (['titulo' => 255, 'director' => 100, 'actores' => 255, 'sinopsis' => 10000] as $field => $max) {
            if (mb_strlen($data[$field]) > $max) { $errors[$field] = 'Este campo es demasiado largo.'; }
        }
        if (mb_strlen($data['titulo']) < 1) { $errors['titulo'] = 'Escribe el título.'; }
        if (!ctype_digit($data['fechalanzamiento']) || (int) $data['fechalanzamiento'] < 1888 || (int) $data['fechalanzamiento'] > (int) date('Y') + 1) {
            $errors['fechalanzamiento'] = 'Introduce un año válido.';
        }
        $categories = $this->app->movies()->categories();
        $category = null;
        foreach ($categories as $candidate) { if ((int) $candidate['id'] === (int) $data['categoria_id']) { $category = $candidate; } }
        if (!$category) { $errors['categoria_id'] = 'Selecciona una categoría.'; }
        if (!ctype_digit($data['duracion']) || (int) $data['duracion'] < 1 || (int) $data['duracion'] > 86400) {
            $errors['duracion'] = 'Introduce una duración entre 1 y 86400 segundos.';
        }
        $data['trailer_duracion'] = $data['trailer_duracion'] === '' ? '0' : $data['trailer_duracion'];
        if (!ctype_digit($data['trailer_duracion']) || (int) $data['trailer_duracion'] > 86400) {
            $errors['trailer_duracion'] = 'Introduce una duración entre 0 y 86400 segundos o deja el campo vacío.';
        }
        if (!in_array($data['estado'], ['borrador', 'publicado'], true)) { $errors['estado'] = 'Selecciona un estado válido.'; }
        if ($data['estado'] === 'publicado' && (!$id || !$this->app->resources()->readyToPublish($id))) {
            $errors['estado'] = 'Guarda el borrador y carga una portada y al menos un tráiler o una película completa antes de publicarlo.';
        }
        if ($errors) {
            http_response_code(422);
            $_GET['id'] = $id ?? 0;
            $_GET['q'] = $query; $_GET['pagina'] = $page;
            $this->index($id ? 'editar' : 'nuevo', $errors, $data + ['id' => $id]);
            return;
        }
        $data['genero'] = $category['nombre'];
        $savedId = $this->app->movies()->save($data, $id);
        $this->app->movies()->setState($savedId, $data['estado']);
        $this->app->catalog()->invalidate();
        $this->app->flash('success', $data['estado'] === 'publicado' ? 'Contenido publicado en el catálogo.' : 'Borrador guardado. Ya puedes cargar sus archivos.');
        Http::redirect('/admin/editar', ['id' => $savedId, 'q' => $query, 'pagina' => $page]);
    }
}
