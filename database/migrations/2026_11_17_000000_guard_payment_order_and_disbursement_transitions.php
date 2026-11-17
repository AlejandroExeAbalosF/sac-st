<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las transiciones de la Orden de Pago y del egreso, impuestas en la base.
 *
 * Hasta acá vivían solo en los Actions, y una se escapaba: anular una Orden
 * con su transferencia en curso dejaba al egreso atado a la Orden anulada, y
 * al validarlo la Orden volvía de `voided` a `completed` mientras la que la
 * reemplazaba quedaba activa sobre una cuota pagada. Ahora la base lo impide
 * aunque un Action se equivoque:
 *
 * - la Orden: `completed`, `rejected` y `voided` son finales; no se anula ni
 *   rechaza con un egreso vivo, y no se completa sin su egreso confirmado;
 * - el egreso: `failed` y `reversed` son finales y no se nace en ellos;
 *   `confirmed` no cambia; a `failed` se llega sin débito y con motivo de la
 *   lista; a `reversed` no se llega todavía, porque su circuito (la
 *   contrapartida) no existe; de uno cerrado solo cambia el SET NULL de sus
 *   autores cuando se borra el usuario;
 * - un egreso con Orden solo nace o se confirma con la Orden activa;
 * - un egreso confirmado no supera lo que la cuota tiene financiado, y la entrega y la
 *   liberación de fondos se serializan sobre la fila de la cuota.
 *
 * El motivo de «dar por fallida la transferencia» no está en el relevamiento:
 * es una propuesta para confirmar con el área (`HaberesReasonAct::TransferFailure`).
 */
