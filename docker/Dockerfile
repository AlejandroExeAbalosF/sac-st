# Imagen de desarrollo de SAC-ST.
#
# Una sola imagen para los cuatro servicios de PHP y para Vite, y no dos.
# El motivo no es la comodidad: el plugin de Wayfinder ejecuta
# `php artisan wayfinder:generate` desde el propio proceso de Vite, así que
# el contenedor que sirve el front necesita PHP y el proyecto completo.
# Separar Node de PHP deja el front sin rutas tipadas.
FROM php:8.5-cli

# - libpq       pdo_pgsql: la base es PostgreSQL y no hay plan B (SQLite no
#               tiene los CHECK, los triggers ni numeric(19,2) que el sistema
#               usa como invariantes).
# - libicu      intl: importes y fechas en es-AR.
# - libzip      zip: PhpSpreadsheet, para los extractos bancarios.
# - libpng/jpeg gd: PhpSpreadsheet y DomPDF.
# - libwebp     gd con WebP: las fotos de comprobantes se guardan en ese
#               formato (ver `ImageNormalizer`). Sin libwebp, GD compila
#               igual y la falta recién aparece al subir la primera foto.
# - exif        para enderezar las fotos del celular antes de convertirlas.
#
# `sodium` y OPcache no se instalan: la imagen base ya los trae compilados
# y pedirlos de nuevo aborta el build con un `cp: cannot stat 'modules/*'`
# que no dice nada sobre la causa.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        gnupg \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libpq-dev \
        libwebp-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Node 22 y pnpm 10: las mismas versiones que usa CI y que lee el
# `lockfileVersion: 9.0` de pnpm-lock.yaml.
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && npm install -g pnpm@10 \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY php/php.ini /usr/local/etc/php/conf.d/sacst.ini

# El proyecto entra por bind mount, no copiado: esto es desarrollo y lo que
# se edita en el host tiene que verse adentro. El UID/GID se parametriza
# para que en Linux los archivos que escriba el contenedor —`vendor`, los
# helpers de Wayfinder, los PDF de `output`— pertenezcan a quien edita y no
# a root. En Docker Desktop (Windows y macOS) el valor es indistinto.
ARG UID=1000
ARG GID=1000
RUN if ! getent group "${GID}" >/dev/null; then groupadd -g "${GID}" sacst; fi \
    && if ! getent passwd "${UID}" >/dev/null; then \
           useradd -u "${UID}" -g "${GID}" -d /home/sacst -s /bin/bash sacst; \
       fi \
# `vendor` y `node_modules` viven en volúmenes nombrados, fuera del bind
# mount: así los binarios de Linux no pisan los del host ni pagan su I/O.
# Un volumen nombrado hereda el dueño del directorio que encuentra en la
# imagen, así que los dos tienen que existir acá y pertenecer al usuario, o
# `composer install` no puede escribir en ellos.
    && mkdir -p /var/www/html/vendor \
                /var/www/html/node_modules \
                /home/sacst/.composer \
                /home/sacst/.local/share/pnpm \
    && chown -R "${UID}:${GID}" /var/www/html /home/sacst

COPY --chmod=0755 setup.sh /usr/local/bin/sacst-setup

USER ${UID}:${GID}

WORKDIR /var/www/html

# pnpm necesita un directorio propio de caché y de store; sin esto intenta
# escribir en el home de root y falla al correr como usuario sin privilegios.
ENV HOME=/home/sacst \
    PNPM_HOME=/home/sacst/.local/share/pnpm \
    COMPOSER_HOME=/home/sacst/.composer \
    COMPOSER_MEMORY_LIMIT=-1
