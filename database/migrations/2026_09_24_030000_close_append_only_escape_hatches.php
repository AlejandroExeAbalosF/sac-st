<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las dos salidas de los triggers append-only ya no las abre cualquiera.
 *
 * `bank_transactions` y `attachments` dejaban borrar si la transacción
 * declaraba `SET LOCAL sacst.allow_*`. Pero un parámetro así lo puede fijar
 * cualquier rol, `sacst_app` incluido: la puerta la sostenía solo la
 * convención del código. Y la de `attachments` no preguntaba qué se
 * borraba, así que por ahí se iba el PDF de cualquier recibo u orden.
 *
 * Ahora el trigger exige además que quien borra sea el **dueño** de la
 * tabla. En producción la app entra con `sacst_app`, que no lo es; lo es
 * `sacst_owner`, el rol de las migraciones, y con él se crean las dos
 * únicas funciones que borran, `SECURITY DEFINER`:
 *
 * - `forget_statement_attachment(id)`: solo el archivo de un extracto
 *   importado, que es lo único que se borra al revertir una importación;
 * - `discard_statement_transaction(id)`: solo un movimiento todavía
 *   pendiente, sin conciliar, que es lo que la reversión elimina.
 *
 * La marca `sacst.allow_*` sigue: la fija la función, no la app. En
 * desarrollo todo corre con el dueño y el trigger se comporta igual que
 * antes; lo que cambia es producción, que es donde importa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sacst_runs_as_owner(p_table oid) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT pg_has_role(current_user, relowner, 'USAGE') FROM pg_class WHERE oid = p_table
            $$;

            CREATE OR REPLACE FUNCTION bank_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_bank_transaction_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un movimiento bancario no se borra: se revierte la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.value_date IS DISTINCT FROM OLD.value_date
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.causal_code IS DISTINCT FROM OLD.causal_code
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.balance_after IS DISTINCT FROM OLD.balance_after
                    OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                THEN
                    RAISE EXCEPTION 'Los datos de un movimiento bancario no se editan: son lo que informó el banco.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION forget_statement_attachment(p_attachment_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_subject text;
            BEGIN
                SELECT subject_type INTO v_subject FROM attachments WHERE id = p_attachment_id;

                IF v_subject IS NULL THEN
                    RAISE EXCEPTION 'El adjunto % no existe.', p_attachment_id
                        USING ERRCODE = 'no_data_found';
                END IF;

                IF v_subject <> 'import' THEN
                    RAISE EXCEPTION 'Solo se borra el archivo de un extracto importado, al revertir la importación.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                PERFORM set_config('sacst.allow_attachment_delete', 'on', true);
                DELETE FROM attachments WHERE id = p_attachment_id;
                PERFORM set_config('sacst.allow_attachment_delete', 'off', true);
            END;
            $$;

            CREATE OR REPLACE FUNCTION discard_statement_transaction(p_transaction_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_status text;
            BEGIN
                SELECT reconciliation_status INTO v_status FROM bank_transactions WHERE id = p_transaction_id;

                IF v_status IS NULL THEN
                    RAISE EXCEPTION 'El movimiento % no existe.', p_transaction_id
                        USING ERRCODE = 'no_data_found';
                END IF;

                IF v_status <> 'pending' THEN
                    RAISE EXCEPTION 'Solo se descarta un movimiento sin conciliar, al revertir la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                PERFORM set_config('sacst.allow_bank_transaction_delete', 'on', true);
                DELETE FROM bank_transactions WHERE id = p_transaction_id;
                PERFORM set_config('sacst.allow_bank_transaction_delete', 'off', true);
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS discard_statement_transaction(bigint);
            DROP FUNCTION IF EXISTS forget_statement_attachment(bigint);

            CREATE OR REPLACE FUNCTION bank_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_bank_transaction_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un movimiento bancario no se borra: se revierte la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.value_date IS DISTINCT FROM OLD.value_date
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.causal_code IS DISTINCT FROM OLD.causal_code
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.balance_after IS DISTINCT FROM OLD.balance_after
                    OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                THEN
                    RAISE EXCEPTION 'Los datos de un movimiento bancario no se editan: son lo que informó el banco.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS sacst_runs_as_owner(oid);
        SQL);
    }
};
