<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El mismo efectivo no se deposita dos veces, aunque viaje repartido (Aranceles,
 * DER físico §4.2 y §4.3).
 *
 * `cash_deposited_once` solo controla los ítems con asignación a una cuota. Un
 * ítem sin asignación quedaba sin guarda salvo en las cajas de cobros enteros.
 *
 * - Un traslado solo lleva recepciones de su propia caja, en todas las cajas.
 * - Lo vigente de una recepción de efectivo, sumado, no supera su importe. Los
 *   cheques y las cajas de cobros enteros ya lo tienen con su propia guarda.
 * - En la caja de Aranceles solo viaja efectivo vigente.
 * - Una recepción de Aranceles con un traslado vigente no se revierte.
 * - Los ítems de un traslado suman su importe, en todas las cajas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_transfer_item_within_receipt() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_caja bigint;
                v_caja_recepcion bigint;
                v_vigente numeric(19, 2);
            BEGIN
                SELECT cash_box_id INTO v_caja FROM cash_to_bank_transfers WHERE id = NEW.cash_to_bank_transfer_id;
                SELECT cash_box_id INTO v_caja_recepcion FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                -- Va antes que cualquier salida: ningún ítem queda sin esta comprobación.
                IF v_caja IS DISTINCT FROM v_caja_recepcion THEN
                    RAISE EXCEPTION 'Un traslado solo lleva dinero de su propia caja.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.funding_allocation_id IS NOT NULL AND v_caja <> aranceles_cash_box_id() THEN
                    RETURN NEW;
                END IF;

                -- Dos depósitos del mismo efectivo se serializan por la recepción.
                SELECT r.amount, r.origin, r.medium, r.reversal_event_id, b.deposits_whole_receipts
                  INTO v_recepcion
                  FROM fund_receipts r
                  JOIN cash_boxes b ON b.id = r.cash_box_id
                 WHERE r.id = NEW.fund_receipt_id
                   FOR NO KEY UPDATE OF r;

                IF v_caja = aranceles_cash_box_id()
                    AND (v_recepcion.medium <> 'cash'
                        OR v_recepcion.origin NOT IN ('received', 'opening')
                        OR v_recepcion.reversal_event_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'De la caja de Aranceles solo viaja su efectivo vigente.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Los cheques y las cajas de cobros enteros ya tienen su guarda, más precisa.
                IF v_recepcion.medium = 'cheque' OR v_recepcion.deposits_whole_receipts THEN
                    RETURN NEW;
                END IF;

                SELECT coalesce(sum(i.amount), 0) INTO v_vigente
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = NEW.fund_receipt_id AND t.status <> 'cancelled';

                IF v_vigente + NEW.amount > v_recepcion.amount THEN
                    RAISE EXCEPTION 'Ese efectivo ya se depositó: la recepción tiene % y viajarían %.',
                        v_recepcion.amount, v_vigente + NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfer_item_within_receipt BEFORE INSERT ON cash_to_bank_transfer_items
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_item_within_receipt();

            -- Lo que salió de la caja no se puede dar por nunca recibido.
            CREATE OR REPLACE FUNCTION fund_receipts_keep_fee_transfers() RETURNS trigger AS $$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL
                    AND NEW.cash_box_id = aranceles_cash_box_id()
                    AND EXISTS (
                        SELECT 1 FROM cash_to_bank_transfer_items i
                          JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                         WHERE i.fund_receipt_id = NEW.id AND t.status <> 'cancelled'
                    ) THEN
                    RAISE EXCEPTION 'La recepción % viajó en un traslado vigente: primero se cancela.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_keep_fee_transfers BEFORE UPDATE OF reversal_event_id ON fund_receipts
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_keep_fee_transfers();

            CREATE OR REPLACE FUNCTION cash_transfer_items_match_amount(p_transfer bigint) RETURNS void AS $$
            DECLARE
                v_importe numeric(19, 2);
                v_items numeric(19, 2);
            BEGIN
                SELECT amount INTO v_importe FROM cash_to_bank_transfers WHERE id = p_transfer;
                SELECT coalesce(sum(amount), 0) INTO v_items
                  FROM cash_to_bank_transfer_items WHERE cash_to_bank_transfer_id = p_transfer;

                IF v_importe IS DISTINCT FROM v_items THEN
                    RAISE EXCEPTION 'El traslado % es por % y sus ítems suman %.', p_transfer, v_importe, v_items
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_transfer_items_match_amount_transfer() RETURNS trigger AS $$
            BEGIN
                PERFORM cash_transfer_items_match_amount(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_transfer_items_match_amount_item() RETURNS trigger AS $$
            BEGIN
                PERFORM cash_transfer_items_match_amount(NEW.cash_to_bank_transfer_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Diferidos: el traslado y sus ítems se insertan en varias sentencias.
            CREATE CONSTRAINT TRIGGER cash_transfer_items_match_amount_transfer
                AFTER INSERT ON cash_to_bank_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_items_match_amount_transfer();
            CREATE CONSTRAINT TRIGGER cash_transfer_items_match_amount_item
                AFTER INSERT ON cash_to_bank_transfer_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_items_match_amount_item();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER cash_transfer_items_match_amount_item ON cash_to_bank_transfer_items;
            DROP TRIGGER cash_transfer_items_match_amount_transfer ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_items_match_amount_item();
            DROP FUNCTION cash_transfer_items_match_amount_transfer();
            DROP FUNCTION cash_transfer_items_match_amount(bigint);
            DROP TRIGGER fund_receipts_keep_fee_transfers ON fund_receipts;
            DROP FUNCTION fund_receipts_keep_fee_transfers();
            DROP TRIGGER cash_transfer_item_within_receipt ON cash_to_bank_transfer_items;
            DROP FUNCTION cash_transfer_item_within_receipt();
        SQL);
    }
};
