<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

$u = requiere_login();

if (es_post()) {
    csrf_verificar();
    $actual = (string) ($_POST['actual'] ?? '');
    $nueva  = (string) ($_POST['nueva'] ?? '');
    $conf   = (string) ($_POST['confirmar'] ?? '');

    $hash = (string) consulta('SELECT password_hash FROM usuarios WHERE id = ?', [$u['id']])->fetchColumn();
    if (!password_verify($actual, $hash)) {
        flash('danger', 'La contraseña actual no es correcta.');
    } elseif ($nueva !== $conf) {
        flash('danger', 'La confirmación no coincide.');
    } elseif ($err = validar_clave_nueva($nueva)) {
        flash('danger', $err);
    } elseif (password_verify($nueva, $hash)) {
        flash('danger', 'La nueva contraseña debe ser distinta de la actual.');
    } else {
        consulta('UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 0 WHERE id = ?',
            [password_hash($nueva, PASSWORD_DEFAULT), $u['id']]);
        session_regenerate_id(true);
        auditar('cambio_clave', 'usuarios', (int) $u['id']);
        flash('success', 'Contraseña actualizada.');
        redirigir('index.php');
    }
    redirigir('cambiar_clave.php');
}

cabecera('Cambiar contraseña', $u);
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <div class="card shadow-sm"><div class="card-body p-4">
    <h1 class="h4">Cambiar contraseña</h1>
    <?php if ($u['debe_cambiar_clave']): ?><p class="text-muted small">Por seguridad, debe definir una contraseña personal antes de continuar.</p><?php endif; ?>
    <form method="post">
      <?= csrf_campo() ?>
      <div class="mb-3"><label class="form-label">Contraseña actual</label><input class="form-control" type="password" name="actual" required autocomplete="current-password"></div>
      <div class="mb-3"><label class="form-label">Nueva contraseña</label><input class="form-control" type="password" name="nueva" required minlength="<?= (int) $GLOBALS['CFG']['seguridad']['password_min'] ?>" autocomplete="new-password"><div class="form-text">Mínimo <?= (int) $GLOBALS['CFG']['seguridad']['password_min'] ?> caracteres, con letras y números.</div></div>
      <div class="mb-3"><label class="form-label">Confirmar nueva contraseña</label><input class="form-control" type="password" name="confirmar" required autocomplete="new-password"></div>
      <button class="btn btn-primary" type="submit">Guardar</button>
    </form>
  </div></div>
</div></div>
<?php pie();
