<?php
$reviewValues = array_replace($ownReview ?? [], $reviewOld);
$canReview = $preview || ($movie['estado'] ?? '') === 'publicado';
?>
<section id="resenas" class="reviews-section" aria-labelledby="reviews-title">
    <div class="section-heading"><div><p class="eyebrow">CADA MIRADA CUENTA</p><h2 id="reviews-title">La voz del público<span class="title-period">.</span></h2></div><span class="review-total"><?= (int) $reviews['total'] ?> <?= (int) $reviews['total'] === 1 ? 'opinión' : 'opiniones' ?></span></div>
    <div class="reviews-layout">
        <div class="review-list">
            <?php foreach ($reviews['items'] as $review): ?>
            <article class="review-entry">
                <header><span class="review-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($review['autor'], 0, 1))) ?></span><div><h3><?= e($review['autor']) ?></h3><p class="review-date"><?= e((new DateTimeImmutable($review['fecha'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y')) ?><?php if (!empty($review['actualizado_en']) && $review['actualizado_en'] !== $review['fecha']): ?> · Editada<?php endif ?></p></div><span class="review-score"><?php if ($review['valoracion'] !== null): ?><span aria-hidden="true">★</span> <?= (int) $review['valoracion'] ?> / 5<?php else: ?>Sin valoración<?php endif ?></span></header>
                <p class="review-text"><?= nl2br(e($review['resena'])) ?></p>
            </article>
            <?php endforeach ?>
            <?php if (!$reviews['items']): ?><div class="reviews-empty"><span aria-hidden="true">☆</span><h3>La primera mirada puede ser la tuya.</h3><p>Comparte lo que esta película te hizo sentir.</p></div><?php endif ?>
            <?php if ($reviews['pages'] > 1): ?><nav class="reviews-pagination" aria-label="Páginas de opiniones">
                <?php if ($reviews['page'] > 1): ?><a class="button button-outline button-small" href="<?= e(screen_url('/detalle', ['id' => $movie['id'], 'resenas_pagina' => $reviews['page'] - 1]) . '#resenas') ?>">← Anterior</a><?php endif ?>
                <span>Página <?= (int) $reviews['page'] ?> de <?= (int) $reviews['pages'] ?></span>
                <?php if ($reviews['page'] < $reviews['pages']): ?><a class="button button-outline button-small" href="<?= e(screen_url('/detalle', ['id' => $movie['id'], 'resenas_pagina' => $reviews['page'] + 1]) . '#resenas') ?>">Siguiente →</a><?php endif ?>
            </nav><?php endif ?>
        </div>
        <aside class="review-compose" aria-labelledby="review-form-title">
        <?php if ($canReview && ($user || $preview)): ?>
            <p class="eyebrow">TU BUTACA, TU OPINIÓN</p><h3 id="review-form-title"><?= $ownReview ? 'Tu reseña' : 'Comparte tu mirada' ?></h3><p class="review-form-intro">Una película, una opinión por cuenta. Puedes volver a editarla cuando quieras.</p>
            <?php if ($preview): ?><p class="demo-form-note">Opiniones ficticias de muestra. El formulario está desactivado.</p><?php endif ?>
            <?php if ($reviewErrors): ?><div class="form-errors" role="alert"><ul><?php foreach ($reviewErrors as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
            <form action="<?= e(url('/resenas/guardar')) ?>" method="post" class="review-form">
                <?= csrf_field() ?><input type="hidden" name="pelicula_id" value="<?= (int) $movie['id'] ?>">
                <fieldset class="review-fields" <?= $preview ? 'disabled' : '' ?>>
                    <legend class="sr-only">Tu reseña y valoración</legend>
                    <fieldset class="star-picker"><legend>Tu valoración <span aria-hidden="true">*</span></legend><div class="star-options">
                        <?php for ($star = 1; $star <= 5; $star++): ?><label><input type="radio" name="valoracion" value="<?= $star ?>" <?= (string) ($reviewValues['valoracion'] ?? '') === (string) $star ? 'checked' : '' ?> required><span class="star-option" aria-hidden="true">★<small><?= $star ?></small></span><span class="sr-only"><?= $star ?> <?= $star === 1 ? 'estrella' : 'estrellas' ?></span></label><?php endfor ?>
                    </div></fieldset>
                    <div class="field"><label for="review-text">Tu reseña <span aria-hidden="true">*</span></label><textarea id="review-text" name="resena" maxlength="2000" rows="6" required aria-describedby="review-text-help" placeholder="¿Qué te llevas de esta historia?"><?= e($reviewValues['resena'] ?? '') ?></textarea><small id="review-text-help" class="field-hint">Hasta 2000 caracteres. Se mostrará tu nombre público.</small></div>
                    <button class="button button-gold" type="submit"><?= $ownReview ? 'Actualizar mi opinión' : 'Publicar mi opinión' ?> <?= cf_icon('arrow') ?></button>
                </fieldset>
            </form>
            <?php if ($ownReview && !$preview): ?><form action="<?= e(url('/resenas/eliminar')) ?>" method="post" class="review-delete-form"><?= csrf_field() ?><input type="hidden" name="pelicula_id" value="<?= (int) $movie['id'] ?>"><button type="submit" class="text-button">Eliminar mi reseña y valoración</button></form><?php endif ?>
        <?php elseif ($canReview): ?>
            <span class="tiny-ornament" aria-hidden="true">✦</span><h3 id="review-form-title">Tu opinión también es parte de la historia.</h3><p>Entra con tu cuenta para escribir una reseña y valorar esta película.</p><a class="button button-gold" href="<?= e(url('/login', ['next' => '/detalle?id=' . $movie['id']])) ?>">Iniciar sesión <?= cf_icon('arrow') ?></a>
        <?php else: ?><h3 id="review-form-title">Una función por estrenar.</h3><p>Se podrán publicar opiniones cuando este título esté publicado.</p><?php endif ?>
        </aside>
    </div>
</section>
