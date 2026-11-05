FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    mariadb-server \
    mariadb-client \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite headers \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads /var/www/html/config

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

CMD ["/usr/local/bin/docker-entrypoint.sh"]
