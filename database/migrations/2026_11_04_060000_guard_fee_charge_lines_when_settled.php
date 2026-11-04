<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cambia el total de un cobro también se comprueba al terminar la transacción.
 *
 * Los renglones y sus ajustes no se editan ni se borran, pero insertar uno más
 * cambiaba el total sin revalidar nada: un cobro pagado de $ 220 pasaba a $ 340 con
 * su dinero y su recibo de $ 220. Ahora esas dos tablas disparan la misma
 * comprobación, el recibo vigente tiene que documentar el total y un cobro anulado
 * no recibe renglones. El alta inicial sigue siendo atómica: la guarda es diferida.
 */
return new class extends Migration
{
    private const string COMPROBACION = <<<'SQL'
        CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS $$
        DECLARE
            v_cobro RECORD;
            v_saldo RECORD;
            v_recibos integer;
            v_documentado numeric(19, 2);
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*), COALESCE(sum(r.amount), 0) INTO v_recibos, v_documentado
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1 OR v_documentado <> v_saldo.total) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente por su total y su total vinculado: % de %, con % recibos por %.',
                    v_saldo.funded, v_saldo.total, v_recibos, v_documentado USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;

        CREATE OR REPLACE FUNCTION fee_charge_settled_trigger() RETURNS trigger AS $$
        BEGIN
            IF TG_TABLE_NAME = 'fee_charges' THEN
                PERFORM fee_charge_settled(NEW.id);
            ELSIF TG_TABLE_NAME = 'receipts' THEN
                PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_receipts WHERE receipt_id = NEW.id;
            ELSIF TG_TABLE_NAME = 'fee_charge_item_adjustments' THEN
                PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_items WHERE id = NEW.fee_charge_item_id;
            ELSE
                PERFORM fee_charge_settled(NEW.fee_charge_id);
            END IF;

            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

    private const string COMPROBACION_ANTERIOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS $$
        DECLARE
            v_cobro RECORD;
            v_saldo RECORD;
            v_recibos integer;
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*) INTO v_recibos
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente y su total vinculado: % de %, con % recibos.',
                    v_saldo.funded, v_saldo.total, v_recibos USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;

        CREATE OR REPLACE FUNCTION fee_charge_settled_trigger() RETURNS trigger AS $$
        BEGIN
            IF TG_TABLE_NAME = 'fee_charges' THEN
                PERFORM fee_charge_settled(NEW.id);
            ELSIF TG_TABLE_NAME = 'receipts' THEN
                PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_receipts WHERE receipt_id = NEW.id;
            ELSE
                PERFORM fee_charge_settled(NEW.fee_charge_id);
            END IF;

            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        DB::unprepared(self::COMPROBACION);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER fee_charge_items_settled AFTER INSERT ON fee_charge_items
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();
            CREATE CONSTRAINT TRIGGER fee_charge_item_adjustments_settled AFTER INSERT ON fee_charge_item_adjustments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();

            -- Lo anulado queda como estaba: su total es parte de lo que se anuló.
            CREATE OR REPLACE FUNCTION fee_charge_items_not_on_voided() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM fee_charges WHERE id = NEW.fee_charge_id AND status = 'voided') THEN
                    RAISE EXCEPTION 'Un cobro anulado no recibe renglones.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_items_not_on_voided BEFORE INSERT ON fee_charge_items
                FOR EACH ROW EXECUTE FUNCTION fee_charge_items_not_on_voided();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER fee_charge_items_not_on_voided ON fee_charge_items;
            DROP FUNCTION fee_charge_items_not_on_voided();
            DROP TRIGGER fee_charge_item_adjustments_settled ON fee_charge_item_adjustments;
            DROP TRIGGER fee_charge_items_settled ON fee_charge_items;
        SQL);

        DB::unprepared(self::COMPROBACION_ANTERIOR);
    }
};
