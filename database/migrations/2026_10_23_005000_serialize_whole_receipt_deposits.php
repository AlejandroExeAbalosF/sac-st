<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un cobro, un traslado vigente: también cuando dos escriben a la vez.
 *
 * `transfer_follows_box_policy` contaba los traslados vigentes del cobro sin
 * bloquear nada en común, así que dos transacciones que llegaban juntas al
 * commit no veían el traslado de la otra y las dos pasaban. El Action ya
 * bloquea la recepción antes de escribir; la base ahora lo hace por su
 * cuenta, para cualquier otro escritor (revisión de la tanda 5a, R1).
 */
return new class extends Migration
{
    private const SERIALIZADA = <<<'SQL'
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

                /*
                 * Serializa a quienes depositan el mismo cobro: el segundo espera
                 * acá y, al seguir, cuenta ya con el traslado del primero
                 * confirmado. NO KEY UPDATE y no UPDATE: tiene que convivir con
                 * el KEY SHARE que la FK del ítem deja en cada escritor; con
                 * UPDATE, dos escritores se esperarían entre sí.
                 */
                PERFORM 1 FROM fund_receipts WHERE id = v_item.fund_receipt_id FOR NO KEY UPDATE;

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
        SQL;

    private const ORIGINAL = <<<'SQL'
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
        SQL;

    public function up(): void
    {
        DB::unprepared(self::SERIALIZADA);
    }

    public function down(): void
    {
        DB::unprepared(self::ORIGINAL);
    }
};
