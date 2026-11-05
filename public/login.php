<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/layout.php';

if (usuario_actual()) {
    redirigir('index.php');
}

if (es_post()) {
    csrf_verificar();
    $correo = mb_strtolower(post_str('correo', 150));
    $clave  = (string) ($_POST['clave'] ?? '');
    if (iniciar_sesion($correo, $clave)) {
        redirigir('index.php');
    }
    // Mensaje genérico: no revela si el correo existe ni si la cuenta está bloqueada
    flash('danger', 'Credenciales incorrectas o cuenta temporalmente bloqueada.');
    redirigir('login.php');
}

cabecera('Iniciar sesión');
?>
<div class="container-fluid vh-100 d-flex align-items-center justify-content-center" style="background-color: #f4f7f6;">
    <div class="row w-100 shadow-lg rounded-4 overflow-hidden" style="max-width: 1000px; background: #fff;">
        <!-- Lado Izquierdo: Imagen -->
        <div class="col-md-6 d-none d-md-block p-0 position-relative">
            <img src="<?= e(url('assets/img/fachada-iestp.jpg')) ?>" alt="IESTP Utcubamba" class="w-100 h-100 object-fit-cover">
            <div class="position-absolute top-0 start-0 w-100 h-100 d-flex flex-column align-items-center justify-content-center" style="background: rgba(13, 110, 253, 0.7);">
                <img src="<?= e(url('assets/img/logo-iestp.png')) ?>" alt="Logo" style="width: 120px;" class="mb-3 bg-white rounded-circle p-2">
                <h2 class="text-white fw-bold">IESTP Utcubamba</h2>
                <p class="text-white">Innovación y servicio para tu formación</p>
            </div>
        </div>
        <!-- Lado Derecho: Formulario -->
        <div class="col-md-6 p-5 d-flex flex-column justify-content-center">
            <div class="text-center mb-4">
                <h3 class="fw-bold text-primary">Sistema de Mantenimiento</h3>
                <p class="text-muted">Preventivo y Correctivo</p>
            </div>
            <form method="post">
                <?= csrf_campo() ?>
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold">Correo institucional</label>
                    <input class="form-control form-control-lg bg-light" type="email" name="correo" placeholder="usuario@iestpu.edu.pe" required autofocus>
                </div>
                <div class="mb-4">
                    <label class="form-label text-muted small fw-bold">Contraseña</label>
                    <input class="form-control form-control-lg bg-light" type="password" name="clave" placeholder="••••••••" required>
                </div>
                <button class="btn btn-primary btn-lg w-100 fw-bold" type="submit">Ingresar al Sistema</button>
            </form>
        </div>
    </div>
</div>
<?php pie();
