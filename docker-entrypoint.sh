#!/usr/bin/env bash
set -e

PORT_TO_USE="${PORT:-80}"

sed -i "s/Listen 80/Listen ${PORT_TO_USE}/g" /etc/apache2/ports.conf
printf '<VirtualHost *:%s>\n    DocumentRoot /var/www/html/public\n    <Directory /var/www/html/public>\n        Options Indexes FollowSymLinks\n        AllowOverride All\n        Require all granted\n    </Directory>\n    ErrorLog ${APACHE_LOG_DIR}/error.log\n    CustomLog ${APACHE_LOG_DIR}/access.log combined\n</VirtualHost>\n' "${PORT_TO_USE}" > /etc/apache2/sites-available/000-default.conf

if [ -z "${DB_HOST}" ] || [ "${DB_HOST}" = "127.0.0.1" ] || [ "${DB_HOST}" = "localhost" ]; then
    echo ">>> Iniciando motor MariaDB integrado para Render..."
    mkdir -p /run/mysqld
    chown -R mysql:mysql /run/mysqld /var/lib/mysql

    mariadbd --user=mysql \
        --bind-address=127.0.0.1 \
        --port=3306 \
        --innodb-buffer-pool-size=32M \
        --performance-schema=OFF \
        --max-allowed-packet=64M \
        --skip-name-resolve &

    for i in $(seq 1 30); do
        if mariadb-admin ping --silent 2>/dev/null; then
            break
        fi
        sleep 1
    done

    echo ">>> Creando base de datos bd_mantenimiento_iestpu y cargando los 3,440 bienes del Excel..."
    mariadb -u root -e "CREATE DATABASE IF NOT EXISTS bd_mantenimiento_iestpu CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    mariadb -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD(''); GRANT ALL PRIVILEGES ON *.* TO 'root'@'127.0.0.1' IDENTIFIED BY '' WITH GRANT OPTION; FLUSH PRIVILEGES;" || true
    mariadb -u root bd_mantenimiento_iestpu < /var/www/html/database/instalacion_completa_iestpu.sql
    echo ">>> Base de datos cargada al 100% con exito."
fi

echo ">>> Iniciando servidor web Apache en el puerto ${PORT_TO_USE}..."
exec apache2-foreground
