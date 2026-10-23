<?php
declare(strict_types=1);
require_once __DIR__ . '/partials/helpers.php';
$preview = $preview ?? false;
$user = $user ?? null;
$title = $title ?? 'El cine vive para siempre';
$errors = $errors ?? [];
$old = $old ?? [];
$authPage = str_ends_with($contentTemplate, 'auth.php');
$adminPage = str_ends_with($contentTemplate, 'admin.php');
$activeSection = $section ?? 'catalogo';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#101110">
    <meta name="description" content="ClassicFlix. Una nueva forma de volver a los grandes clásicos del cine.">
    <title><?= e($title) ?> · ClassicFlix</title>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset('art/favicon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
    <?php if ($adminPage): ?><script src="<?= e(asset('js/upload.js')) ?>" defer></script><?php endif ?>
    <?php if (str_ends_with($contentTemplate, 'player.php')): ?><script src="<?= e(asset('js/player.js')) ?>" defer></script><?php endif ?>
</head>
<body class="<?= $authPage ? 'auth-body' : ($adminPage ? 'admin-body' : 'cinema-body') ?><?= $preview ? ' is-preview' : '' ?>">
<a class="skip-link" href="#main">Saltar al contenido</a>
<?php if ($preview): ?>
    <div class="preview-bar"><span><span class="status-dot"></span> Vista de muestra <span class="preview-explanation">· Datos ficticios, sin operaciones reales</span></span><nav aria-label="Pantallas de muestra"><a href="<?= e(url('/preview', ['screen' => 'catalogo'])) ?>">Catálogo</a><a href="<?= e(url('/preview', ['screen' => 'login'])) ?>">Login</a><a href="<?= e(url('/preview', ['screen' => 'registro'])) ?>">Registro</a><a href="<?= e(url('/preview', ['screen' => 'admin'])) ?>">Panel</a></nav></div>
<?php endif ?>
<?php if (!$adminPage): ?>
    <header class="site-header<?= $authPage ? ' auth-header' : '' ?>">
        <a class="brand" href="<?= e(screen_url('/catalogo')) ?>" aria-label="ClassicFlix, inicio"><span class="brand-emblem" aria-hidden="true"><span>C</span></span><span>CLASSIC<span class="brand-light">FLIX</span><small>EL ARTE DE VOLVER A MIRAR</small></span></a>
        <?php if (!$authPage): ?>
        <button class="icon-button menu-toggle" type="button" aria-expanded="false" aria-controls="main-navigation" aria-label="Abrir navegación"><?= cf_icon('menu') ?></button>
        <nav class="main-navigation" id="main-navigation" aria-label="Principal">
            <a class="nav-link <?= $activeSection === 'catalogo' ? 'active' : '' ?>" href="<?= e(screen_url('/catalogo')) ?>">Descubrir</a>
            <a class="nav-link <?= $activeSection === 'favoritos' ? 'active' : '' ?>" href="<?= e(screen_url('/favoritos')) ?>">Mi lista</a>
            <a class="nav-link <?= $activeSection === 'historial' ? 'active' : '' ?>" href="<?= e(screen_url('/historial')) ?>">Historial</a>
            <?php if (($user['rol'] ?? '') === 'admin'): ?><a class="nav-link" href="<?= e(screen_url('/admin')) ?>">Administración</a><?php endif ?>
        </nav>
        <div class="header-account">
            <?php if ($user): ?>
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['nombre'], 0, 1))) ?></span><span class="account-name"><?= e(explode(' ', $user['nombre'])[0]) ?></span>
                <?php if (!$preview): ?><form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="icon-button" title="Cerrar sesión" aria-label="Cerrar sesión"><?= cf_icon('logout') ?></button></form><?php endif ?>
            <?php else: ?><a class="button button-small button-outline" href="<?= e(screen_url('/login')) ?>">Entrar <?= cf_icon('arrow') ?></a><?php endif ?>
        </div>
        <?php else: ?>
            <a class="quiet-link" href="<?= e(screen_url('/catalogo')) ?>">Explorar la colección <?= cf_icon('arrow') ?></a>
        <?php endif ?>
    </header>
<?php endif ?>
<?php if (!empty($flash)): ?><div class="flash flash-<?= e($flash['type'] ?? 'info') ?>" role="status"><?= cf_icon('info') ?><span><?= e($flash['message']) ?></span></div><?php endif ?>
<?php require $contentTemplate ?>
<?php if (!$adminPage): ?>
<footer class="site-footer"><span class="footer-wordmark">CLASSICFLIX</span><span>Las buenas historias no tienen fecha de caducidad.</span><span class="footer-mark">EST. MMXXVI <span aria-hidden="true">✦</span> CINE SIN TIEMPO</span></footer>
<?php endif ?>
</body>
</html>
