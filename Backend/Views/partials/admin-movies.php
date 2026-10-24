<?php $searchRoute = '/admin/peliculas'; $searchLabel = 'Buscar por título de película'; require __DIR__ . '/admin-search.php'; ?>
<section class="admin-panel" aria-labelledby="admin-movies-title">
    <div class="panel-heading"><h2 id="admin-movies-title">Historias en cartelera</h2><span class="subtle-label"><?= (int) $movieListing['total'] ?> RESULTADOS</span></div>
    <p class="admin-table-hint">Desliza la tabla para ver todas las columnas.</p>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Películas: tabla con desplazamiento horizontal">
        <table class="content-table admin-movie-table"><caption class="sr-only">Películas publicadas y borradores</caption><thead><tr><th scope="col">Película</th><th scope="col">Categoría</th><th scope="col">Año</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr></thead><tbody>
        <?php foreach ($movies as $adminMovie): ?>
            <?php $editContext = ['id' => $adminMovie['id'], 'q' => $adminQuery, 'pagina' => $movieListing['page']]; ?>
            <tr><td><a class="table-movie" href="<?= e(screen_url('/admin/editar', $editContext)) ?>"><img src="<?= e(cf_poster($adminMovie)) ?>" alt="" loading="lazy" width="36" height="48" data-fallback="<?= e(asset('art/poster-placeholder.svg')) ?>"><span><strong><?= e($adminMovie['titulo']) ?></strong><small><?= e(($adminMovie['director'] ?? '') ?: 'Dirección sin especificar') ?></small></span></a></td><td><?= e($adminMovie['categoria'] ?? $adminMovie['genero'] ?? 'Sin categoría') ?></td><td><?= e($adminMovie['fechalanzamiento']) ?></td><td><span class="state-badge <?= $adminMovie['estado'] === 'publicado' ? 'state-published' : 'state-draft' ?>"><span></span><?= $adminMovie['estado'] === 'publicado' ? 'Publicado' : 'Borrador' ?></span></td><td><div class="table-actions"><a href="<?= e(screen_url('/admin/editar', $editContext)) ?>" aria-label="Editar <?= e($adminMovie['titulo']) ?>">Editar</a><a href="<?= e(screen_url('/admin/archivos', $editContext)) ?>" aria-label="Archivos de <?= e($adminMovie['titulo']) ?>">Archivos</a></div></td></tr>
        <?php endforeach ?>
        <?php if (!$movies): ?><tr><td colspan="5"><p class="table-empty"><?= $adminQuery !== '' ? 'No hay películas con ese título. Prueba otra búsqueda.' : 'Todavía no hay películas. Registra tu primer contenido.' ?></p></td></tr><?php endif ?>
        </tbody></table>
    </div>
    <?php $listing = $movieListing; $pageParam = 'pagina'; $paginationLabel = 'Páginas de películas'; require __DIR__ . '/admin-pagination.php'; ?>
</section>
