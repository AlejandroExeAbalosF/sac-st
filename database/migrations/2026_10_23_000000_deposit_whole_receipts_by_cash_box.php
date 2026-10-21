<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada cobro de caja se deposita entero, en su propio traslado — Multas M06,
 * DER físico §7.
 *
 * Haberes deposita por cuota: un traslado lleva las asignaciones de una
 * cuota, y un cheque puede viajar repartido entre varias. Multas no: cada
 * cobro recibido por la caja va al banco completo, solo, y a la cuenta con
 * la que opera su libro. Es una política de la caja
 * (`cash_boxes.deposits_whole_receipts`), no de Multas, para que Aranceles la
 * tome el día que la confirme el área.
 *
 * Con la política:
 * - un traslado lleva un ítem, por el importe entero de una recepción de la
 *   misma caja, recibida por el circuito, sin asignación a cuota;
 * - la recepción tiene un solo traslado vigente; los cancelados quedan;
 * - la cuenta es la del libro de la caja: la misma moneda no alcanza;
 * - la acreditación es un crédito del extracto entero y sin otro uso: el
 *   remanente de un crédito mayor no es la acreditación de un depósito.
 *
 * Y para todas las cajas, el traslado solo avanza desde `deposited`: a
 * acreditado o a cancelado. Es lo que ya hacía Haberes; ahora lo dice la base.
 */
