<?php
/**
 * Crea un usuario desde la línea de comandos (útil para el primer administrador).
 *
 *   php scripts/crear_usuario.php admin_general correo@iestpu.edu.pe "Nombres" "Apellidos" [CODIGO_PROGRAMA ...]
 *
 * Genera una contraseña temporal aleatoria y obliga a cambiarla en el primer ingreso.
 * Roles: admin_general | admin_programa | tecnico | docente
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo se ejecuta por línea de comandos.');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
$GLOBALS['CFG'] = require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set($GLOBALS['CFG']['app']['zona_horaria']);
require dirname(__DIR__) . '/includes/db.php';

if ($argc < 5) {
    fwrite(STDERR, "Uso: php scripts/crear_usuario.php <rol> <correo> <nombres> <apellidos> [programa ...]\n");
    exit(1);
}
[, $rol, $correo, $nombres, $apellidos] = $argv;
$programas = array_slice($argv, 5);

$correo = mb_strtolower(trim($correo));
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Correo no válido.\n");
    exit(1);
}
$rolId = consulta('SELECT id FROM roles WHERE codigo = ?', [$rol])->fetchColumn();
if (!$rolId) {
    fwrite(STDERR, "Rol desconocido: $rol\n");
    exit(1);
}
if ($rol !== 'admin_general' && !$programas) {
    fwrite(STDERR, "Este rol necesita al menos un programa (AGRO, ENFE, CONT, APSTI).\n");
    exit(1);
}

$clave = substr(bin2hex(random_bytes(8)), 0, 12) . 'A1';
$pdo = db();
$pdo->beginTransaction();
try {
    consulta('INSERT INTO usuarios (rol_id, nombres, apellidos, correo, password_hash, debe_cambiar_clave) VALUES (?,?,?,?,?,1)',
        [$rolId, $nombres, $apellidos, $correo, password_hash($clave, PASSWORD_DEFAULT)]);
    $uid = (int) $pdo->lastInsertId();
    foreach ($programas as $cod) {
        $pid = consulta('SELECT id FROM programas WHERE codigo = ?', [strtoupper($cod)])->fetchColumn();
        if (!$pid) {
            throw new RuntimeException("Programa desconocido: $cod");
        }
        consulta('INSERT INTO usuario_programa (usuario_id, programa_id) VALUES (?,?)', [$uid, $pid]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'No se creó el usuario: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Usuario creado: $correo ($rol)\nContraseña temporal: $clave\n(Deberá cambiarla al ingresar.)\n";
