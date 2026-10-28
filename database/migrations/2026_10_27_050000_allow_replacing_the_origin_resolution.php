<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La resolución de origen de una multa se reemplaza, en un acto propio.
 *
 * Hasta acá no había salida para una multa cargada con la resolución
 * equivocada —otro papel, no un número mal leído—: el importe original cita
 * su resolución, no se edita, y exige que conserve el papel de origen
 * (`2026_10_27_040000_require_the_role_each_debt_entry_cites`).
 *
 * El reemplazo (`ReplaceFineOriginResolution`) hace, en una transacción:
 * una revisión que cambia el documento —y la fecha, que es la de la
 * resolución— del importe original; la anulación del origen de la
 * resolución vieja; y el vínculo de origen de la nueva. Para eso:
 *
 * - `fine_identifier_revision_apply` admite revisar `fine_document_id`, solo
 *   en el importe original;
 * - `fine_debt_entries_date_only` deja que esa revisión lo escriba; importe,
 *   tipo y reversión siguen como nacieron;
 * - `fine_debt_entries_keep_document_role`, diferido al cierre de la
 *   transacción, exige que el documento nuevo quede vinculado con el papel
 *   que el movimiento pide: entre un paso y otro del reemplazo no lo está;
 * - la revisión acepta el motivo «Documento equivocado», que es el de este
 *   acto, solo sobre un movimiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_identifier_revision_apply() RETURNS trigger AS $$
            DECLARE
                v_tabla text;
                v_id bigint;
                v_permitidas text[];
                v_actual jsonb;
                v_claves text[];
                v_nuevas text[];
                v_campo text;
                v_prefijo text;
                v_columnas text;
                v_antes_fecha text;
                v_antes_precision text;
                v_despues_fecha text;
                v_despues_precision text;
                -- Listas explicitas: con LIKE '%_on' el guion bajo es comodin y
                -- «caption» pasaria por una fecha.
                v_prefijos CONSTANT text[] := ARRAY['issued', 'registered', 'effective', 'occurred'];
                v_fechas CONSTANT text[] := ARRAY[
                    'issued_on', 'registered_on', 'effective_on', 'occurred_on',
                    'issued_precision', 'registered_precision', 'effective_precision', 'occurred_precision'
                ];
            BEGIN
                IF NEW.fine_case_id IS NOT NULL THEN
                    v_tabla := 'fine_cases';
                    v_id := NEW.fine_case_id;
                    v_permitidas := ARRAY['number', 'year', 'suffix'];
                ELSIF NEW.fine_document_id IS NOT NULL THEN
                    v_tabla := 'fine_documents';
                    v_id := NEW.fine_document_id;
                    v_permitidas := ARRAY['act_type', 'number', 'year', 'issued_on', 'issued_precision'];
                ELSIF NEW.fine_debt_entry_id IS NOT NULL THEN
                    v_tabla := 'fine_debt_entries';
                    v_id := NEW.fine_debt_entry_id;
                    v_permitidas := ARRAY['effective_on', 'effective_precision'];

                    -- El documento del importe original se reemplaza (ReplaceFineOriginResolution).
                    IF (SELECT kind FROM fine_debt_entries WHERE id = v_id) = 'constitution' THEN
                        v_permitidas := v_permitidas || ARRAY['fine_document_id'];
                    END IF;
                ELSIF NEW.fine_historical_movement_id IS NOT NULL THEN
                    v_tabla := 'fine_historical_movements';
                    v_id := NEW.fine_historical_movement_id;
                    v_permitidas := ARRAY['occurred_on', 'occurred_precision'];
                ELSE
                    v_tabla := 'fines';
                    v_id := NEW.fine_id;
                    v_permitidas := ARRAY['caption', 'person_id', 'registered_on', 'registered_precision'];
                END IF;

                EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id = $1 FOR UPDATE', v_tabla)
                    INTO v_actual USING v_id;

                SELECT array_agg(c ORDER BY c) INTO v_claves FROM jsonb_object_keys(NEW.old_values) c;
                SELECT array_agg(c ORDER BY c) INTO v_nuevas FROM jsonb_object_keys(NEW.new_values) c;

                IF v_claves IS NULL OR v_claves IS DISTINCT FROM v_nuevas THEN
                    RAISE EXCEPTION 'La revision tiene que decir el valor anterior y el nuevo de los mismos datos.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (v_claves <@ v_permitidas) THEN
                    RAISE EXCEPTION 'Esos datos de % no se revisan: %.', v_tabla, array_to_string(v_claves, ', ')
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Una fecha y su precision viajan juntas: separadas, el estado
                -- intermedio no cumpliria las CHECK de la tabla.
                FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                    IF ((v_prefijo || '_on') = ANY (v_claves)) <> ((v_prefijo || '_precision') = ANY (v_claves)) THEN
                        RAISE EXCEPTION 'La fecha % se revisa junto con su precision.', v_prefijo || '_on'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                FOREACH v_campo IN ARRAY v_claves LOOP
                    IF (v_actual -> v_campo) IS DISTINCT FROM (NEW.old_values -> v_campo) THEN
                        /*
                         * `check_violation` y no `serialization_failure`:
                         * Laravel trata ese codigo como un choque de
                         * concurrencia y lo reintenta o lo envuelve. Esto no
                         * se arregla reintentando: hay que volver a leer.
                         */
                        RAISE EXCEPTION 'El dato % cambio desde que se abrio el formulario: volve a cargarlo.', v_campo
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                IF NEW.old_values = NEW.new_values THEN
                    RAISE EXCEPTION 'La revision no cambia ningun dato.'
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 * Completar es llenar un vacio o precisar una fecha sin
                 * moverla de mes. Cualquier otra cosa es una correccion y
                 * lleva motivo.
                 *
                 * La fecha y su precision se juzgan como un par, aunque el
                 * valor de la fecha no cambie: «01/05/2026» con dia a «mayo
                 * de 2026» deja la fecha igual y pierde precision, y eso es
                 * corregir (revision del 2026-10-20, R2).
                 */
                IF NEW.kind = 'completion' THEN
                    FOREACH v_campo IN ARRAY v_claves LOOP
                        IF v_campo = ANY (v_fechas)
                           OR (NEW.old_values -> v_campo) = (NEW.new_values -> v_campo) THEN
                            CONTINUE;
                        END IF;

                        IF jsonb_typeof(NEW.old_values -> v_campo) <> 'null' THEN
                            RAISE EXCEPTION 'El dato % ya tenia valor: cambiarlo es una correccion y lleva motivo.', v_campo
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END LOOP;

                    FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                        IF NOT ((v_prefijo || '_on') = ANY (v_claves)) THEN
                            CONTINUE;
                        END IF;

                        v_antes_fecha := NEW.old_values ->> (v_prefijo || '_on');
                        v_antes_precision := NEW.old_values ->> (v_prefijo || '_precision');
                        v_despues_fecha := NEW.new_values ->> (v_prefijo || '_on');
                        v_despues_precision := NEW.new_values ->> (v_prefijo || '_precision');

                        IF v_antes_fecha IS NOT DISTINCT FROM v_despues_fecha
                           AND v_antes_precision IS NOT DISTINCT FROM v_despues_precision THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'unknown' THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'month'
                           AND v_despues_precision = 'day'
                           AND date_trunc('month', v_despues_fecha::date)::date = v_antes_fecha::date THEN
                            CONTINUE;
                        END IF;

                        RAISE EXCEPTION 'Mover o volver menos precisa una fecha es una correccion y lleva motivo.'
                            USING ERRCODE = 'check_violation';
                    END LOOP;
                END IF;

                SELECT string_agg(quote_ident(c), ', ') INTO v_columnas FROM unnest(v_claves) c;

                -- Movimientos y antecedentes no llevan `updated_at`: su historia
                -- es la propia revision.
                EXECUTE format(
                    'UPDATE %I SET (%s) = (SELECT %s FROM jsonb_populate_record(NULL::%I, $1))%s WHERE id = $2',
                    v_tabla, v_columnas, v_columnas, v_tabla,
                    CASE WHEN v_tabla IN ('fine_debt_entries', 'fine_historical_movements') THEN '' ELSE ', updated_at = now()' END
                ) USING NEW.new_values, v_id;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_date_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'La tabla fine_debt_entries es append-only: no admite DELETE.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Desde una revision: la fecha, y el documento del importe original.
                IF pg_trigger_depth() < 2
                   OR (to_jsonb(NEW) - 'effective_on' - 'effective_precision' - 'fine_document_id')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'effective_on' - 'effective_precision' - 'fine_document_id')
                   OR (NEW.fine_document_id IS DISTINCT FROM OLD.fine_document_id AND OLD.kind <> 'constitution') THEN
                    RAISE EXCEPTION 'Un movimiento de deuda no se edita: solo su fecha, y el documento del importe original, se revisan.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_keep_document_role() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_document_id IS NOT NULL
                   AND fine_debt_entry_document_role(NEW.kind) IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM fine_document_links
                        WHERE fine_id = NEW.fine_id
                          AND fine_document_id = NEW.fine_document_id
                          AND revoked_at IS NULL
                          AND role = fine_debt_entry_document_role(NEW.kind)
                   ) THEN
                    RAISE EXCEPTION 'El movimiento % cita el documento % sin vincularlo a la multa con el papel %.', NEW.id, NEW.fine_document_id, fine_debt_entry_document_role(NEW.kind)
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_debt_entries_keep_document_role
                AFTER UPDATE OF fine_document_id ON fine_debt_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fine_debt_entries_keep_document_role();

            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check CHECK (
                    (kind = 'completion') = (reason_code IS NULL)
                    AND (reason_code IS NULL
                         OR reason_code IN ('data_entry_error', 'misread_paper', 'other')
                         OR (reason_code = 'wrong_document' AND fine_debt_entry_id IS NOT NULL))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason IS NULL OR reason_code IS NOT NULL)
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check CHECK (
                    (kind = 'completion') = (reason_code IS NULL)
                    AND (reason_code IS NULL OR reason_code IN ('data_entry_error', 'misread_paper', 'other'))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason IS NULL OR reason_code IS NOT NULL)
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                ) NOT VALID;

            DROP TRIGGER fine_debt_entries_keep_document_role ON fine_debt_entries;
            DROP FUNCTION fine_debt_entries_keep_document_role();

            CREATE OR REPLACE FUNCTION fine_identifier_revision_apply() RETURNS trigger AS $$
            DECLARE
                v_tabla text;
                v_id bigint;
                v_permitidas text[];
                v_actual jsonb;
                v_claves text[];
                v_nuevas text[];
                v_campo text;
                v_prefijo text;
                v_columnas text;
                v_antes_fecha text;
                v_antes_precision text;
                v_despues_fecha text;
                v_despues_precision text;
                -- Listas explicitas: con LIKE '%_on' el guion bajo es comodin y
                -- «caption» pasaria por una fecha.
                v_prefijos CONSTANT text[] := ARRAY['issued', 'registered', 'effective', 'occurred'];
                v_fechas CONSTANT text[] := ARRAY[
                    'issued_on', 'registered_on', 'effective_on', 'occurred_on',
                    'issued_precision', 'registered_precision', 'effective_precision', 'occurred_precision'
                ];
            BEGIN
                IF NEW.fine_case_id IS NOT NULL THEN
                    v_tabla := 'fine_cases';
                    v_id := NEW.fine_case_id;
                    v_permitidas := ARRAY['number', 'year', 'suffix'];
                ELSIF NEW.fine_document_id IS NOT NULL THEN
                    v_tabla := 'fine_documents';
                    v_id := NEW.fine_document_id;
                    v_permitidas := ARRAY['act_type', 'number', 'year', 'issued_on', 'issued_precision'];
                ELSIF NEW.fine_debt_entry_id IS NOT NULL THEN
                    v_tabla := 'fine_debt_entries';
                    v_id := NEW.fine_debt_entry_id;
                    v_permitidas := ARRAY['effective_on', 'effective_precision'];
                ELSIF NEW.fine_historical_movement_id IS NOT NULL THEN
                    v_tabla := 'fine_historical_movements';
                    v_id := NEW.fine_historical_movement_id;
                    v_permitidas := ARRAY['occurred_on', 'occurred_precision'];
                ELSE
                    v_tabla := 'fines';
                    v_id := NEW.fine_id;
                    v_permitidas := ARRAY['caption', 'person_id', 'registered_on', 'registered_precision'];
                END IF;

                EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id = $1 FOR UPDATE', v_tabla)
                    INTO v_actual USING v_id;

                SELECT array_agg(c ORDER BY c) INTO v_claves FROM jsonb_object_keys(NEW.old_values) c;
                SELECT array_agg(c ORDER BY c) INTO v_nuevas FROM jsonb_object_keys(NEW.new_values) c;

                IF v_claves IS NULL OR v_claves IS DISTINCT FROM v_nuevas THEN
                    RAISE EXCEPTION 'La revision tiene que decir el valor anterior y el nuevo de los mismos datos.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (v_claves <@ v_permitidas) THEN
                    RAISE EXCEPTION 'Esos datos de % no se revisan: %.', v_tabla, array_to_string(v_claves, ', ')
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Una fecha y su precision viajan juntas: separadas, el estado
                -- intermedio no cumpliria las CHECK de la tabla.
                FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                    IF ((v_prefijo || '_on') = ANY (v_claves)) <> ((v_prefijo || '_precision') = ANY (v_claves)) THEN
                        RAISE EXCEPTION 'La fecha % se revisa junto con su precision.', v_prefijo || '_on'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                FOREACH v_campo IN ARRAY v_claves LOOP
                    IF (v_actual -> v_campo) IS DISTINCT FROM (NEW.old_values -> v_campo) THEN
                        /*
                         * `check_violation` y no `serialization_failure`:
                         * Laravel trata ese codigo como un choque de
                         * concurrencia y lo reintenta o lo envuelve. Esto no
                         * se arregla reintentando: hay que volver a leer.
                         */
                        RAISE EXCEPTION 'El dato % cambio desde que se abrio el formulario: volve a cargarlo.', v_campo
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                IF NEW.old_values = NEW.new_values THEN
                    RAISE EXCEPTION 'La revision no cambia ningun dato.'
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 * Completar es llenar un vacio o precisar una fecha sin
                 * moverla de mes. Cualquier otra cosa es una correccion y
                 * lleva motivo.
                 *
                 * La fecha y su precision se juzgan como un par, aunque el
                 * valor de la fecha no cambie: «01/05/2026» con dia a «mayo
                 * de 2026» deja la fecha igual y pierde precision, y eso es
                 * corregir (revision del 2026-10-20, R2).
                 */
                IF NEW.kind = 'completion' THEN
                    FOREACH v_campo IN ARRAY v_claves LOOP
                        IF v_campo = ANY (v_fechas)
                           OR (NEW.old_values -> v_campo) = (NEW.new_values -> v_campo) THEN
                            CONTINUE;
                        END IF;

                        IF jsonb_typeof(NEW.old_values -> v_campo) <> 'null' THEN
                            RAISE EXCEPTION 'El dato % ya tenia valor: cambiarlo es una correccion y lleva motivo.', v_campo
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END LOOP;

                    FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                        IF NOT ((v_prefijo || '_on') = ANY (v_claves)) THEN
                            CONTINUE;
                        END IF;

                        v_antes_fecha := NEW.old_values ->> (v_prefijo || '_on');
                        v_antes_precision := NEW.old_values ->> (v_prefijo || '_precision');
                        v_despues_fecha := NEW.new_values ->> (v_prefijo || '_on');
                        v_despues_precision := NEW.new_values ->> (v_prefijo || '_precision');

                        IF v_antes_fecha IS NOT DISTINCT FROM v_despues_fecha
                           AND v_antes_precision IS NOT DISTINCT FROM v_despues_precision THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'unknown' THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'month'
                           AND v_despues_precision = 'day'
                           AND date_trunc('month', v_despues_fecha::date)::date = v_antes_fecha::date THEN
                            CONTINUE;
                        END IF;

                        RAISE EXCEPTION 'Mover o volver menos precisa una fecha es una correccion y lleva motivo.'
                            USING ERRCODE = 'check_violation';
                    END LOOP;
                END IF;

                SELECT string_agg(quote_ident(c), ', ') INTO v_columnas FROM unnest(v_claves) c;

                -- Movimientos y antecedentes no llevan `updated_at`: su historia
                -- es la propia revision.
                EXECUTE format(
                    'UPDATE %I SET (%s) = (SELECT %s FROM jsonb_populate_record(NULL::%I, $1))%s WHERE id = $2',
                    v_tabla, v_columnas, v_columnas, v_tabla,
                    CASE WHEN v_tabla IN ('fine_debt_entries', 'fine_historical_movements') THEN '' ELSE ', updated_at = now()' END
                ) USING NEW.new_values, v_id;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_date_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'La tabla fine_debt_entries es append-only: no admite DELETE.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF pg_trigger_depth() < 2
                   OR (to_jsonb(NEW) - 'effective_on' - 'effective_precision')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'effective_on' - 'effective_precision') THEN
                    RAISE EXCEPTION 'Un movimiento de deuda no se edita: solo su fecha se completa o corrige con una revision.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
