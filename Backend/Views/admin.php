<?php
$editingMovie = $editingMovie ?? null; $formMovie = array_merge($editingMovie ?? [], $old);
$context = ['q' => $adminQuery, 'pagina' => $listPage];
$adminLabels = ['resumen' => 'Vista general', 'peliculas' => 'Películas', 'nuevo' => 'Nuevo contenido', 'editar' => 'Editar película', 'archivos' => 'Archivos de la película', 'resenas' => 'Moderar reseñas'];
$adminHeading = $adminLabels[$adminSection];
$uploadSizeLabel = static fn(int $bytes): string => $bytes >= 1073741824 ? rtrim(rtrim(number_format($bytes / 1073741824, 2, '.', ''), '0'), '.') . ' GB' : rtrim(rtrim(number_format($bytes / 1048576, 2, '.', ''), '0'), '.') . ' MB';
$uploadSlots = ['portada' => ['label' => 'Portada', 'hint' => 'JPEG, PNG o WebP · hasta ' . $uploadSizeLabel($uploadLimits['portada']), 'accept' => 'image/jpeg,image/png,image/webp', 'icon' => 'grid'], 'trailer' => ['label' => 'Tráiler', 'hint' => 'MP4 compatible con HTML5 · hasta ' . $uploadSizeLabel($uploadLimits['trailer']), 'accept' => 'video/mp4', 'icon' => 'play'], 'pelicula' => ['label' => 'Película completa', 'hint' => 'MP4 compatible con HTML5 · hasta ' . $uploadSizeLabel($uploadLimits['pelicula']), 'accept' => 'video/mp4', 'icon' => 'film']];
?>
<div class="admin-shell admin-paged">
<aside class="admin-sidebar" aria-label="Administración">
    <a class="brand" href="<?= e(screen_url('/catalogo')) ?>"><span class="brand-emblem" aria-hidden="true"><span>C</span></span><span>CLASSIC<span class="brand-light">FLIX</span><small>DETRÁS DE LA PANTALLA</small></span></a>
    <div class="admin-desktop-navigation"><p class="sidebar-label">EL ESTUDIO</p><?php require __DIR__ . '/partials/admin-navigation.php'; ?></div>
    <details class="admin-mobile-menu"><summary>Secciones <span aria-hidden="true">▾</span></summary><?php require __DIR__ . '/partials/admin-navigation.php'; ?></details>
    <div class="sidebar-bottom"><div class="sidebar-quote"><span aria-hidden="true">✦</span><p>Cada gran función<br>empieza aquí.</p></div><div class="admin-person"><span class="avatar"><?= e(mb_strtoupper(mb_substr($user['nombre'] ?? 'A', 0, 1))) ?></span><div><strong><?= e($user['nombre'] ?? 'Administración') ?></strong><small>ADMINISTRADOR</small></div><?php if (!$preview): ?><form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="icon-button" aria-label="Cerrar sesión"><?= cf_icon('logout') ?></button></form><?php endif ?></div></div>
</aside>
<main id="main" class="admin-main">
    <header class="admin-topbar"><span class="admin-breadcrumb">EL ESTUDIO <span>/</span> <?= e(mb_strtoupper($adminHeading)) ?></span><a class="quiet-link" href="<?= e(screen_url('/catalogo')) ?>">Ir a ClassicFlix <?= cf_icon('arrow') ?></a></header>
    <div class="admin-content">
        <div class="section-heading admin-heading"><div><p class="eyebrow">DETRÁS DE CADA GRAN FUNCIÓN</p><h1><?= e($adminSection === 'resumen' ? 'Tu sala de control' : $adminHeading) ?><span class="title-period">.</span></h1><p class="section-description"><?= e(match($adminSection) { 'resumen' => 'Un vistazo a tu colección y accesos para trabajar en ella.', 'peliculas' => 'Busca una película para editar sus datos o gestionar sus archivos.', 'resenas' => 'Busca opiniones por autor o por una palabra del texto.', default => $editingMovie ? $editingMovie['titulo'] : 'Guarda primero los datos. Después podrás cargar la portada y los videos.' }) ?></p></div><?php if (in_array($adminSection, ['resumen', 'peliculas'], true)): ?><a href="<?= e(screen_url('/admin/nuevo')) ?>" class="button button-gold button-small"><?= cf_icon('plus') ?> Nuevo contenido</a><?php endif ?></div>
        <?php if (in_array($adminSection, ['nuevo', 'editar', 'archivos'], true)): ?>
        <a class="back-link admin-back" href="<?= e(screen_url('/admin/peliculas', $context)) ?>">← Volver a películas</a>
        <?php if ($editingMovie): ?><nav class="admin-editor-tabs" aria-label="Edición de la película"><a href="<?= e(screen_url('/admin/editar', ['id' => $editingMovie['id']] + $context)) ?>" <?= $adminSection === 'editar' ? 'aria-current="page"' : '' ?>>Datos de la película</a><a href="<?= e(screen_url('/admin/archivos', ['id' => $editingMovie['id']] + $context)) ?>" <?= $adminSection === 'archivos' ? 'aria-current="page"' : '' ?>>Portada y videos</a></nav><?php endif ?>
        <?php endif ?>
        <?php require __DIR__ . '/partials/' . match ($adminSection) { 'resumen' => 'admin-overview.php', 'peliculas' => 'admin-movies.php', 'resenas' => 'admin-reviews.php', 'archivos' => 'admin-uploads.php', default => 'admin-editor.php' }; ?>
        <footer class="admin-footer"><span>CLASSICFLIX <span aria-hidden="true">✦</span> EL ESTUDIO</span><span>Detrás de cada historia, una buena colección.</span></footer>
    </div>
</main></div>