#!/bin/bash
# Preparación del entorno. Corre una sola vez por `docker compose up`, en el
# servicio `init`, y los demás servicios esperan a que termine bien.
#
# Está en un servicio aparte y no en el arranque de cada contenedor porque
# `app`, `queue` y `vite` levantan a la vez: tres `composer install` y tres
# `migrate` simultáneos sobre el mismo directorio y la misma base se pisan
# entre sí, y el error que tiran no se parece a la causa.
set -euo pipefail

paso() { printf '\n\033[1;34m==>\033[0m %s\n' "$1"; }

cd /var/www/html

paso 'Limpiando cachés compiladas'
# El proyecto entra por bind mount, así que `bootstrap/cache` puede traer
# rutas y configuración compiladas en el host —con separadores y rutas de
# Windows— que adentro del contenedor no resuelven. Se borran a mano y no
# con `optimize:clear`: ese comando necesita que la aplicación arranque, que
# es justamente lo que la caché vieja impide.
rm -f bootstrap/cache/{config,routes-v7,services,packages,events}.php
mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/logs

paso 'Configurando .env'
if [ ! -f .env ]; then
    cp .env.docker .env
    echo '.env creado a partir de .env.docker'
else
    # Un .env que ya existe es del desarrollador y no se pisa. Los datos de
    # conexión no hacen falta acá: docker-compose.yml los inyecta como
    # variables de entorno, y en Laravel esas le ganan al archivo.
    echo '.env ya existe: se respeta tal cual está'
fi

paso 'Instalando dependencias de PHP'
composer install --no-interaction --no-progress

if ! grep -qE '^APP_KEY=.+' .env; then
    paso 'Generando APP_KEY'
    php artisan key:generate --ansi
fi

paso 'Instalando dependencias de JavaScript'
# El store va adentro del volumen de `node_modules` y no en el home. pnpm
# puebla `node_modules` con enlaces duros al store, y un enlace duro no cruza
# sistemas de archivos: con el store en otro volumen, pnpm se da cuenta, se
# rinde y crea un `.pnpm-store` al lado del package.json. O sea, adentro del
# bind mount, que es el lugar más lento posible y además ensucia el repo.
pnpm install --frozen-lockfile --store-dir /var/www/html/node_modules/.pnpm-store

paso 'Esperando a PostgreSQL'
# El healthcheck del servicio `db` ya garantiza que el motor acepta
# conexiones, pero no que acepte *esta* credencial contra *esta* base.
for intento in $(seq 1 30); do
    if base=$(php docker/db-state.php ping 2>/dev/null); then
        echo "PostgreSQL responde en ${DB_HOST}:${DB_PORT}/${base}"
        break
    fi
    if [ "$intento" -eq 30 ]; then
        echo 'PostgreSQL no respondió tras 30 intentos.' >&2
        php docker/db-state.php ping
        exit 1
    fi
    sleep 1
done

paso 'Aplicando migraciones'
php artisan migrate --force --ansi

# El catálogo —permisos, series, cajas, etiquetas— y el usuario de
# desarrollo entran una sola vez. `DatabaseSeeder` crea el admin con un
# `username` único: correrlo dos veces falla, y falla tarde.
#
# Ningún dato de demo se carga acá: los cinco escenarios son alternativos
# entre sí y se piden por nombre. Están documentados en docker/README.md.
if [ "$(php docker/db-state.php users)" = "0" ]; then
    paso 'Sembrando catálogo y usuario de desarrollo'
    php artisan db:seed --force --ansi
else
    paso 'La base ya tiene usuarios: no se siembra nada'
fi

paso 'Generando tipos de TypeScript'
php artisan typescript:transform --ansi

printf '\n\033[1;32m==> Listo.\033[0m El sistema queda en http://localhost:8000\n'
printf '    Usuario: \033[1madmin\033[0m   Contraseña: \033[1mContrasena.Segura.2026\033[0m\n\n'
