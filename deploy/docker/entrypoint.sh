#!/bin/sh
# Entrypoint de la imagen app: lo usan php-fpm, las migraciones y la cola.
# Corre como www-data, igual que el comando que termina ejecutando.
set -eu

cd /var/www/html

# storage/ es un bind-mount de una carpeta del servidor, y es la que guarda
# los adjuntos y los logs de auditoría. Si llega sin permisos, se corta acá
# con el motivo: el síntoma de dejarlo pasar es un 500 en la primera
# pantalla que escriba algo, y un log que no se puede escribir para contarlo.
if [ ! -w storage ]; then
    echo "sacst: storage/ no es escribible por $(id -un) (uid $(id -u))." >&2
    echo "sacst: en el servidor, la carpeta de SACST_STORAGE_PATH tiene que ser de 33:33. Ver deploy/README.md." >&2
    exit 1
fi

# La carpeta puede llegar vacía la primera vez.
mkdir -p \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs/audit

# Configuración, rutas, eventos y vistas cacheadas. Se hace al arrancar y no
# en el build porque la configuración depende del entorno del servidor, que
# la imagen no conoce.
if [ "${SACST_OPTIMIZE:-false}" = "true" ]; then
    php artisan optimize --no-ansi
fi

exec "$@"
