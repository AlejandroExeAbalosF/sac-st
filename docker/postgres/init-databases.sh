#!/bin/bash
# Postgres corre este script una sola vez: cuando el volumen de datos nace
# vacío. `POSTGRES_DB` ya creó la base de desarrollo; falta la de tests.
#
# Son dos bases y no una a propósito. Los tests corren con RefreshDatabase,
# que vacía lo que encuentra: apuntarlos a la base de desarrollo borraría
# los datos cargados a mano para probar el circuito.
set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-SQL
    CREATE DATABASE ${POSTGRES_TEST_DB} OWNER ${POSTGRES_USER};
SQL

echo "Base de tests '${POSTGRES_TEST_DB}' creada."
