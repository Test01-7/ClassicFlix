<?php
$votes = (int) ($ratingMovie['valoraciones_total'] ?? 0);
$average = $ratingMovie['valoracion_media'] ?? null;
?>
<p class="audience-rating <?= e($ratingClass ?? '') ?>" aria-label="Valoración del público">
    <?php if ($votes > 0 && $average !== null): ?>
    <span class="rating-star" aria-hidden="true">★</span><strong><?= e(number_format((float) $average, 1, ',', '')) ?> / 5</strong><span class="rating-count">· <?= $votes ?> <?= $votes === 1 ? 'valoración' : 'valoraciones' ?></span>
    <?php else: ?><span class="rating-star" aria-hidden="true">☆</span><span>Sin valoraciones</span><?php endif ?>
</p>