return new class extends Migration
{
    /** Los mismos códigos que `HaberesReasonAct::TransferFailure`. */
    private const FAILURE_CODES = "'wrong_beneficiary_data', 'bank_rejected', 'transfer_not_made', 'data_entry_error', 'other'";

    public function up(): void
    {
        $codigos = self::FAILURE_CODES;

        DB::unprepared(<<<SQL
            ALTER TABLE disbursements ADD COLUMN failure_reason_code varchar(60);

            -- Ningún egreso llegó a «failed» por la aplicación; si alguno lo hizo
            -- a mano, su motivo libre queda como detalle de «Otro».
            UPDATE disbursements SET failure_reason_code = 'other' WHERE status = 'failed';

            ALTER TABLE disbursements DROP CONSTRAINT disbursements_failure_check;
            ALTER TABLE disbursements ADD CONSTRAINT disbursements_failure_check
                CHECK (
                    (status = 'failed') = (failure_reason_code IS NOT NULL)
                    AND (failure_reason_code IS NULL OR failure_reason_code IN ({$codigos}))
                    AND (failure_reason_code IS DISTINCT FROM 'other' OR failure_reason IS NOT NULL)
                );
        SQL);

        DB::unprepared(self::APPEND_ONLY_WITH_TRANSITIONS);

        DB::unprepared(<<<'SQL'
            -- `disbursements_append_only` solo mira UPDATE y DELETE: sin esto,
            -- un INSERT dejaba nacer un egreso ya fallido (con débito) o
            -- revertido (sin contrapartida). A los dos se llega por transición.
            CREATE OR REPLACE FUNCTION disbursements_born_open() RETURNS trigger AS $$
            BEGIN
                IF NEW.status IN ('failed', 'reversed') THEN
                    RAISE EXCEPTION 'Un egreso no nace %: se llega por transición desde uno en curso.', NEW.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER disbursements_born_open
                BEFORE INSERT ON disbursements
                FOR EACH ROW EXECUTE FUNCTION disbursements_born_open();

            CREATE OR REPLACE FUNCTION disbursements_order_active() RETURNS trigger AS $$
            DECLARE
                estado_orden text;
            BEGIN
                IF NEW.payment_order_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND NOT (NEW.status = 'confirmed' AND OLD.status <> 'confirmed') THEN
                    RETURN NEW;
                END IF;

                -- Bloqueada: una anulación concurrente espera a que esto termine
                -- y entonces ve el egreso vivo.
                SELECT status INTO estado_orden FROM payment_orders WHERE id = NEW.payment_order_id FOR UPDATE;

                IF estado_orden IN ('completed', 'rejected', 'voided') THEN
                    RAISE EXCEPTION 'La Orden % ya no está vigente: el egreso no se registra ni se confirma contra ella.',
                        NEW.payment_order_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER disbursements_order_active
                BEFORE INSERT OR UPDATE OF status ON disbursements
                FOR EACH ROW EXECUTE FUNCTION disbursements_order_active();

            CREATE OR REPLACE FUNCTION payment_orders_status_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.status IS NOT DISTINCT FROM OLD.status THEN
                    RETURN NEW;
                END IF;

                IF OLD.status IN ('completed', 'rejected', 'voided') THEN
                    RAISE EXCEPTION 'Una Orden % no cambia de estado.', OLD.status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status IN ('rejected', 'voided') AND EXISTS (
                    SELECT 1 FROM disbursements
                     WHERE payment_order_id = NEW.id
                       AND status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La Orden % tiene una transferencia en curso: hay que darla por fallida antes.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = 'completed' AND NOT EXISTS (
                    SELECT 1 FROM disbursements
                     WHERE payment_order_id = NEW.id
                       AND status = 'confirmed'
                ) THEN
                    RAISE EXCEPTION 'La Orden % no se completa sin su egreso confirmado.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_orders_status_guard
                BEFORE UPDATE OF status ON payment_orders
                FOR EACH ROW EXECUTE FUNCTION payment_orders_status_guard();

            -- La entrega y la liberación de fondos compiten por la misma cuota:
            -- las dos bloquean su fila antes de mirar a la otra.
            CREATE OR REPLACE FUNCTION funding_allocations_reversal_without_disbursement() RETURNS trigger AS $$
            BEGIN
                IF NEW.allocation_kind <> 'reversal' THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM disbursements
                    WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                      AND status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un egreso registrado: sus fondos no se pueden liberar.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION disbursements_within_funding() RETURNS trigger AS $$
            DECLARE
                financiado numeric(19,2);
            BEGIN
                -- El dinero sale al confirmar: el mostrador nace confirmado y la
                -- transferencia llega a confirmarse. Un egreso en curso no
                -- mueve nada, y su Orden ya exigió la cuota completa.
                IF NEW.status <> 'confirmed' OR (TG_OP = 'UPDATE' AND OLD.status = 'confirmed') THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                SELECT COALESCE(SUM(CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END), 0)
                  INTO financiado
                  FROM funding_allocations
                 WHERE beneficiary_installment_id = NEW.beneficiary_installment_id;

                IF NEW.amount > financiado THEN
                    RAISE EXCEPTION 'El egreso de % supera lo que la cuota % tiene financiado (%).',
                        NEW.amount, NEW.beneficiary_installment_id, financiado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER disbursements_within_funding
                BEFORE INSERT OR UPDATE OF status ON disbursements
                FOR EACH ROW EXECUTE FUNCTION disbursements_within_funding();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS disbursements_within_funding ON disbursements;
            DROP FUNCTION IF EXISTS disbursements_within_funding();
            DROP TRIGGER IF EXISTS payment_orders_status_guard ON payment_orders;
            DROP FUNCTION IF EXISTS payment_orders_status_guard();
            DROP TRIGGER IF EXISTS disbursements_order_active ON disbursements;
            DROP FUNCTION IF EXISTS disbursements_order_active();
            DROP TRIGGER IF EXISTS disbursements_born_open ON disbursements;
            DROP FUNCTION IF EXISTS disbursements_born_open();

            CREATE OR REPLACE FUNCTION funding_allocations_reversal_without_disbursement() RETURNS trigger AS $$
            BEGIN
                IF NEW.allocation_kind = 'reversal' AND EXISTS (
                    SELECT 1 FROM disbursements
                    WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                      AND status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un egreso registrado: sus fondos no se pueden liberar.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(self::APPEND_ONLY_PREVIOUS);

        DB::unprepared(<<<'SQL'
            ALTER TABLE disbursements DROP CONSTRAINT disbursements_failure_check;
            UPDATE disbursements SET failure_reason = COALESCE(failure_reason, 'Otro') WHERE status = 'failed';
            ALTER TABLE disbursements ADD CONSTRAINT disbursements_failure_check
                CHECK (status <> 'failed' OR failure_reason IS NOT NULL);
            ALTER TABLE disbursements DROP COLUMN failure_reason_code;
        SQL);
    }

    /**
     * `disbursements_append_only()` con las transiciones de estado. Se
     * reescribe entera: la anterior está en `APPEND_ONLY_PREVIOUS`.
     */
    private const APPEND_ONLY_WITH_TRANSITIONS = <<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR NEW.payment_order_id IS DISTINCT FROM OLD.payment_order_id
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía, la Orden y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Un egreso cerrado queda como se cerró. Solo se admite lo que
                -- PostgreSQL escribe solo: el SET NULL de las FK a usuarios,
                -- que llega desde el trigger de la FK (profundidad > 1) y deja
                -- la columna en NULL. Cambiar de autor, o borrarlo a mano, no.
                IF OLD.status IN ('failed', 'reversed') AND (
                    (to_jsonb(NEW) - ARRAY['created_by', 'validated_by', 'cash_delivered_by'])
                        IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['created_by', 'validated_by', 'cash_delivered_by'])
                    OR (
                        (NEW.created_by IS DISTINCT FROM OLD.created_by
                            OR NEW.validated_by IS DISTINCT FROM OLD.validated_by
                            OR NEW.cash_delivered_by IS DISTINCT FROM OLD.cash_delivered_by)
                        AND NOT (
                            pg_trigger_depth() > 1
                            AND (NEW.created_by IS NULL OR NEW.created_by IS NOT DISTINCT FROM OLD.created_by)
                            AND (NEW.validated_by IS NULL OR NEW.validated_by IS NOT DISTINCT FROM OLD.validated_by)
                            AND (NEW.cash_delivered_by IS NULL OR NEW.cash_delivered_by IS NOT DISTINCT FROM OLD.cash_delivered_by)
                        )
                    )
                )
                THEN
                    RAISE EXCEPTION 'Un egreso % no se modifica.', OLD.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed' AND NEW.status <> 'confirmed' THEN
                    RAISE EXCEPTION 'Un egreso confirmado no cambia de estado: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- La reversión todavía no tiene circuito: sin contrapartida,
                -- entrar a «reversed» liberaría la cuota y la Orden por la puerta
                -- de atrás.
                IF NEW.status = 'reversed' THEN
                    RAISE EXCEPTION 'Un egreso no se da por revertido sin su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status = 'failed' AND (NEW.bank_transaction_id IS NOT NULL OR NEW.fee_bank_transaction_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'Un egreso con su débito reconocido no se da por fallido: hay que desvincular el débito antes.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                THEN
                    RAISE EXCEPTION 'El débito de un egreso confirmado no se cambia: hay un asiento que lo referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.fee_bank_transaction_id IS DISTINCT FROM OLD.fee_bank_transaction_id
                THEN
                    RAISE EXCEPTION 'La comisión de un egreso confirmado no se cambia: hay un asiento que la referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** La de `2026_11_10_020000_add_bank_fee_to_disbursements`, para el `down()`. */
    private const APPEND_ONLY_PREVIOUS = <<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                THEN
                    RAISE EXCEPTION 'El débito de un egreso confirmado no se cambia: hay un asiento que lo referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.fee_bank_transaction_id IS DISTINCT FROM OLD.fee_bank_transaction_id
                THEN
                    RAISE EXCEPTION 'La comisión de un egreso confirmado no se cambia: hay un asiento que la referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;
};
