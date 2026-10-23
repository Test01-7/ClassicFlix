<?php
declare(strict_types=1);

namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\Csrf;
use ClassicFlix\Support\Http;
use ClassicFlix\Support\HttpException;

final class MediaController
{
    public function __construct(private readonly Application $app) {}

    public function playback(): void
    {
        $kind = (string) ($_GET['tipo'] ?? 'trailer');
        if (!in_array($kind, ['trailer', 'pelicula'], true)) {
            throw new HttpException(400, 'El tipo de reproducción no es válido.');
        }
        // Enforce the private route before constructing repositories/connecting DB.
        $user = $kind === 'pelicula' ? $this->app->auth()->requireUser() : $this->app->auth()->user();
        Http::json($this->app->media()->sign((int) ($_GET['id'] ?? 0), $kind, $user));
    }

    public function poster(): void
    {
        $media = $this->app->media()->sign((int) ($_GET['id'] ?? 0), 'portada', $this->app->auth()->user());
        header('Cache-Control: private, no-store');
        header('Location: ' . $media['url'], true, 302);
        exit;
    }

    public function record(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            throw new HttpException(405, 'Esta operación requiere POST.');
        }
        $user = $this->app->auth()->requireUser();
        Csrf::validate();
        $this->app->media()->record((int) ($_POST['pelicula_id'] ?? 0), (string) ($_POST['tipo'] ?? ''), (string) ($_POST['playback_token'] ?? ''), $user);
        Http::json(['recorded' => true]);
    }
}
