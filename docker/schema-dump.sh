#!/bin/bash
# Vuelca el esquema de una base auxiliar, normalizado para poder compararlo.
#
# El dump crudo no sirve para un diff: trae el nombre de la base, comentarios
# con la version y --sobre todo-- las filas de `migrations`, que son
# justamente lo que cambia al consolidar. Lo que tiene que coincidir es la
# estructura, no como se llego a ella.
#
# pg_dump 18 ademas abre y cierra con un token \restrict aleatorio distinto
# en cada corrida: sin sacarlo, dos volcados de la misma base ya difieren.
set -euo pipefail

BASE="${1:?falta el nombre de la base}"
SALIDA="${2:?falta el archivo de salida}"
PGDUMP="/c/Program Files/PostgreSQL/18/bin/pg_dump.exe"

eval "$(php -r '
require "vendor/autoload.php"; $app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c = config("database.connections.pgsql");
printf("export PGHOST=%s PGPORT=%s PGUSER=%s PGPASSWORD=%s\n",
    escapeshellarg($c["host"]), escapeshellarg($c["port"]),
    escapeshellarg($c["username"]), escapeshellarg($c["password"]));
')"

"$PGDUMP" --schema-only --no-owner --no-privileges --no-comments "$BASE" \
  | grep -v '^--' \
  | grep -viE 'restrict [a-z0-9]{40,}' \
  | grep -v '^SET ' \
  | grep -v '^SELECT pg_catalog.set_config' \
  | sed '/^$/d' \
  > "$SALIDA"

echo "$(wc -l < "$SALIDA") lineas -> $SALIDA"
