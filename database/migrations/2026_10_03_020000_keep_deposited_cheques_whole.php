<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un cheque se deposita como llegó a la Secretaría: entero y de una vez.
 *
 * El traslado de una cuota que tiene solo una parte de un cheque lleva el
 * papel completo: la parte de la cuota, la de otra cuota que lo comparte y
 * la que todavía no tiene dueño. Lo que la base garantiza es el resultado:
 * entre los traslados vigentes, los ítems de un cheque suman cero o el
 * cheque entero, y están todos en el mismo traslado.
 *
 * Diferido porque los ítems de un traslado se escriben de a uno. Solo
 * mira la inserción de ítems: son inmutables, y lo único que le pasa
 * después a un traslado es cancelarse, que saca todos sus ítems a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        $partidos = (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM (
                SELECT i.fund_receipt_id
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                  JOIN fund_receipts fr ON fr.id = i.fund_receipt_id
                 WHERE t.status <> 'cancelled'
                   AND fr.medium = 'cheque'
                 GROUP BY i.fund_receipt_id, fr.amount
                HAVING count(DISTINCT t.id) > 1 OR sum(i.amount) <> fr.amount
            ) AS partidos
        SQL);

        if ($partidos > 0) {
            throw new RuntimeException(sprintf(
                'Hay %d cheques depositados en parte o en más de un traslado. Hay que revisarlos antes de crear la guarda.',
                $partidos,
            ));
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cheque_travels_whole() RETURNS trigger AS $$
            DECLARE
                v_cheque RECORD;
                v_traslados integer;
                v_viaja NUMERIC(19,2);
            BEGIN
                SELECT medium, amount, cheque_number INTO v_cheque
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                IF v_cheque.medium IS DISTINCT FROM 'cheque' THEN
                    RETURN NULL;
                END IF;

                SELECT count(DISTINCT t.id), coalesce(sum(i.amount), 0)
                  INTO v_traslados, v_viaja
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = NEW.fund_receipt_id
                   AND t.status <> 'cancelled';

                IF v_traslados > 1 THEN
                    RAISE EXCEPTION 'El cheque % ya viaja en otro traslado vigente.',
                        coalesce(v_cheque.cheque_number, '(sin número)')
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_viaja <> 0 AND v_viaja <> v_cheque.amount THEN
                    RAISE EXCEPTION 'El cheque % es de % y el traslado lleva %: un cheque se deposita entero.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.amount, v_viaja
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cheque_travels_whole
                AFTER INSERT ON cash_to_bank_transfer_items
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_travels_whole();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cheque_travels_whole ON cash_to_bank_transfer_items;
            DROP FUNCTION IF EXISTS cheque_travels_whole();
        SQL);
    }
};
