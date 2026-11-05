#!/usr/bin/env bash
set -e

PORT_TO_USE="${PORT:-80}"

sed -i "s/Listen 80/Listen ${PORT_TO_USE}/g" /etc/apache2/ports.conf
printf '<VirtualHost *:%s>\n    DocumentRoot /var/www/html/public\n    <Directory /var/www/html/public>\n        Options Indexes FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>\n    ErrorLog ${APACHE_LOG_DIR}/error.log\n    CustomLog ${APACHE_LOG_DIR}/access.log combined\n</VirtualHost>\n' "${PORT_TO_USE}" > /etc/apache2/sites-available/000-default.conf

ln -sfn /var/www/html /var/www/html/public/mantenimiento_iestpu || true
if [ -f /var/www/html/public/assets/img/fachada-iestp.jpg.jpeg ] && [ ! -f /var/www/html/public/assets/img/fachada-iestp.jpg ]; then
    cp /var/www/html/public/assets/img/fachada-iestp.jpg.jpeg /var/www/html/public/assets/img/fachada-iestp.jpg || true
fi

cat << 'PHP_EOF' > /var/www/html/config/config.php
<?php
declare(strict_types=1);

$autoBaseUrl = '';
if (!empty($_SERVER['SCRIPT_NAME'])) {
    $script = str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']);
    $base = preg_replace('#/(?:admin|docente|tecnico)/[^/]+$#', '', $script);
    if ($base === $script) {
        $base = dirname($script);
    }
    $autoBaseUrl = rtrim(str_replace('\\', '/', (string) $base), '/');
}

return [
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
];
PHP_EOF

echo ">>> Iniciando motor MariaDB integrado para Render..."
mkdir -p /run/mysqld
chown -R mysql:mysql /run/mysqld /var/lib/mysql

mariadbd --user=mysql \
    --bind-address=127.0.0.1 \
    --port=3306 \
    --socket=/run/mysqld/mysqld.sock \
    --skip-grant-tables \
    --max-connect-errors=4294967295 \
    --innodb-buffer-pool-size=32M \
    --performance-schema=OFF \
    --max-allowed-packet=64M \
    --skip-name-resolve &

for i in $(seq 1 30); do
    if mariadb-admin --socket=/run/mysqld/mysqld.sock ping --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

echo ">>> Inicializando base de datos bd_mantenimiento_iestpu..."
mariadb --socket=/run/mysqld/mysqld.sock -u root -e "CREATE DATABASE IF NOT EXISTS bd_mantenimiento_iestpu CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

if [ -f /var/www/html/database/instalacion_completa_iestpu.sql ]; then
    mariadb --socket=/run/mysqld/mysqld.sock -u root bd_mantenimiento_iestpu < /var/www/html/database/instalacion_completa_iestpu.sql
else
    mariadb --socket=/run/mysqld/mysqld.sock -u root bd_mantenimiento_iestpu < /var/www/html/database/schema.sql
    if [ -f /var/www/html/database/upgrade_v2.sql ]; then
        mariadb --socket=/run/mysqld/mysqld.sock -u root bd_mantenimiento_iestpu < /var/www/html/database/upgrade_v2.sql || true
    fi
    HASH_CLAVE=$(php -r 'echo password_hash("admin123", PASSWORD_DEFAULT);')
    mariadb --socket=/run/mysqld/mysqld.sock -u root bd_mantenimiento_iestpu -e "
      INSERT IGNORE INTO categorias_equipos (id, programa_id, nombre, es_consumible) VALUES (1, NULL, 'Varios', 0);
      INSERT IGNORE INTO usuarios (id, rol_id, dni, nombres, apellidos, correo, password_hash, debe_cambiar_clave, activo) VALUES
        (1, 1, '27722958', 'Ernesto', 'Correa Barrantes (Admin)', 'admin@iestpu.edu.pe', '${HASH_CLAVE}', 0, 1),
        (2, 2, '44839017', 'Carlos', 'Sandoval (Coord. APSTI)', 'coordinador.apsti@iestpu.edu.pe', '${HASH_CLAVE}', 0, 1),
        (7, 3, '46998877', 'Carlos', 'Quispe (Soporte)', 'soporte@iestpu.edu.pe', '${HASH_CLAVE}', 0, 1);
      INSERT IGNORE INTO usuario_programa (usuario_id, programa_id) VALUES (2, 4), (7, 1), (7, 2), (7, 3), (7, 4);
    "
    if [ -f /var/www/html/scripts/importar_excel.php ] && [ -f /var/www/html/storage/inventario.xlsx ]; then
        php /var/www/html/scripts/importar_excel.php /var/www/html/storage/inventario.xlsx || true
    fi
fi

echo ">>> Base de datos lista. Iniciando Apache en puerto ${PORT_TO_USE}..."
exec apache2-foreground
