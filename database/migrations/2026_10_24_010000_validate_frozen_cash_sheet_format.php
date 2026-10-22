<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La evidencia del cierre tiene que poder leerse y dibujarse.
 *
 * La guarda anterior comparaba la versión como texto —aceptaba `"2"`, que
 * el lector rechaza porque espera el número— y solo pedía que las columnas
 * del reverso fueran una lista no vacía: admitía una columna sin encabezado,
 * o `null`, y el generador desalineaba datos y encabezados. Ahora la versión
 * es un número entero admitido y cada columna es un objeto con `key` y `header`
 * de texto no vacío, sin claves repetidas.
 *
 * Solo rige al insertar: la evidencia ya guardada la escribió el sistema con
 * el formato correcto, y es append-only.
 */
return new class extends Migration
{
    private const string FUNCION = <<<'SQL'
        CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
        DECLARE
            v_columnas jsonb;
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                AND status = 'closed' AND evidence_version = NEW.version) THEN
                RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
            END IF;
            IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object'
                OR jsonb_typeof(NEW.payload->'schema') IS DISTINCT FROM 'number'
                -- Como texto: `2.0` también es un número, y el lector espera el entero.
                OR NEW.payload->>'schema' NOT IN ('1', '2') THEN
                RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
            END IF;
            IF NEW.payload->>'schema' = '2' THEN
                v_columnas := NEW.payload->'chequeColumns';
                IF jsonb_typeof(v_columnas) IS DISTINCT FROM 'array'
                    OR jsonb_array_length(v_columnas) = 0
                    OR NOT (NEW.payload ? 'bankColumnNote')
                    OR jsonb_typeof(NEW.payload->'bankColumnNote') NOT IN ('string', 'null')
                    OR EXISTS (
                        SELECT 1 FROM jsonb_array_elements(v_columnas) AS c(col)
                        WHERE jsonb_typeof(col) IS DISTINCT FROM 'object'
                           OR jsonb_typeof(col->'key') IS DISTINCT FROM 'string'
                           OR jsonb_typeof(col->'header') IS DISTINCT FROM 'string'
                           OR btrim(col->>'key') = ''
                           OR btrim(col->>'header') = ''
                    )
                    OR (SELECT count(DISTINCT col->>'key') FROM jsonb_array_elements(v_columnas) AS c(col))
                        <> jsonb_array_length(v_columnas)
                THEN
                    RAISE EXCEPTION 'La evidencia del cierre tiene que congelar las columnas del reverso y la aclaracion de la columna de banco.' USING ERRCODE = 'check_violation';
                END IF;
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

    public function up(): void
    {
        DB::unprepared(self::FUNCION);
    }

    public function down(): void
    {
        DB::unprepared(self::ANTERIOR);
    }
};
