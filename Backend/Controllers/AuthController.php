<?php
declare(strict_types=1);
namespace ClassicFlix\Controllers;

use ClassicFlix\Application;
use ClassicFlix\Support\{Csrf, Http, ValidationException};

final class AuthController
{
    public function __construct(private Application $app) {}

    public function form(bool $register): void
    {
        $next = Http::safeNext($_GET['next'] ?? '/catalogo');
        if ($this->app->auth()->user()) { Http::redirect($next); }
        $this->app->render('auth', ['title' => $register ? 'Crear cuenta' : 'Iniciar sesión', 'register' => $register, 'next' => $next]);
    }

    public function submit(bool $register): void
    {
        Csrf::validate();
        $next = Http::safeNext($_POST['next'] ?? '/catalogo');
        $old = ['nombre' => Http::input('nombre'), 'email' => Http::input('email')];
        // No recortar contraseñas: sus espacios forman parte del secreto elegido.
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        try {
            if ($register) {
                $this->app->auth()->register($old + ['password' => $password,
                    'password_confirmation' => is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '']);
            } else { $this->app->auth()->login($old['email'], $password); }
            $this->app->flash('success', $register ? 'Tu cuenta está lista. Bienvenido a ClassicFlix.' : 'Bienvenido de nuevo.');
            Http::redirect($next);
        } catch (ValidationException $e) {
            http_response_code(422);
            $this->app->render('auth', ['title' => $register ? 'Crear cuenta' : 'Iniciar sesión', 'register' => $register,
                'next' => $next, 'old' => $old, 'errors' => $e->errors]);
        }
    }

    public function logout(): void
    {
        Csrf::validate();
        $this->app->auth()->logout();
        Http::redirect('/catalogo');
    }
}
