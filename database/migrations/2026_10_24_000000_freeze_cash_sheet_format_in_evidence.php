<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La evidencia del cierre congela también el formato de su reverso.
 *
 * Desde que Multas cierra, cada caja declara qué columnas identifican sus
 * cheques y qué representa su columna de banco. Si eso se leyera del
 * módulo al rehacer la planilla, una planilla vieja cambiaría de contenido
 * el día que cambie la configuración. `schema 2` lo guarda con los datos:
 * las columnas, que no pueden faltar ni venir vacías, y la aclaración de la
 * columna de banco, que puede ser nula pero tiene que estar.
 *
 * La evidencia de `schema 1` ya existe y es toda de Haberes: se sigue
 * aceptando tal cual, con sus columnas de siempre.
 */
return new class extends Migration
{
    private const string FUNCION = <<<'SQL'
        CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                AND status = 'closed' AND evidence_version = NEW.version) THEN
                RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
            END IF;
            IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object'
                OR NEW.payload->>'schema' IS NULL
                OR NEW.payload->>'schema' NOT IN ('1', '2') THEN
                RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
            END IF;
            IF NEW.payload->>'schema' = '2' AND (
                jsonb_typeof(NEW.payload->'chequeColumns') IS DISTINCT FROM 'array'
                OR jsonb_array_length(NEW.payload->'chequeColumns') = 0
                OR NOT (NEW.payload ? 'bankColumnNote')
                OR jsonb_typeof(NEW.payload->'bankColumnNote') NOT IN ('string', 'null')
            ) THEN
                RAISE EXCEPTION 'La evidencia del cierre tiene que congelar las columnas del reverso y la aclaracion de la columna de banco.' USING ERRCODE = 'check_violation';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

    private const string ANTERIOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                AND status = 'closed' AND evidence_version = NEW.version) THEN
                RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
            END IF;
            IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object' OR NEW.payload->>'schema' IS DISTINCT FROM '1' THEN
                RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        DB::unprepared(self::FUNCION);
    }

    public function down(): void
    {
        DB::unprepared(self::ANTERIOR);
    }
};
