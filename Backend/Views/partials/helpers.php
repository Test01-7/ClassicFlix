<?php
declare(strict_types=1);

function cf_icon(string $name, string $class = ''): string
{
    $paths = [
        'play' => '<path d="m9 5 11 7-11 7V5Z"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0l-1 1-1-1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'arrow' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'film' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 3v18M16 3v18M4 8h4m8 0h4M4 16h4m8 0h4"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3m2-16a3 3 0 0 1 0 6m1 4a5 5 0 0 1 3 6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14l-1 7Z"/>',
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 15v5h16v-5"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'logout' => '<path d="M9 3H4v18h5m5-5 5-4-5-4M8 12h12"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
    ];
    return '<svg class="icon ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['film']) . '</svg>';
}

function cf_poster(array $movie): string
{
    return (string) (($movie['poster_url'] ?? '') ?: asset('art/poster-placeholder.svg'));
}
