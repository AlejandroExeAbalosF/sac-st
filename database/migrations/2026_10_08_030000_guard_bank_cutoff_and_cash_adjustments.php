<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dos guardas que faltaban, encontradas en la auditoría de la caja.
 *
 * - `bank_allocation_after_opening`: un movimiento del extracto anterior a
 *   la apertura de su moneda no se imputa como dinero nuevo. Desde que lo
 *   del banco se asienta el día en que se registra, la guarda de «nada
 *   anterior a la apertura» dejó de alcanzarlo: un crédito ya incluido en
 *   el saldo declarado entraba otra vez como ingreso. La fecha que se mira
 *   es la del banco, no la del asiento.
 * - `cash_adjustment_is_current`: la diferencia se imputa solo desde el
 *   último turno del día, y solo si el libro sigue con el saldo que se
 *   contó. Dos arqueos revisados del mismo cajón permitían imputar dos
 *   veces el mismo faltante.
 *
 * `AdjustCashDifference` y `BankMovementAfterOpening` dan el mensaje
 * legible; esto es lo que no se puede saltear.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_allocation_after_opening() RETURNS trigger AS $$
            DECLARE caja BIGINT; moneda CHAR(3); fecha DATE; apertura DATE;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;
                SELECT cash_box_id INTO caja FROM financial_events WHERE id = NEW.financial_event_id;
                IF caja IS NULL THEN RETURN NEW; END IF;
                PERFORM 1 FROM cash_boxes WHERE id = caja FOR UPDATE;
                SELECT b.currency, t.transaction_date INTO moneda, fecha
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id;
                SELECT opened_on INTO apertura FROM cash_book_openings
                 WHERE cash_box_id = caja AND currency = moneda;
                IF fecha < apertura THEN
                    RAISE EXCEPTION 'El movimiento bancario del % es anterior a la apertura en % del %: corresponde al circuito historico.', fecha, moneda, apertura
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER bank_allocation_after_opening BEFORE INSERT ON bank_transaction_allocations
                FOR EACH ROW EXECUTE FUNCTION bank_allocation_after_opening();

            CREATE OR REPLACE FUNCTION cash_adjustment_is_current() RETURNS trigger AS $$
            DECLARE saldo NUMERIC(19,2); ajuste NUMERIC(19,2); evento RECORD;
            BEGIN
                IF NEW.adjustment_event_id IS NULL THEN RETURN NEW; END IF;
                IF TG_OP = 'UPDATE' AND OLD.adjustment_event_id IS NOT DISTINCT FROM NEW.adjustment_event_id THEN RETURN NEW; END IF;
                PERFORM 1 FROM cash_boxes WHERE id = NEW.cash_box_id FOR UPDATE;
                IF TG_OP = 'INSERT' THEN
                    RAISE EXCEPTION 'La imputacion requiere un arqueo revisado existente.' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status <> 'reviewed' OR NEW.status <> 'adjusted' OR OLD.difference_amount = 0 THEN
                    RAISE EXCEPTION 'Solo se imputa un arqueo revisado con diferencia.' USING ERRCODE = 'check_violation';
                END IF;
                IF (to_jsonb(NEW) - 'status' - 'adjustment_event_id' - 'updated_at' - 'difference_amount')
                    IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'adjustment_event_id' - 'updated_at' - 'difference_amount') THEN
                    RAISE EXCEPTION 'Imputar no permite modificar el conteo revisado.' USING ERRCODE = 'check_violation';
                END IF;
                IF EXISTS (SELECT 1 FROM cash_counts WHERE cash_box_id = NEW.cash_box_id
                    AND currency = NEW.currency AND counted_on = NEW.counted_on AND sequence > NEW.sequence) THEN
                    RAISE EXCEPTION 'Hay un turno posterior: solo se imputa el ultimo arqueo del dia.' USING ERRCODE = 'check_violation';
                END IF;
                SELECT * INTO evento FROM financial_events WHERE id = NEW.adjustment_event_id;
                IF evento.event_type <> 'cash_adjustment' OR evento.status <> 'posted'
                    OR evento.cash_box_id IS DISTINCT FROM NEW.cash_box_id OR evento.event_date <> NEW.counted_on THEN
                    RAISE EXCEPTION 'El asiento no corresponde a la diferencia de este arqueo.' USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(SUM(l.debit - l.credit), 0) INTO saldo
                  FROM journal_lines l JOIN financial_events e ON e.id = l.financial_event_id
                 WHERE l.cash_box_id = NEW.cash_box_id AND l.currency = NEW.currency
                   AND l.account_code = 'CASH_ON_HAND' AND e.status IN ('posted', 'reversed')
                   AND e.event_date <= NEW.counted_on AND e.id <> NEW.adjustment_event_id;
                IF saldo <> OLD.expected_amount THEN
                    RAISE EXCEPTION 'El saldo del libro cambio desde el arqueo: hay que volver a contar.' USING ERRCODE = 'check_violation';
                END IF;
                SELECT COALESCE(SUM(debit - credit), 0) INTO ajuste FROM journal_lines
                 WHERE financial_event_id = NEW.adjustment_event_id AND cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency AND account_code = 'CASH_ON_HAND';
                IF ajuste <> OLD.difference_amount THEN
                    RAISE EXCEPTION 'El importe del ajuste no coincide con la diferencia del arqueo.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER cash_adjustment_is_current BEFORE INSERT OR UPDATE ON cash_counts
                FOR EACH ROW EXECUTE FUNCTION cash_adjustment_is_current();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cash_adjustment_is_current ON cash_counts;
            DROP FUNCTION IF EXISTS cash_adjustment_is_current();
            DROP TRIGGER IF EXISTS bank_allocation_after_opening ON bank_transaction_allocations;
            DROP FUNCTION IF EXISTS bank_allocation_after_opening();
        SQL);
    }
};
