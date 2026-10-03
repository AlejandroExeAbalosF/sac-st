<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un cheque del sistema anterior se asigna entero, y sigue en la cartera
 * aunque se libere.
 *
 * El cheque es un papel que se entrega o se deposita entero: no hay forma
 * de darle al beneficiario «$35.000 de un cheque de $40.000». Si se
 * repartiera entre cuotas, al entregar una se marcaría entregado el papel
 * completo y el libro conservaría el resto en `CHEQUES_IN_CUSTODY` sin
 * ningún cheque que lo respalde. Por eso, para la plata del sistema
 * anterior —cheques de la apertura o identificados al apartar—, lo
 * asignado de un cheque es **cero o el cheque entero**.
 *
 * Y un cheque identificado al apartar es un papel de la cartera, no un
 * importe de un solo uso: liberada su asignación, vuelve a poder apartarse
 * para otra cuota, igual que uno de la apertura. Lo que sigue siendo de un
 * solo uso es el efectivo y el depósito directo apartados, que son montos.
 *
 * La cuenta de un depósito directo apartado tiene que estar en la moneda
 * de la recepción: un depósito en pesos no está en una cuenta en dólares.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION allocation_respects_origin() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_tipo TEXT;
                v_anterior NUMERIC(19,2);
                v_actual NUMERIC(19,2);
            BEGIN
                IF NEW.allocation_kind = 'reversal' THEN
                    RETURN NEW;
                END IF;

                SELECT origin, medium, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.allocation_event_id;

                IF v_recepcion.origin = 'received' AND v_tipo IS DISTINCT FROM 'funds_allocated' THEN
                    RAISE EXCEPTION 'Lo que entró por el circuito se asigna con funds_allocated, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_tipo IS DISTINCT FROM 'legacy_funds_allocated' THEN
                    RAISE EXCEPTION 'El dinero del sistema anterior se asigna apartándolo de LEGACY_FUNDS, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                -- El efectivo y el depósito apartados son montos de un solo uso;
                -- un cheque es un papel de la cartera y se puede volver a apartar.
                IF v_recepcion.origin = 'legacy'
                    AND v_recepcion.medium <> 'cheque'
                    AND NEW.allocation_event_id <> v_recepcion.financial_event_id THEN
                    RAISE EXCEPTION 'Una recepción apartada del sistema anterior no se vuelve a asignar.'
                        USING ERRCODE = 'check_violation';
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                SELECT
                    COALESCE(SUM(CASE WHEN r.origin IN ('opening', 'legacy')
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0),
                    COALESCE(SUM(CASE WHEN r.origin = 'received'
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0)
                  INTO v_anterior, v_actual
                  FROM funding_allocations a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                 WHERE a.beneficiary_installment_id = NEW.beneficiary_installment_id;

                IF v_recepcion.origin = 'received' AND v_anterior > 0 THEN
                    RAISE EXCEPTION 'La cuota está financiada con dinero del sistema anterior: no admite dinero actual.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_actual > 0 THEN
                    RAISE EXCEPTION 'La cuota tiene dinero actual asignado: no admite dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'received' AND EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo de ingreso de papel: solo se financia con dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION legacy_cheque_stays_whole() RETURNS trigger AS $$
            DECLARE
                v_cheque RECORD;
                v_asignado NUMERIC(19,2);
            BEGIN
                SELECT id, amount, cheque_number INTO v_cheque
                  FROM fund_receipts
                 WHERE id = NEW.fund_receipt_id
                   AND medium = 'cheque'
                   AND origin IN ('opening', 'legacy');

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END), 0)
                  INTO v_asignado
                  FROM funding_allocations
                 WHERE fund_receipt_id = v_cheque.id;

                IF v_asignado <> 0 AND v_asignado <> v_cheque.amount THEN
                    RAISE EXCEPTION 'El cheque % es de % y quedaría asignado por %: un cheque del sistema anterior se asigna y se libera entero.',
                        v_cheque.cheque_number, v_cheque.amount, v_asignado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER funding_allocations_legacy_cheque_whole
                AFTER INSERT ON funding_allocations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION legacy_cheque_stays_whole();

            CREATE OR REPLACE FUNCTION fund_receipts_bank_account_currency() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM bank_accounts
                     WHERE id = NEW.bank_account_id AND currency = NEW.currency
                ) THEN
                    RAISE EXCEPTION 'La cuenta % no es de la moneda de la recepción (%).', NEW.bank_account_id, NEW.currency
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_bank_account_currency
                BEFORE INSERT ON fund_receipts
                FOR EACH ROW
                WHEN (NEW.bank_account_id IS NOT NULL)
                EXECUTE FUNCTION fund_receipts_bank_account_currency();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fund_receipts_bank_account_currency ON fund_receipts;
            DROP FUNCTION IF EXISTS fund_receipts_bank_account_currency();
            DROP TRIGGER IF EXISTS funding_allocations_legacy_cheque_whole ON funding_allocations;
            DROP FUNCTION IF EXISTS legacy_cheque_stays_whole();

            CREATE OR REPLACE FUNCTION allocation_respects_origin() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_tipo TEXT;
                v_anterior NUMERIC(19,2);
                v_actual NUMERIC(19,2);
            BEGIN
                IF NEW.allocation_kind = 'reversal' THEN
                    RETURN NEW;
                END IF;

                SELECT origin, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.allocation_event_id;

                IF v_recepcion.origin = 'received' AND v_tipo IS DISTINCT FROM 'funds_allocated' THEN
                    RAISE EXCEPTION 'Lo que entró por el circuito se asigna con funds_allocated, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_tipo IS DISTINCT FROM 'legacy_funds_allocated' THEN
                    RAISE EXCEPTION 'El dinero del sistema anterior se asigna apartándolo de LEGACY_FUNDS, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'legacy' AND NEW.allocation_event_id <> v_recepcion.financial_event_id THEN
                    RAISE EXCEPTION 'Una recepción apartada del sistema anterior no se vuelve a asignar.'
                        USING ERRCODE = 'check_violation';
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                SELECT
                    COALESCE(SUM(CASE WHEN r.origin IN ('opening', 'legacy')
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0),
                    COALESCE(SUM(CASE WHEN r.origin = 'received'
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0)
                  INTO v_anterior, v_actual
                  FROM funding_allocations a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                 WHERE a.beneficiary_installment_id = NEW.beneficiary_installment_id;

                IF v_recepcion.origin = 'received' AND v_anterior > 0 THEN
                    RAISE EXCEPTION 'La cuota está financiada con dinero del sistema anterior: no admite dinero actual.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_actual > 0 THEN
                    RAISE EXCEPTION 'La cuota tiene dinero actual asignado: no admite dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'received' AND EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo de ingreso de papel: solo se financia con dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
