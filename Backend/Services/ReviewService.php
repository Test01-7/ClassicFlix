<?php
declare(strict_types=1);
namespace ClassicFlix\Services;

use ClassicFlix\Application;
use ClassicFlix\Support\{HttpException, ValidationException};

final class ReviewService
{
    public function __construct(private Application $app) {}

    public function save(int $movieId, array $input): void
    {
        $user = $this->app->auth()->requireUser();
        $this->publishedMovie($movieId);
        $text = is_string($input['resena'] ?? null) ? trim($input['resena']) : '';
        $rating = $input['valoracion'] ?? '';
        $errors = [];
        if (!is_string($rating) && !is_int($rating)) { $rating = ''; }
        if (!preg_match('/^[1-5]$/D', (string) $rating)) { $errors['valoracion'] = 'Selecciona una valoración entera de 1 a 5 estrellas.'; }
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/^\s*$/u', $text) || mb_strlen($text) > 2000) {
            $errors['resena'] = 'Escribe una reseña de entre 1 y 2000 caracteres.';
        }
        if ($errors) { throw new ValidationException($errors); }
        $this->app->reviews()->saveOwn((int) $user['id'], $movieId, $text, (int) $rating);
        $this->app->catalog()->invalidate();
    }

    public function delete(int $movieId): void
    {
        $user = $this->app->auth()->requireUser();
        $this->publishedMovie($movieId);
        if (!$this->app->reviews()->deleteOwn((int) $user['id'], $movieId)) {
            throw new HttpException(404, 'No tienes una reseña para este título.');
        }
        $this->app->catalog()->invalidate();
    }

    public function moderate(int $reviewId): void
    {
        $this->app->auth()->requireAdmin();
        // Moderation also covers titles that have since been returned to draft.
        if ($reviewId < 1 || !$this->app->reviews()->deleteById($reviewId)) {
            throw new HttpException(404, 'Esta reseña ya no existe.');
        }
        $this->app->catalog()->invalidate();
    }

    private function publishedMovie(int $id): void
    {
        if ($id < 1 || !$this->app->movies()->find($id)) { throw new HttpException(404, 'Este título no está disponible para recibir opiniones.'); }
    }
}