return new class extends Migration
{
    private const GUARDA_CON_TRANSICIONES = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_to_bank_transfers_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.deposit_event_id IS DISTINCT FROM OLD.deposit_event_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.deposit_date IS DISTINCT FROM OLD.deposit_date
                THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.credit_event_id IS NOT NULL
                    AND NEW.credit_event_id IS DISTINCT FROM OLD.credit_event_id
                THEN
                    RAISE EXCEPTION 'La acreditacion de un traslado no se reasigna.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'deposited' AND NEW.status IN ('bank_confirmed', 'cancelled'))
                THEN
                    RAISE EXCEPTION 'Un traslado % no pasa a %: solo avanza el que espera acreditación.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    private const GUARDA_ORIGINAL = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_to_bank_transfers_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.deposit_event_id IS DISTINCT FROM OLD.deposit_event_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.deposit_date IS DISTINCT FROM OLD.deposit_date
                THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.credit_event_id IS NOT NULL
                    AND NEW.credit_event_id IS DISTINCT FROM OLD.credit_event_id
                THEN
                    RAISE EXCEPTION 'La acreditacion de un traslado no se reasigna.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cash_boxes ADD COLUMN deposits_whole_receipts boolean NOT NULL DEFAULT false;
            UPDATE cash_boxes SET deposits_whole_receipts = true WHERE code = 'multas';

            CREATE OR REPLACE FUNCTION transfer_follows_box_policy(p_transfer bigint) RETURNS void AS $$
            DECLARE
                v_traslado RECORD;
                v_cuenta bigint;
                v_items integer;
                v_item RECORD;
                v_vigentes integer;
            BEGIN
                SELECT t.id, t.cash_box_id, t.bank_account_id, t.amount, t.status, b.deposits_whole_receipts, a.currency
                  INTO v_traslado
                  FROM cash_to_bank_transfers t
                  JOIN cash_boxes b ON b.id = t.cash_box_id
                  JOIN bank_accounts a ON a.id = t.bank_account_id
                 WHERE t.id = p_transfer;

                IF NOT FOUND OR NOT v_traslado.deposits_whole_receipts THEN
                    RETURN;
                END IF;

                SELECT bank_account_id INTO v_cuenta
                  FROM cash_book_openings
                 WHERE cash_box_id = v_traslado.cash_box_id AND currency = v_traslado.currency;

                IF v_cuenta IS DISTINCT FROM v_traslado.bank_account_id THEN
                    RAISE EXCEPTION 'Esta caja deposita en la cuenta con la que opera su libro (%), no en la %.',
                        v_cuenta, v_traslado.bank_account_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT count(*) INTO v_items FROM cash_to_bank_transfer_items WHERE cash_to_bank_transfer_id = p_transfer;

                IF v_items <> 1 THEN
                    RAISE EXCEPTION 'Un traslado de esta caja lleva un solo cobro, entero: tiene %.', v_items
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT i.fund_receipt_id, i.funding_allocation_id, i.amount, r.amount AS receipt_amount,
                       r.cash_box_id AS receipt_box, r.origin, r.medium
                  INTO v_item
                  FROM cash_to_bank_transfer_items i
                  JOIN fund_receipts r ON r.id = i.fund_receipt_id
                 WHERE i.cash_to_bank_transfer_id = p_transfer;

                IF v_item.funding_allocation_id IS NOT NULL
                    OR v_item.receipt_box IS DISTINCT FROM v_traslado.cash_box_id
                    OR v_item.origin <> 'received'
                    OR v_item.medium NOT IN ('cash', 'cheque') THEN
                    RAISE EXCEPTION 'Esta caja deposita cobros recibidos por su mostrador, sin asignarlos a nadie.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_item.amount <> v_item.receipt_amount OR v_traslado.amount <> v_item.receipt_amount THEN
                    RAISE EXCEPTION 'El cobro viaja entero: % y no %.', v_item.receipt_amount, v_item.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT count(DISTINCT t.id) INTO v_vigentes
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = v_item.fund_receipt_id AND t.status <> 'cancelled';

                IF v_vigentes > 1 THEN
                    RAISE EXCEPTION 'Ese cobro ya tiene un traslado vigente: uno solo por cobro.'
                        USING ERRCODE = 'unique_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION transfer_follows_box_policy_transfer() RETURNS trigger AS $$
            BEGIN
                PERFORM transfer_follows_box_policy(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION transfer_follows_box_policy_item() RETURNS trigger AS $$
            BEGIN
                PERFORM transfer_follows_box_policy(NEW.cash_to_bank_transfer_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Diferidos: el traslado y su ítem se insertan en dos sentencias.
            CREATE CONSTRAINT TRIGGER transfer_follows_box_policy_transfer
                AFTER INSERT ON cash_to_bank_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION transfer_follows_box_policy_transfer();
            CREATE CONSTRAINT TRIGGER transfer_follows_box_policy_item
                AFTER INSERT ON cash_to_bank_transfer_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION transfer_follows_box_policy_item();

            CREATE OR REPLACE FUNCTION cash_deposit_credit_whole() RETURNS trigger AS $$
            DECLARE
                v_entero boolean;
                v_movimiento numeric(19, 2);
                v_otros numeric(19, 2);
            BEGIN
                SELECT b.deposits_whole_receipts INTO v_entero
                  FROM financial_events e
                  JOIN cash_boxes b ON b.id = e.cash_box_id
                 WHERE e.id = NEW.financial_event_id;

                IF NOT coalesce(v_entero, false) THEN
                    RETURN NEW;
                END IF;

                SELECT abs(amount) INTO v_movimiento FROM bank_transactions WHERE id = NEW.bank_transaction_id;
                SELECT coalesce(sum(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_otros
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id AND id <> NEW.id;

                IF NEW.amount <> v_movimiento OR v_otros <> 0 THEN
                    RAISE EXCEPTION 'El depósito de esta caja se acredita con un crédito entero y sin otro uso: % de %, con % ya imputado.',
                        NEW.amount, v_movimiento, v_otros
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_deposit_credit_whole
                AFTER INSERT ON bank_transaction_allocations
                FOR EACH ROW
                WHEN (NEW.allocation_role = 'cash_deposit_confirmation' AND NEW.reversal_of_id IS NULL)
                EXECUTE FUNCTION cash_deposit_credit_whole();
        SQL);

        // Solo avanza el que espera acreditación: a acreditado o a cancelado.
        DB::unprepared(self::GUARDA_CON_TRANSICIONES);
    }

    public function down(): void
    {
        DB::unprepared(self::GUARDA_ORIGINAL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER cash_deposit_credit_whole ON bank_transaction_allocations;
            DROP FUNCTION cash_deposit_credit_whole();
            DROP TRIGGER transfer_follows_box_policy_item ON cash_to_bank_transfer_items;
            DROP TRIGGER transfer_follows_box_policy_transfer ON cash_to_bank_transfers;
            DROP FUNCTION transfer_follows_box_policy_item();
            DROP FUNCTION transfer_follows_box_policy_transfer();
            DROP FUNCTION transfer_follows_box_policy(bigint);
            ALTER TABLE cash_boxes DROP COLUMN deposits_whole_receipts;
        SQL);
    }
};
