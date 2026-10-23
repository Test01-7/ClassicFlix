<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $route, array $params = []): string
{
    $parts = parse_url($route);
    $path = $parts['path'] ?? '/catalogo';
    parse_str($parts['query'] ?? '', $existing);
    return 'index.php?' . http_build_query(['r' => $path] + $params + $existing);
}

function asset(string $path): string
{
    return 'assets/' . ltrim($path, '/');
}

function csrf_token(): string { return \ClassicFlix\Support\Csrf::token(); }

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function format_duration(mixed $seconds): string
{
    $seconds = max(0, (int) $seconds);
    return $seconds >= 3600 ? sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60))
        : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function screen_url(string $route, array $params = []): string
{
    if (empty($GLOBALS['classicflix_preview'])) {
        return url($route, $params);
    }
    $screen = match ($route) {
        '/login' => 'login', '/registro' => 'registro', '/detalle' => 'detalle',
        '/reproducir' => 'reproducir', '/favoritos' => 'favoritos', '/historial' => 'historial',
        '/admin', '/admin/editar' => 'admin', default => 'catalogo'
    };
    return url('/preview', ['screen' => $screen] + $params);
}
