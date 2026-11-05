<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

if (es_post()) {
    csrf_verificar();
    auditar('logout');
    cerrar_sesion();
}
redirigir('login.php');
