# syntax=docker/dockerfile:1
#
# Imagen de producción de SAC-ST. El contexto es la raíz del repo.
#
# Dos imágenes salen de acá, y las dos las publica GitHub Actions en GHCR
# con la misma versión:
#
#   - app  PHP-FPM con el código, vendor y los assets compilados. Corre
#          php-fpm, las migraciones y el worker de la cola: misma imagen,
#          distinto comando.
#   - web  nginx con public/ solamente. No ejecuta PHP: lo reenvía a app.
#
# La imagen es pública, igual que el repo: no lleva ningún secreto. El
# entorno entra al levantar el contenedor, desde /etc/sacst del servidor.
#
# Las imágenes base van fijadas por digest, no solo por etiqueta: una
# etiqueta como `8.5-fpm-bookworm` se republica con cada parche, y dos
# builds del mismo commit podían salir con sistemas distintos. Con el
# digest, el build es reproducible y un cambio de base es un commit que se
# revisa. Para actualizar una: `docker buildx imagetools inspect <etiqueta>`
# y reemplazar el sha256 —la etiqueta queda al lado para leerlo—.

# ─────────────────────────────────────────────────────────────────────────
# base · PHP con las extensiones que el sistema usa en runtime
# ─────────────────────────────────────────────────────────────────────────
FROM php:8.5-fpm-bookworm@sha256:53eab56a8f43f51a92119f6c29b3448af98eec288ff17f92829354a4b4c9ca05 AS base

# - pdo_pgsql/pgsql  PostgreSQL, sin plan B.
# - intl             importes y fechas en es-AR.
# - zip, gd          PhpSpreadsheet y OpenSpout (extractos y exportes), DomPDF.
#                    GD con WebP: las fotos de comprobantes se guardan en ese
#                    formato (ImageNormalizer). Sin libwebp, GD compila igual
#                    y la falta aparece recién al subir la primera foto.
# - exif             para enderezar las fotos del celular antes de convertirlas.
# - bcmath           brick/money.
# - pcntl            señales para que queue:work termine limpio.
#
# OPcache y sodium vienen compilados en la imagen base; pedirlos de nuevo
# aborta el build.
#
# Las bibliotecas -dev se usan para compilar y se descartan: el bloque de
# apt-mark es el que usa la propia imagen oficial para quedarse solo con las
# bibliotecas que las extensiones enlazan de verdad.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" bcmath exif gd intl pcntl pdo_pgsql pgsql zip; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

COPY deploy/docker/php/php.ini /usr/local/etc/php/conf.d/zz-sacst.ini

WORKDIR /var/www/html

# ─────────────────────────────────────────────────────────────────────────
# build · vendor y assets
# ─────────────────────────────────────────────────────────────────────────
# Los assets se compilan sobre la imagen de PHP y no sobre una de Node:
# el plugin de Wayfinder corre `php artisan wayfinder:generate` desde el
# propio Vite, y los helpers de rutas que genera —resources/js/actions y
# resources/js/routes— no están versionados. Sin PHP y sin vendor, el build
# del front no tiene de dónde sacarlos.
FROM base AS build

# Node 22 y pnpm 10, las mismas versiones que CI y el entorno de desarrollo.
# Se toma el binario de la imagen oficial (misma Debian, misma glibc) en vez
# de instalar un repositorio más.
COPY --from=node:22-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim@sha256:43ac6c60b8f89723f746e8a92ce91abd5017e627ce1ddfe4238355d3a30b772c /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm install -g pnpm@10.34.5 \
    && npm cache clean --force

COPY --from=composer:2@sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7 /usr/bin/composer /usr/local/bin/composer

# Dependencias primero y el código después: mientras composer.lock y
# pnpm-lock.yaml no cambien, estas capas salen de la caché.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress --prefer-dist

COPY package.json pnpm-lock.yaml pnpm-workspace.yaml .npmrc ./
RUN pnpm install --frozen-lockfile

COPY . .

# El autoload recién existe después del dump; ningún `artisan` puede ir
# antes. El dump corre con sus scripts a propósito: `package:discover`
# registra los providers de los paquetes, y Wayfinder los necesita para ver
# las rutas completas.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && pnpm run build \
    && rm -rf node_modules

# ─────────────────────────────────────────────────────────────────────────
# app · PHP-FPM
# ─────────────────────────────────────────────────────────────────────────
FROM base AS app

# El código queda de root y de solo lectura para www-data: un proceso
# comprometido no puede reescribir la aplicación. Lo único escribible es
# storage/ —un bind-mount del servidor— y bootstrap/cache, donde el
# entrypoint deja la configuración cacheada.
COPY --from=build /var/www/html /var/www/html
RUN chown www-data:www-data bootstrap/cache

COPY --chmod=0755 deploy/docker/entrypoint.sh /usr/local/bin/sacst-entrypoint

# php-fpm corre entero como www-data, también el proceso maestro. Escucha en
# el 9000, que no pide privilegios; la directiva `user` del pool queda sin
# efecto y fpm lo avisa con un NOTICE al arrancar, que es esperable.
USER www-data

EXPOSE 9000
ENTRYPOINT ["sacst-entrypoint"]
CMD ["php-fpm"]

# ─────────────────────────────────────────────────────────────────────────
# web · nginx
# ─────────────────────────────────────────────────────────────────────────
FROM nginx:1.30-alpine@sha256:985220252f3863977e468f611ef118ebd01421289dd86ee1ae99cb068c3bce2b AS web

COPY --from=app /var/www/html/public /var/www/html/public
# Plantilla: el entrypoint de la imagen oficial la completa con el entorno
# (SACST_DC_PROXY_IP) y la escribe en conf.d/default.conf al arrancar.
COPY deploy/docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template

EXPOSE 80
