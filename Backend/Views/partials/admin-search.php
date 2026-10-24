<form class="admin-search" method="get" action="index.php" role="search">
    <input type="hidden" name="r" value="<?= $preview ? '/preview' : e($searchRoute) ?>">
    <?php if ($preview): ?><input type="hidden" name="screen" value="admin"><input type="hidden" name="panel" value="<?= e($adminSection) ?>"><?php endif ?>
    <div class="search-field"><label class="sr-only" for="admin-search"><?= e($searchLabel) ?></label><?= cf_icon('search') ?><input id="admin-search" type="search" name="q" maxlength="120" placeholder="<?= e($searchLabel) ?>" value="<?= e($adminQuery) ?>"></div>
    <button class="button button-outline" type="submit">Buscar <?= cf_icon('arrow') ?></button>
    <?php if ($adminQuery !== ''): ?><a class="text-button" href="<?= e(screen_url($searchRoute)) ?>">Limpiar búsqueda</a><?php endif ?>
</form>
<?php if ($adminQuery !== ''): ?><p class="admin-search-result" role="status">Resultados para «<?= e($adminQuery) ?>»</p><?php endif ?>
