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
        if (!in_array($path, ['/catalogo', '/detalle', '/reproducir', '/favoritos', '/historial', '/admin', '/admin/peliculas', '/admin/nuevo', '/admin/editar', '/admin/archivos', '/admin/resenas'], true)) {
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
        if (str_starts_with($path, '/admin')) {
            if (is_string($params['q'] ?? null)) { $safe['q'] = mb_substr(trim($params['q']), 0, 120); }
            foreach (['pagina', 'resenas_pagina'] as $pageKey) {
                $page = filter_var($params[$pageKey] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($page) { $safe[$pageKey] = $page; }
            }
        }
        return $path . ($safe ? '?' . http_build_query($safe) : '');
    }

    public static function input(string $name, string $default = ''): string
    {
        $value = $_POST[$name] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }
}
