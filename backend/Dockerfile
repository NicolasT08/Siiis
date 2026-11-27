# Plan B (DEC-B58): solo el backend, en Render, si Hostinger no ofrece la extensión mongodb.
# PHP 8.2 + Apache + ext-mongodb. Contexto de construcción: la carpeta backend/.
# Instrucciones: docs/deployment.md, sección "Plan B: backend en Render (Docker)". No se usa en Hostinger.

# --- 1. Dependencias de producción (sin dev) ---------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# composer.json fija la plataforma (php 8.2.34, ext-mongodb 1.18.1); la imagen de Composer no trae ext-mongodb,
# así que no se revisan las extensiones aquí: la etapa final sí las tiene.
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader --ignore-platform-reqs
COPY src/ src/
RUN composer dump-autoload --no-dev --optimize

# --- 2. Imagen final ---------------------------------------------------------------------------------------
FROM php:8.2-apache

# Extensión mongodb (la misma versión con la que pasan las pruebas en local; la librería es mongodb/mongodb
# 1.19.1, DEC-B03). libssl-dev es necesaria para conectar a Atlas con TLS.
ARG MONGODB_EXT_VERSION=1.21.9
RUN apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && pecl install "mongodb-${MONGODB_EXT_VERSION}" \
    && docker-php-ext-enable mongodb \
    && apt-get purge -y --auto-remove libssl-dev pkg-config \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# PHP de producción. variables_order con "E": las variables de entorno de Render llegan a $_ENV
# (Settings::fromEnv lee $_ENV; en el contenedor no hay archivo .env).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'variables_order = "EGPCS"'; \
        echo 'expose_php = Off'; \
        echo 'zend.exception_ignore_args = On'; \
        echo 'upload_max_filesize = 50M'; \
        echo 'post_max_size = 55M'; \
        echo 'max_execution_time = 180'; \
        echo 'memory_limit = 256M'; \
    } > "$PHP_INI_DIR/conf.d/zz-siiis.ini"

# Apache: raíz en public/, .htaccess activo (reenvía Authorization) y puerto de Render ($PORT, 10000 por
# defecto). Apache expande ${PORT} y ${APACHE_DOCUMENT_ROOT} desde el entorno al arrancar.
ENV PORT=10000 \
    APACHE_DOCUMENT_ROOT=/var/www/siiis/public
RUN a2enmod rewrite \
    && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        '<Directory ${APACHE_DOCUMENT_ROOT}>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/siiis.conf \
    && a2enconf siiis

WORKDIR /var/www/siiis
COPY bootstrap.php ./
COPY public/ public/
COPY src/ src/
COPY cron/ cron/
COPY scripts/cli.php scripts/crear_indices.php scripts/crear_superadmin.php scripts/seed_multimedia.php scripts/cargar_multimedia.php scripts/
COPY scripts/multimedia/ scripts/multimedia/
COPY --from=vendor /app/vendor/ vendor/
RUN mkdir -p storage/logs storage/ratelimit storage/cache \
    && chown -R www-data:www-data storage

EXPOSE 10000
