<?php $searchRoute = '/admin/resenas'; $searchLabel = 'Buscar por autor o texto de la reseña'; require __DIR__ . '/admin-search.php'; ?>
<section class="admin-panel moderation-panel" aria-labelledby="moderation-title">
    <div class="panel-heading"><h2 id="moderation-title">Opiniones del público</h2><span class="subtle-label"><?= (int) $moderation['total'] ?> RESULTADOS</span></div>
    <div class="moderation-intro"><p class="section-description">Al retirar una reseña también se eliminan sus estrellas del promedio público.</p><?php if ($preview): ?><p class="demo-form-note">Opiniones ficticias. La eliminación está desactivada en esta muestra.</p><?php endif ?></div>
    <p class="admin-table-hint">Desliza la tabla para ver todas las columnas.</p>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Reseñas: tabla con desplazamiento horizontal">
        <table class="content-table moderation-table"><caption class="sr-only">Reseñas de todas las películas, de más recientes a más antiguas</caption><thead><tr><th scope="col">Autor</th><th scope="col">Película</th><th scope="col">Estrellas</th><th scope="col">Reseña</th><th scope="col">Fecha</th><th scope="col">Acciones</th></tr></thead><tbody>
        <?php foreach ($moderation['items'] as $opinion): ?>
            <tr class="moderation-row"><td class="moderation-author"><?= e($opinion['autor']) ?></td><td><a href="<?= e(screen_url('/detalle', ['id' => $opinion['pelicula_id']])) ?>"><?= e($opinion['pelicula']) ?></a></td><td><span class="review-score"><?= $opinion['valoracion'] === null ? 'Sin valoración' : '★ ' . (int) $opinion['valoracion'] . ' / 5' ?></span></td><td class="moderation-text"><details class="review-expand"><summary><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $opinion['resena']), 0, 110, '…')) ?><span class="review-expand-hint">Ver reseña completa</span></summary><p class="review-text"><?= nl2br(e($opinion['resena'])) ?></p></details></td><td class="moderation-date"><?= e((new DateTimeImmutable($opinion['fecha'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y')) ?></td><td>
                <details class="moderation-delete"><summary>Eliminar<span class="sr-only"> reseña de <?= e($opinion['autor']) ?> sobre <?= e($opinion['pelicula']) ?></span></summary><p>Se borrarán esta reseña y sus estrellas. No se puede deshacer.</p><form action="<?= e(url('/admin/resenas/eliminar')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="resena_id" value="<?= (int) $opinion['id'] ?>"><input type="hidden" name="resenas_pagina" value="<?= (int) $moderation['page'] ?>"><input type="hidden" name="q" value="<?= e($adminQuery) ?>"><fieldset <?= $preview ? 'disabled' : '' ?>><button type="submit" class="button button-outline button-small">Confirmar eliminación</button></fieldset></form></details>
            </td></tr>
        <?php endforeach ?>
        <?php if (!$moderation['items']): ?><tr><td colspan="6"><p class="table-empty"><?= $adminQuery !== '' ? 'No hay reseñas que coincidan. Busca otro autor o palabra.' : 'Todavía no hay reseñas para moderar.' ?></p></td></tr><?php endif ?>
        </tbody></table>
    </div>
    <?php $listing = $moderation; $pageParam = 'resenas_pagina'; $paginationLabel = 'Páginas de moderación'; require __DIR__ . '/admin-pagination.php'; ?>
</section>
