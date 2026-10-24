<nav class="admin-navigation" aria-label="Secciones del panel">
<?php foreach ([['resumen', '/admin', 'grid', 'Vista general'], ['peliculas', '/admin/peliculas', 'film', 'Películas'], ['nuevo', '/admin/nuevo', 'plus', 'Nuevo contenido'], ['resenas', '/admin/resenas', 'users', 'Moderar reseñas']] as [$key, $route, $icon, $label]): ?>
    <?php $current = $adminSection === $key || ($key === 'peliculas' && in_array($adminSection, ['editar', 'archivos'], true)); ?>
    <a href="<?= e(screen_url($route)) ?>" <?= $current ? 'class="active" aria-current="page"' : '' ?>><?= cf_icon($icon) ?> <?= e($label) ?><?= $current ? '<span class="nav-small-dot"></span>' : '' ?></a>
<?php endforeach ?>
    <a href="<?= e(screen_url('/catalogo')) ?>"><?= cf_icon('eye') ?> Ver el catálogo <?= cf_icon('arrow', 'sidebar-external') ?></a>
</nav>
