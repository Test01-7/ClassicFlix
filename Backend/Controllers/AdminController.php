<?php
declare(strict_types=1);
namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\{Csrf, Http, HttpException, ValidationException};

final class AdminController
{
    public function __construct(private Application $app) {}

    public function index(?array $errors = null, ?array $old = null): void
    {
        $this->app->auth()->requireAdmin();
        $id = (int) ($_GET['id'] ?? 0);
        $editing = $id > 0 ? $this->app->movies()->find($id, false) : null;
        if ($id > 0 && !$editing) { throw new HttpException(404, 'No existe esa ficha.'); }
        if ($editing) {
            $editing = $this->app->catalog()->decorate([$editing])[0];
            $editing['recursos'] = $this->app->resources()->allForMovie($id);
        }
        $this->app->render('admin', ['title' => 'Cabina de proyección', 'editingMovie' => $editing,
            'movies' => $this->app->catalog()->decorate($this->app->movies()->all('', null, false)),
            'categories' => $this->app->movies()->categories(), 'stats' => $this->app->movies()->stats(),
            'errors' => $errors ?? [], 'old' => $old ?? []]);
    }

    public function save(): void
    {
        $this->app->auth()->requireAdmin();
        Csrf::validate();
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
        if (mb_strlen($data['sinopsis']) < 10) { $errors['sinopsis'] = 'Añade una sinopsis de al menos 10 caracteres.'; }
        if (!ctype_digit($data['fechalanzamiento']) || (int) $data['fechalanzamiento'] < 1888 || (int) $data['fechalanzamiento'] > (int) date('Y') + 1) {
            $errors['fechalanzamiento'] = 'Introduce un año válido.';
        }
        $categories = $this->app->movies()->categories();
        $category = null;
        foreach ($categories as $candidate) { if ((int) $candidate['id'] === (int) $data['categoria_id']) { $category = $candidate; } }
        if (!$category) { $errors['categoria_id'] = 'Selecciona una categoría.'; }
        foreach (['duracion', 'trailer_duracion'] as $field) {
            if (!ctype_digit($data[$field]) || (int) $data[$field] < 1 || (int) $data[$field] > 86400) { $errors[$field] = 'Introduce una duración entre 1 y 86400 segundos.'; }
        }
        if (!in_array($data['estado'], ['borrador', 'publicado'], true)) { $errors['estado'] = 'Selecciona un estado válido.'; }
        if ($data['estado'] === 'publicado' && (!$id || !$this->app->resources()->readyToPublish($id))) {
            $errors['estado'] = 'Guarda el borrador, carga la portada y el tráiler y después publícalo.';
        }
        if ($errors) {
            http_response_code(422);
            $_GET['id'] = $id ?? 0;
            $this->index($errors, $data + ['id' => $id]);
            return;
        }
        $data['genero'] = $category['nombre'];
        $savedId = $this->app->movies()->save($data, $id);
        $this->app->movies()->setState($savedId, $data['estado']);
        $this->app->catalog()->invalidate();
        $this->app->flash('success', $data['estado'] === 'publicado' ? 'Contenido publicado en el catálogo.' : 'Borrador guardado. Ya puedes cargar sus archivos.');
        Http::redirect('/admin/editar', ['id' => $savedId]);
    }
}
