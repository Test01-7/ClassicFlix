<?php $item = $item ?? []; ?>
<article class="movie-card">
    <a class="movie-art" href="<?= e(screen_url('/detalle', ['id' => $item['id']])) ?>" aria-label="Ver detalles de <?= e($item['titulo']) ?>">
        <img src="<?= e(cf_poster($item)) ?>" alt="Portada de <?= e($item['titulo']) ?>" loading="lazy" width="400" height="560" data-fallback="<?= e(asset('art/poster-placeholder.svg')) ?>">
        <span class="movie-art-overlay"><span class="card-play"><?= cf_icon('play') ?></span><span>DESCUBRIR LA HISTORIA</span></span>
        <?php if (!empty($item['has_movie'])): ?><span class="movie-badge">PELÍCULA COMPLETA</span><?php endif ?>
    </a>
    <div class="movie-card-info"><div><p class="movie-category"><?= e($item['categoria'] ?? $item['genero'] ?? 'Cine clásico') ?></p><h3><a href="<?= e(screen_url('/detalle', ['id' => $item['id']])) ?>"><?= e($item['titulo']) ?></a></h3><p class="movie-meta"><span><?= e($item['fechalanzamiento']) ?></span><span class="meta-dot">·</span><span><?= e(format_duration((int) ($item['duracion'] ?? 0))) ?></span></p></div>
    <?php if (!$preview && $user): ?><form action="<?= e(url('/favoritos')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="pelicula_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="return" value="<?= e('/' . ($section ?? 'catalogo')) ?>"><button class="icon-button favorite-button <?= !empty($item['is_favorite']) ? 'is-favorite' : '' ?>" aria-label="<?= !empty($item['is_favorite']) ? 'Quitar' : 'Agregar' ?> <?= e($item['titulo']) ?> <?= !empty($item['is_favorite']) ? 'de' : 'a' ?> favoritos" aria-pressed="<?= !empty($item['is_favorite']) ? 'true' : 'false' ?>"><?= cf_icon('heart') ?></button></form><?php else: ?><a class="icon-button favorite-button" href="<?= e(screen_url($preview ? '/favoritos' : '/login', ['next' => '/favoritos'])) ?>" aria-label="Ver mi lista"><?= cf_icon('heart') ?></a><?php endif ?>
    </div>
    <?php if (!empty($item['viewed_at'])): ?><p class="viewed-at"><?= cf_icon('clock') ?> Visto el <?= e($item['viewed_at']) ?></p><?php endif ?>
</article>
