<?php if ($listing['pages'] > 1): ?><nav class="reviews-pagination admin-pagination" aria-label="<?= e($paginationLabel) ?>">
    <?php if ($listing['page'] > 1): ?><a class="button button-outline button-small" href="<?= e(screen_url($searchRoute, ['q' => $adminQuery, $pageParam => $listing['page'] - 1])) ?>">← Anterior</a><?php endif ?>
    <span>Página <?= (int) $listing['page'] ?> de <?= (int) $listing['pages'] ?></span>
    <?php if ($listing['page'] < $listing['pages']): ?><a class="button button-outline button-small" href="<?= e(screen_url($searchRoute, ['q' => $adminQuery, $pageParam => $listing['page'] + 1])) ?>">Siguiente →</a><?php endif ?>
</nav><?php endif ?>
