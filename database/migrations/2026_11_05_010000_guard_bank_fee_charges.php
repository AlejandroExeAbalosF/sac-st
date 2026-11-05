<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo que la base exige a los cobros de Aranceles por el banco (Etapa 3, DER físico §6).
 *
 * - Un cobro pendiente no tiene fecha de pago ni dinero aplicado: lo vinculado
 *   espera sin identificar hasta completar el total.
 * - Un cobro pagado tiene aplicado todo lo que lo respalda.
 * - Un vínculo nace sin aplicar: la aplicación entra por la actualización, que
 *   comprueba que el asiento sea el correcto (`fee_charge_fundings_guard`).
 * - Una recepción por banco de esta caja está vinculada por todo su importe o
 *   revertida: en una cuenta compartida no queda dinero reconocido como de Aranceles
 *   y sin dueño. Lo que sobra de un crédito queda libre en el extracto.
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
            v_aplicados integer;
            v_vigentes integer;
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*), COALESCE(sum(r.amount), 0) INTO v_recibos, v_documentado
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';
            SELECT count(*), count(application_event_id) INTO v_vigentes, v_aplicados
              FROM fee_charge_fundings WHERE fee_charge_id = p_charge AND released_at IS NULL;

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0 OR v_aplicados > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo, dinero aplicado ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1 OR v_documentado <> v_saldo.total OR v_aplicados <> v_vigentes) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente por su total y su total vinculado y aplicado: % de %, con % recibos por %.',
                    v_saldo.funded, v_saldo.total, v_recibos, v_documentado USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

    private const string COMPROBACION_ANTERIOR = <<<'SQL'
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
        SQL;

    public function up(): void
    {
        DB::unprepared(self::COMPROBACION);

        DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_pending_has_no_payment_date
                CHECK (status <> 'pending' OR paid_on IS NULL);

            CREATE OR REPLACE FUNCTION fee_charge_funding_born_unapplied() RETURNS trigger AS $$
            BEGIN
                IF NEW.application_event_id IS NOT NULL OR NEW.application_reversal_event_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Un vínculo nace sin aplicar: la aplicación se registra después, con su asiento.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_funding_born_unapplied BEFORE INSERT ON fee_charge_fundings
                FOR EACH ROW EXECUTE FUNCTION fee_charge_funding_born_unapplied();

            CREATE OR REPLACE FUNCTION fee_bank_receipt_has_a_charge(p_receipt bigint) RETURNS void AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts r
                     WHERE r.id = p_receipt
                       AND r.cash_box_id = aranceles_cash_box_id()
                       AND r.medium = 'bank'
                       AND r.reversal_event_id IS NULL
                       AND r.amount <> (
                           SELECT coalesce(sum(f.amount), 0) FROM fee_charge_fundings f
                            WHERE f.fund_receipt_id = r.id AND f.released_at IS NULL
                       )
                ) THEN
                    RAISE EXCEPTION 'La recepción % por banco de Aranceles no respalda ningún cobro por todo su importe: se vincula o se revierte.', p_receipt
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fee_bank_receipt_has_a_charge_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'fund_receipts' THEN
                    PERFORM fee_bank_receipt_has_a_charge(NEW.id);
                ELSE
                    PERFORM fee_bank_receipt_has_a_charge(NEW.fund_receipt_id);
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Diferidas: la recepción, su vínculo y su reversión van en varias sentencias.
            CREATE CONSTRAINT TRIGGER fund_receipts_fee_bank_has_a_charge AFTER INSERT OR UPDATE ON fund_receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_bank_receipt_has_a_charge_trigger();
            CREATE CONSTRAINT TRIGGER fee_charge_fundings_keep_bank_receipt AFTER INSERT OR UPDATE ON fee_charge_fundings
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_bank_receipt_has_a_charge_trigger();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER fee_charge_fundings_keep_bank_receipt ON fee_charge_fundings;
            DROP TRIGGER fund_receipts_fee_bank_has_a_charge ON fund_receipts;
            DROP FUNCTION fee_bank_receipt_has_a_charge_trigger();
            DROP FUNCTION fee_bank_receipt_has_a_charge(bigint);
            DROP TRIGGER fee_charge_funding_born_unapplied ON fee_charge_fundings;
            DROP FUNCTION fee_charge_funding_born_unapplied();
            ALTER TABLE fee_charges DROP CONSTRAINT fee_charges_pending_has_no_payment_date;
        SQL);

        DB::unprepared(self::COMPROBACION_ANTERIOR);
    }
};
