<?php
declare(strict_types=1);
namespace ClassicFlix\Support;

final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
    }

    public static function validate(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_token'] ?? '';
        if (!is_string($token) || !hash_equals(self::token(), $token)) {
            throw new HttpException(419, 'La sesión del formulario venció. Recarga la página e inténtalo otra vez.');
        }
    }
}
