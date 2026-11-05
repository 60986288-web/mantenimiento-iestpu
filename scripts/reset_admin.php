<?php
declare(strict_types=1);
$GLOBALS['CFG'] = require dirname(__DIR__) . '/config/config.php';
require dirname(__DIR__) . '/includes/db.php';

$nueva_clave = 'admin123';
$hash = password_hash($nueva_clave, PASSWORD_DEFAULT);

consulta("UPDATE usuarios SET password_hash = ?, debe_cambiar_clave = 0 WHERE correo = 'admin@iestpu.edu.pe'", [$hash]);
echo "¡Contraseña reseteada con éxito!\n";
echo "Correo: admin@iestpu.edu.pe\n";
echo "Contraseña: admin123\n";