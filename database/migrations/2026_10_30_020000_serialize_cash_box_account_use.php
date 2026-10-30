<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cambiar la vigencia de una cuenta y usarla se esperan entre sí.
 *
 * Una baja revisa lo registrado con la cuenta, y un depósito o una
 * identificación revisan la vigencia; ninguno ve lo que el otro todavía no
 * confirmó. Con dos conexiones a la vez, una baja al 09/09 y un crédito del
 * 10/09 terminaban los dos bien y el crédito quedaba fuera de la vigencia.
 *
 * Ahora comparten un candado transaccional por caja y cuenta
 * (`lock_cash_box_account`): lo toman las guardas de la base antes de leer
 * la vigencia —depósito, identificación y antecedente de Multas—, el trigger
 * de períodos antes de cualquier cambio de vigencia, y las Actions de baja y
 * de inicio antes de revisar los usos. El segundo espera al primero y, al
 * seguir, lee lo confirmado: cada sentencia de una función `plpgsql` toma su
 * propia instantánea en `READ COMMITTED`.
 */
return new class extends Migration
{
    /** Como la dejó `split_cash_box_bank_account_periods`. */
    private const PERIODOS_ANTES = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_box_bank_account_periods() RETURNS trigger AS $$
            DECLARE v_otro RECORD;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('sacst.cash_box_bank_accounts'), hashtext(NEW.cash_box_id || ':' || NEW.bank_account_id));

                SELECT valid_from, valid_until INTO v_otro
                  FROM cash_box_bank_accounts
                 WHERE cash_box_id = NEW.cash_box_id AND bank_account_id = NEW.bank_account_id AND id <> NEW.id
                   AND daterange(valid_from, valid_until, '[]') && daterange(NEW.valid_from, NEW.valid_until, '[]')
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION 'Los períodos de una cuenta en la caja no se superponen: ya vale desde % hasta %.',
                        COALESCE(to_char(v_otro.valid_from, 'DD/MM/YYYY'), 'su inicio histórico'),
                        COALESCE(to_char(v_otro.valid_until, 'DD/MM/YYYY'), 'hoy')
                        USING ERRCODE = 'exclusion_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Con el candado común. */
    private const PERIODOS = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_box_bank_account_periods() RETURNS trigger AS $$
            DECLARE v_otro RECORD;
            BEGIN
                PERFORM lock_cash_box_account(NEW.cash_box_id, NEW.bank_account_id);

                SELECT valid_from, valid_until INTO v_otro
                  FROM cash_box_bank_accounts
                 WHERE cash_box_id = NEW.cash_box_id AND bank_account_id = NEW.bank_account_id AND id <> NEW.id
                   AND daterange(valid_from, valid_until, '[]') && daterange(NEW.valid_from, NEW.valid_until, '[]')
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION 'Los períodos de una cuenta en la caja no se superponen: ya vale desde % hasta %.',
                        COALESCE(to_char(v_otro.valid_from, 'DD/MM/YYYY'), 'su inicio histórico'),
                        COALESCE(to_char(v_otro.valid_until, 'DD/MM/YYYY'), 'hoy')
                        USING ERRCODE = 'exclusion_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Como la dejó `add_cash_box_bank_accounts`. */
    private const DEPOSITO_ANTES = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_transfer_follows_box_accounts() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE cash_box_id = NEW.cash_box_id)
                    AND NOT cash_box_account_covers(NEW.cash_box_id, NEW.bank_account_id, NEW.deposit_date) THEN
                    RAISE EXCEPTION 'La cuenta del depósito no está habilitada para esta caja el %.', NEW.deposit_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Toma el candado antes de leer la vigencia. */
    private const DEPOSITO = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_transfer_follows_box_accounts() RETURNS trigger AS $$
            BEGIN
                -- Espera a quien esté cambiando la vigencia de esta cuenta, y después la lee confirmada.
                PERFORM lock_cash_box_account(NEW.cash_box_id, NEW.bank_account_id);

                IF EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE cash_box_id = NEW.cash_box_id)
                    AND NOT cash_box_account_covers(NEW.cash_box_id, NEW.bank_account_id, NEW.deposit_date) THEN
                    RAISE EXCEPTION 'La cuenta del depósito no está habilitada para esta caja el %.', NEW.deposit_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Como la dejó `add_cash_box_bank_accounts`. */
    private const IDENTIFICACION_ANTES = <<<'SQL'
            CREATE OR REPLACE FUNCTION bank_allocation_follows_box_accounts() RETURNS trigger AS $$
            DECLARE v_evento RECORD; v_tx RECORD;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                SELECT cash_box_id, event_type INTO v_evento FROM financial_events WHERE id = NEW.financial_event_id;

                IF v_evento.event_type IS DISTINCT FROM 'funds_received' OR v_evento.cash_box_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE cash_box_id = v_evento.cash_box_id) THEN
                    RETURN NEW;
                END IF;

                SELECT bank_account_id, transaction_date INTO v_tx FROM bank_transactions WHERE id = NEW.bank_transaction_id;

                IF NOT cash_box_account_covers(v_evento.cash_box_id, v_tx.bank_account_id, v_tx.transaction_date) THEN
                    RAISE EXCEPTION 'El crédito del % no es de una cuenta habilitada para esta caja en esa fecha.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Toma el candado antes de leer la vigencia. */
    private const IDENTIFICACION = <<<'SQL'
            CREATE OR REPLACE FUNCTION bank_allocation_follows_box_accounts() RETURNS trigger AS $$
            DECLARE v_evento RECORD; v_tx RECORD;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                SELECT cash_box_id, event_type INTO v_evento FROM financial_events WHERE id = NEW.financial_event_id;

                IF v_evento.event_type IS DISTINCT FROM 'funds_received' OR v_evento.cash_box_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE cash_box_id = v_evento.cash_box_id) THEN
                    RETURN NEW;
                END IF;

                SELECT bank_account_id, transaction_date INTO v_tx FROM bank_transactions WHERE id = NEW.bank_transaction_id;

                PERFORM lock_cash_box_account(v_evento.cash_box_id, v_tx.bank_account_id);

                IF NOT cash_box_account_covers(v_evento.cash_box_id, v_tx.bank_account_id, v_tx.transaction_date) THEN
                    RAISE EXCEPTION 'El crédito del % no es de una cuenta habilitada para esta caja en esa fecha.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Como la dejó `add_cash_box_bank_accounts`. */
    private const ANTECEDENTE_ANTES = <<<'SQL'
            CREATE OR REPLACE FUNCTION fine_historical_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_tx RECORD;
                v_apertura DATE;
                v_imputado NUMERIC(19,2);
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.bank_transaction_id IS NOT NULL
                       AND (NEW.occurred_on IS DISTINCT FROM OLD.occurred_on
                            OR NEW.occurred_precision IS DISTINCT FROM OLD.occurred_precision) THEN
                        RAISE EXCEPTION 'La fecha de un pago respaldado por un credito del banco es la del banco: no se revisa.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.bank_transaction_id IS NULL THEN RETURN NEW; END IF;

                SELECT t.*, b.currency INTO v_tx
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id
                   FOR UPDATE OF t;

                IF v_tx.direction <> 'credit' OR NEW.kind <> 'payment' THEN
                    RAISE EXCEPTION 'Un movimiento del banco respalda un pago, y tiene que ser un credito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.amount <> v_tx.amount THEN
                    RAISE EXCEPTION 'El pago es por el credito entero (%), no por una parte.', v_tx.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.occurred_precision <> 'day' OR NEW.occurred_on <> v_tx.transaction_date THEN
                    RAISE EXCEPTION 'La fecha del pago es la del credito: %.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT o.opened_on INTO v_apertura
                  FROM cash_book_openings o
                  JOIN cash_boxes c ON c.id = o.cash_box_id
                 WHERE c.code = 'multas'
                   AND o.currency = v_tx.currency
                   AND cash_box_account_covers(c.id, v_tx.bank_account_id, v_tx.transaction_date);

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'El credito no es de una cuenta de la caja de Multas vigente en su fecha, o la caja todavia no se abrio.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_tx.transaction_date >= v_apertura THEN
                    RAISE EXCEPTION 'El credito del % es posterior a la apertura de la caja de Multas (%): se cobra por el circuito, no como pago anterior.',
                        v_tx.transaction_date, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_imputado
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id;

                IF v_imputado > 0 THEN
                    RAISE EXCEPTION 'El credito ya esta imputado en un libro: no respalda ademas un pago anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** Toma el candado antes de leer la vigencia. */
    private const ANTECEDENTE = <<<'SQL'
            CREATE OR REPLACE FUNCTION fine_historical_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_tx RECORD;
                v_apertura DATE;
                v_imputado NUMERIC(19,2);
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.bank_transaction_id IS NOT NULL
                       AND (NEW.occurred_on IS DISTINCT FROM OLD.occurred_on
                            OR NEW.occurred_precision IS DISTINCT FROM OLD.occurred_precision) THEN
                        RAISE EXCEPTION 'La fecha de un pago respaldado por un credito del banco es la del banco: no se revisa.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.bank_transaction_id IS NULL THEN RETURN NEW; END IF;

                SELECT t.*, b.currency INTO v_tx
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id
                   FOR UPDATE OF t;

                IF v_tx.direction <> 'credit' OR NEW.kind <> 'payment' THEN
                    RAISE EXCEPTION 'Un movimiento del banco respalda un pago, y tiene que ser un credito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.amount <> v_tx.amount THEN
                    RAISE EXCEPTION 'El pago es por el credito entero (%), no por una parte.', v_tx.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.occurred_precision <> 'day' OR NEW.occurred_on <> v_tx.transaction_date THEN
                    RAISE EXCEPTION 'La fecha del pago es la del credito: %.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                PERFORM lock_cash_box_account((SELECT id FROM cash_boxes WHERE code = 'multas'), v_tx.bank_account_id);

                SELECT o.opened_on INTO v_apertura
                  FROM cash_book_openings o
                  JOIN cash_boxes c ON c.id = o.cash_box_id
                 WHERE c.code = 'multas'
                   AND o.currency = v_tx.currency
                   AND cash_box_account_covers(c.id, v_tx.bank_account_id, v_tx.transaction_date);

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'El credito no es de una cuenta de la caja de Multas vigente en su fecha, o la caja todavia no se abrio.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_tx.transaction_date >= v_apertura THEN
                    RAISE EXCEPTION 'El credito del % es posterior a la apertura de la caja de Multas (%): se cobra por el circuito, no como pago anterior.',
                        v_tx.transaction_date, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_imputado
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id;

                IF v_imputado > 0 THEN
                    RAISE EXCEPTION 'El credito ya esta imputado en un libro: no respalda ademas un pago anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION lock_cash_box_account(p_box bigint, p_account bigint) RETURNS void AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('sacst.cash_box_bank_accounts'), hashtext(p_box || ':' || p_account));
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach ([self::PERIODOS, self::DEPOSITO, self::IDENTIFICACION, self::ANTECEDENTE] as $funcion) {
            DB::unprepared($funcion);
        }
    }

    public function down(): void
    {
        foreach ([self::PERIODOS_ANTES, self::DEPOSITO_ANTES, self::IDENTIFICACION_ANTES, self::ANTECEDENTE_ANTES] as $funcion) {
            DB::unprepared($funcion);
        }

        DB::unprepared('DROP FUNCTION lock_cash_box_account(bigint, bigint);');
    }
};
