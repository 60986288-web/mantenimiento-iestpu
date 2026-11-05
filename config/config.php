<?php
/**
 * Configuración general compatible con XAMPP local y cualquier Hosting Web (cPanel, Hostinger, InfinityFree, etc.).
 * Detecta automáticamente la ruta base (base_url) sin importar en qué carpeta se suba.
 */
declare(strict_types=1);

if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
} else {
    $local = [];
}

// Detectar base_url automáticamente hasta la carpeta /public
$autoBaseUrl = '/mantenimiento_iestpu/public';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $script = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
    $pos = strpos($script, '/public/');
    if ($pos !== false) {
        $autoBaseUrl = substr($script, 0, $pos + 7);
    } else {
        $autoBaseUrl = rtrim(dirname($script), '/');
    }
}

return array_replace_recursive([
    'app' => [
        'nombre'       => 'Mantenimiento IESTPU',
        'base_url'     => $autoBaseUrl,
        'debug'        => false,
        'zona_horaria' => 'America/Lima',
    ],
    'db' => [
        'host'    => '127.0.0.1',
        'puerto'  => 3306,
        'nombre'  => 'bd_mantenimiento_iestpu',
        'usuario' => 'root',
        'clave'   => '',
        'charset' => 'utf8mb4',
    ],
    'seguridad' => [
        'sesion_minutos'  => 60,
        'password_min'    => 10,
        'max_intentos'    => 5,
        'minutos_bloqueo' => 15,
    ],
    'uploads' => [
        'directorio' => dirname(__DIR__) . '/uploads',
        'max_bytes'  => 5 * 1024 * 1024,
        'mimes'      => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'],
    ],
], $local);
