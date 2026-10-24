<?php
declare(strict_types=1);
namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\{Csrf, Http, HttpException, ValidationException};

final class ReviewController
{
    public function __construct(private Application $app) {}

    public function save(): void
    {
        $this->app->auth()->requireUser();
        Csrf::validate();
        $movieId = $this->movieId();
        try {
            $this->app->reviewService()->save($movieId, $_POST);
            $this->app->flash('success', 'Tu opinión se guardó. Gracias por compartir tu mirada.');
            Http::redirect('/detalle', ['id' => $movieId]);
        } catch (ValidationException $e) {
            http_response_code(422);
            $_GET['id'] = $movieId;
            $old = ['resena' => is_string($_POST['resena'] ?? null) ? $_POST['resena'] : '',
                'valoracion' => is_scalar($_POST['valoracion'] ?? null) ? (string) $_POST['valoracion'] : ''];
            (new CatalogController($this->app))->detail(['reviewErrors' => $e->errors, 'reviewOld' => $old]);
        }
    }

    public function delete(): void
    {
        $this->app->auth()->requireUser();
        Csrf::validate();
        $movieId = $this->movieId();
        $this->app->reviewService()->delete($movieId);
        $this->app->flash('success', 'Tu reseña y valoración se eliminaron.');
        Http::redirect('/detalle', ['id' => $movieId]);
    }

    private function movieId(): int
    {
        $id = filter_var($_POST['pelicula_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) { throw new HttpException(400, 'Selecciona una película válida.'); }
        return $id;
    }
}
