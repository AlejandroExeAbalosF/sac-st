<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `f_unaccent` nombra su extensión con el esquema.
 *
 * `pg_restore` trabaja con `search_path` vacío, y las columnas generadas de
 * `people` y `expedientes` llaman a esta función al crearse. Sin el esquema,
 * `unaccent` no se encontraba y esas dos tablas no se restauraban: un backup
 * de `pg_dump` parecía bueno hasta el día de usarlo.
 *
 * Se califican la función y también el diccionario, que se resuelve por
 * `search_path` igual que ella. Se prefiere esto a `SET search_path` en la
 * función porque esa cláusula impide que PostgreSQL la expanda en línea.
 *
 * El resultado no cambia, así que no hay columnas ni índices que recalcular.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT unaccent('unaccent', $1) $$;
        SQL);
    }
};
