<?php $register = $register ?? false; $next = $next ?? '/catalogo'; ?>
<main id="main" class="auth-main">
    <section class="auth-scene" aria-label="El universo ClassicFlix">
        <div class="auth-scene-top"><span class="eyebrow">UNA INVITACIÓN AL CINE</span><span class="edition">N.º 001 / COLECCIÓN PERMANENTE</span></div>
        <div class="auth-scene-copy"><div class="deco-rule"><span>✦</span></div><p class="eyebrow">GRANDES HISTORIAS. NUEVAS MIRADAS.</p><h1>Hay historias <br>que merecen <br><em>otra función.</em></h1><p>Vuelve a la emoción de los clásicos.<br>Tu próxima gran historia ya te está esperando.</p></div>
        <div class="auth-scene-bottom"><span>LA MAGIA NO ENVEJECE.</span><span>1920 — ∞</span></div>
    </section>
    <section class="auth-form-panel" aria-labelledby="auth-title">
        <div class="auth-form-inner">
            <span class="tiny-ornament" aria-hidden="true">✦</span><p class="eyebrow"><?= $register ? 'TU ASIENTO ESTÁ RESERVADO' : 'ES UN GUSTO VOLVER A VERTE' ?></p>
            <h2 id="auth-title"><?= $register ? 'Que empiece <br>tu historia.' : 'Bienvenido <br>de nuevo.' ?></h2>
            <p class="form-intro"><?= $register ? 'Crea tu cuenta y descubre un cine que nunca pasa de moda.' : 'Entra, ponte cómodo y deja que el cine haga el resto.' ?></p>
            <?php if ($errors): ?><div class="form-errors" role="alert"><strong>Revisa los siguientes datos:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
            <?php if ($preview): ?><p class="demo-form-note"><?= cf_icon('info') ?>Vista de muestra. Este formulario no crea ni autentica cuentas.</p><?php endif ?>
            <form action="<?= e(url($register ? '/registro' : '/login')) ?>" method="post" class="auth-form" data-validate>
                <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
                <fieldset <?= $preview ? 'disabled' : '' ?>>
                <?php if ($register): ?><div class="field"><label for="nombre">Nombre completo</label><input id="nombre" name="nombre" type="text" autocomplete="name" placeholder="¿Cómo te llamas?" value="<?= e($old['nombre'] ?? '') ?>" maxlength="100" required></div><?php endif ?>
                <div class="field"><label for="email">Correo electrónico</label><input id="email" name="email" type="email" autocomplete="email" placeholder="tu@correo.com" value="<?= e($old['email'] ?? '') ?>" maxlength="100" required></div>
                <div class="field"><label for="password">Contraseña</label><div class="password-field"><input id="password" name="password" type="password" autocomplete="<?= $register ? 'new-password' : 'current-password' ?>" placeholder="<?= $register ? 'Mínimo 8 caracteres' : 'Tu contraseña' ?>" <?= $register ? 'minlength="8"' : '' ?> maxlength="72" required><button class="password-toggle" type="button" aria-label="Mostrar contraseña" aria-pressed="false" data-password="password"><?= cf_icon('eye') ?></button></div></div>
                <?php if ($register): ?><div class="field"><label for="password_confirmation">Confirmar contraseña</label><div class="password-field"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repite tu contraseña" minlength="8" maxlength="72" required><button class="password-toggle" type="button" aria-label="Mostrar confirmación de contraseña" aria-pressed="false" data-password="password_confirmation"><?= cf_icon('eye') ?></button></div></div><?php endif ?>
                <button class="button button-gold button-full" type="submit"><?= $register ? 'Crear mi cuenta' : 'Entrar a ClassicFlix' ?> <?= cf_icon('arrow') ?></button>
                </fieldset>
            </form>
            <p class="auth-switch"><?= $register ? '¿Ya tienes una cuenta?' : '¿Primera vez por aquí?' ?> <a href="<?= e(screen_url($register ? '/login' : '/registro', ['next' => $next])) ?>"><?= $register ? 'Inicia sesión' : 'Crea tu cuenta' ?></a></p>
            <div class="auth-footnote"><?= cf_icon('lock') ?><span>Tu cuenta, tu colección, tu próxima historia.</span></div>
        </div>
    </section>
</main>
