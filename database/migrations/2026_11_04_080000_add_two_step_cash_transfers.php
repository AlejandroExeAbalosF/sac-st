<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El traspaso y su comprobante, en dos pasos (Aranceles, DER físico §4.3 bis).
 *
 * Es una política de la caja (`cash_boxes.deposits_in_two_steps`). El efectivo
 * sale de la caja al registrar el traspaso, que nace `awaiting_ticket`. El
 * comprobante llega después, sin asiento, y lo pasa a `deposited`. Recién ahí se
 * puede acreditar: la transición ya existente solo sale de `deposited`.
 *
 * `deposit_date` es la fecha del traspaso y `ticket_date`, la del papel.
 */
return new class extends Migration
{
    private const GUARDA_CON_COMPROBANTE = <<<'SQL'
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
                    AND NOT (OLD.status = 'deposited' AND NEW.status IN ('bank_confirmed', 'cancelled', 'bounced'))
                    AND NOT (OLD.status = 'awaiting_ticket' AND NEW.status IN ('deposited', 'cancelled'))
                THEN
                    RAISE EXCEPTION 'Un traslado % no pasa a %: solo avanza el que espera comprobante o acreditación.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                -- El comprobante entra una sola vez, y es lo que saca al traslado de la espera.
                IF (OLD.status = 'awaiting_ticket' AND NEW.status = 'deposited') IS DISTINCT FROM
                    (OLD.ticket_loaded_at IS NULL AND NEW.ticket_loaded_at IS NOT NULL)
                THEN
                    RAISE EXCEPTION 'El comprobante se carga una sola vez, sobre un traslado que lo espera.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Un comprobante cargado no se edita: se cancela y se registra otro traspaso.
                IF OLD.ticket_loaded_at IS NOT NULL AND (
                    NEW.ticket_date IS DISTINCT FROM OLD.ticket_date
                    OR NEW.ticket_loaded_at IS DISTINCT FROM OLD.ticket_loaded_at
                    OR NEW.ticket_loaded_by IS DISTINCT FROM OLD.ticket_loaded_by
                    OR NEW.ticket_idempotency_key IS DISTINCT FROM OLD.ticket_idempotency_key
                    OR NEW.deposit_time IS DISTINCT FROM OLD.deposit_time
                    OR NEW.deposit_operation_number IS DISTINCT FROM OLD.deposit_operation_number
                    OR NEW.deposit_terminal IS DISTINCT FROM OLD.deposit_terminal
                ) THEN
                    RAISE EXCEPTION 'Un comprobante cargado no se edita: se cancela el traspaso.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    private const GUARDA_ANTERIOR = <<<'SQL'
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
                    AND NOT (OLD.status = 'deposited' AND NEW.status IN ('bank_confirmed', 'cancelled', 'bounced'))
                THEN
                    RAISE EXCEPTION 'Un traslado % no pasa a %: solo avanza el que espera acreditación.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cash_boxes ADD COLUMN deposits_in_two_steps boolean NOT NULL DEFAULT false;
            UPDATE cash_boxes SET deposits_in_two_steps = true WHERE code = 'aranceles';

            ALTER TABLE cash_to_bank_transfers
                ADD COLUMN ticket_date date,
                ADD COLUMN ticket_loaded_at timestamptz,
                ADD COLUMN ticket_loaded_by bigint REFERENCES users (id) ON DELETE RESTRICT,
                ADD COLUMN ticket_idempotency_key varchar(120);

            CREATE UNIQUE INDEX cash_to_bank_transfers_ticket_idempotency_unique
                ON cash_to_bank_transfers (ticket_idempotency_key)
                WHERE ticket_idempotency_key IS NOT NULL;

            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_status_check;
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
                CHECK (status IN ('awaiting_ticket', 'deposited', 'bank_confirmed', 'cancelled', 'bounced'));

            -- Los datos del comprobante van juntos.
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_ticket_together
                CHECK (
                    (ticket_date IS NULL) = (ticket_loaded_at IS NULL)
                    AND (ticket_date IS NULL) = (ticket_loaded_by IS NULL)
                    AND (ticket_date IS NULL) = (ticket_idempotency_key IS NULL)
                );

            /*
             * El banco no recibe la plata antes de que la caja la cobre. Sí puede
             * ser anterior al traspaso: un traspaso cancelado por error se vuelve
             * a registrar con la fecha del día, y el papel conserva la suya.
             */
            CREATE OR REPLACE FUNCTION cash_transfer_ticket_after_cash() RETURNS trigger AS $$
            BEGIN
                IF NEW.ticket_date IS NOT NULL AND EXISTS (
                    SELECT 1 FROM cash_to_bank_transfer_items i
                      JOIN fund_receipts r ON r.id = i.fund_receipt_id
                     WHERE i.cash_to_bank_transfer_id = NEW.id AND r.received_date > NEW.ticket_date
                ) THEN
                    RAISE EXCEPTION 'El comprobante es anterior al efectivo que lleva el traslado %.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfer_ticket_after_cash BEFORE UPDATE OF ticket_date ON cash_to_bank_transfers
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_ticket_after_cash();

            -- Lo que espera el comprobante todavía no dice nada del papel.
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_awaiting_has_no_ticket
                CHECK (
                    status <> 'awaiting_ticket'
                    OR (ticket_date IS NULL AND deposit_time IS NULL
                        AND deposit_operation_number IS NULL AND deposit_terminal IS NULL)
                );

            -- La política de la caja dice cómo nace cada traslado.
            CREATE OR REPLACE FUNCTION cash_transfer_follows_two_steps() RETURNS trigger AS $$
            DECLARE
                v_dos_pasos boolean;
            BEGIN
                SELECT deposits_in_two_steps INTO v_dos_pasos FROM cash_boxes WHERE id = NEW.cash_box_id;

                IF coalesce(v_dos_pasos, false) IS DISTINCT FROM (NEW.status = 'awaiting_ticket') THEN
                    RAISE EXCEPTION 'Un traslado de esta caja no nace %.', NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfer_follows_two_steps BEFORE INSERT ON cash_to_bank_transfers
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_follows_two_steps();

            -- La foto es la prueba del comprobante. Diferido: se guarda después de la fila.
            CREATE OR REPLACE FUNCTION cash_transfer_ticket_has_photo() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM attachments
                     WHERE subject_type = 'cash_transfer' AND subject_id = NEW.id
                       AND document_type = 'cash_transfer_ticket'
                ) THEN
                    RAISE EXCEPTION 'El comprobante del traslado % no tiene su foto.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cash_transfer_ticket_has_photo
                AFTER UPDATE OF status ON cash_to_bank_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (OLD.status = 'awaiting_ticket' AND NEW.status = 'deposited')
                EXECUTE FUNCTION cash_transfer_ticket_has_photo();
        SQL);

        DB::unprepared(self::GUARDA_CON_COMPROBANTE);
    }

    public function down(): void
    {
        DB::unprepared(self::GUARDA_ANTERIOR);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER cash_transfer_ticket_has_photo ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_ticket_has_photo();
            DROP TRIGGER cash_transfer_follows_two_steps ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_follows_two_steps();

            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_awaiting_has_no_ticket;
            DROP TRIGGER cash_transfer_ticket_after_cash ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_ticket_after_cash();
            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_ticket_together;
            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_status_check;
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
                CHECK (status IN ('deposited', 'bank_confirmed', 'cancelled', 'bounced'));

            DROP INDEX cash_to_bank_transfers_ticket_idempotency_unique;
            ALTER TABLE cash_to_bank_transfers
                DROP COLUMN ticket_idempotency_key,
                DROP COLUMN ticket_loaded_by,
                DROP COLUMN ticket_loaded_at,
                DROP COLUMN ticket_date;

            ALTER TABLE cash_boxes DROP COLUMN deposits_in_two_steps;
        SQL);
    }
};
