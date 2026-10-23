<?php
declare(strict_types=1);
namespace ClassicFlix\Support;

final class Http
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function redirect(string $route, array $params = []): never
    {
        header('Location: ' . \url($route, $params), true, 303);
        exit;
    }

    public static function safeNext(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 500 || preg_match('/[\r\n]/', $value)) {
            return '/catalogo';
        }
        $parts = parse_url($value);
        if (!$parts || isset($parts['scheme']) || isset($parts['host'])) {
            return '/catalogo';
        }
        $path = $parts['path'] ?? '';
        if (!in_array($path, ['/catalogo', '/detalle', '/reproducir', '/favoritos', '/historial', '/admin', '/admin/editar'], true)) {
            return '/catalogo';
        }
        parse_str($parts['query'] ?? '', $params);
        $safe = [];
        if (isset($params['id']) && is_scalar($params['id']) && ctype_digit((string) $params['id'])) {
            $safe['id'] = (int) $params['id'];
        }
        if ($path === '/reproducir') {
            $safe['tipo'] = ($params['tipo'] ?? '') === 'pelicula' ? 'pelicula' : 'trailer';
        }
        return $path . ($safe ? '?' . http_build_query($safe) : '');
    }

    public static function input(string $name, string $default = ''): string
    {
        $value = $_POST[$name] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}
