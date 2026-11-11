<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El egreso por transferencia con comisión bancaria.
 *
 * Una transferencia MacrOnline E-set sale del extracto en **dos débitos**
 * con la misma fecha y referencia: la transferencia (`3913`) y la comisión
 * (`3914`). La comisión la absorbe el beneficiario, así que la Orden se
 * cancela con los dos: $891.841 transferidos + $121 de comisión = $891.962
 * autorizados.
 *
 * Hasta acá el egreso apuntaba a un solo débito y su exactitud vivía solo
 * en PHP. Esta migración agrega la comisión y sube a la base las garantías
 * del par:
 *
 * 1. **Reserva entre vincular y confirmar.** Vincular guarda las
 *    referencias, pero la imputación bancaria nace recién al validar. En el
 *    medio, nada impedía que otro egreso vivo reservara el mismo débito.
 * 2. **Confirmar exige el par exacto**: los dos son débitos de la cuenta de
 *    la Orden, de la misma operación, y suman el importe del egreso.
 * 3. **`bank_fee` y `payment_confirmation` solo sobre débitos enteros.**
 *    Agregar el rol en PHP no alcanza: la lista de roles y su sentido se
 *    imponen acá.
 * 4. **No se excluye lo que está en uso.** Un movimiento con imputaciones, o
 *    reservado por un egreso vivo, no pasa a «fuera del circuito»: la
 *    comisión de un pago ya validado quedaba excluida si otro operador la
 *    marcaba con la pantalla vieja.
 */
return new class extends Migration
{
    private const LIVE_STATUSES = "'pending', 'report_received', 'bank_debit_observed', "
        ."'ready_for_validation', 'confirmed'";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE disbursements
                ADD COLUMN fee_bank_transaction_id bigint
                    REFERENCES bank_transactions(id) ON DELETE RESTRICT;

            CREATE INDEX disbursements_fee_bank_transaction_id_index
                ON disbursements (fee_bank_transaction_id);

            COMMENT ON COLUMN disbursements.fee_bank_transaction_id IS
                'La comisión que el banco debitó por la transferencia. La absorbe el beneficiario: '
                'transferencia + comisión = importe del egreso.';

            -- Una comisión acompaña a una transferencia: no existe sola ni es la misma fila.
            ALTER TABLE disbursements ADD CONSTRAINT disbursements_fee_needs_debit_check
                CHECK (
                    fee_bank_transaction_id IS NULL
                    OR (bank_transaction_id IS NOT NULL AND fee_bank_transaction_id <> bank_transaction_id)
                );
        SQL);

        /*
         * ─── Un débito, un egreso vivo ───────────────────────────────
         *
         * Vincular reserva el movimiento; la imputación que lo consume
         * llega al validar. Sin estos índices, dos Órdenes podían apuntar
         * al mismo débito y la segunda recién chocaba al confirmar —o, con
         * importes distintos, nunca—.
         */
        DB::statement('CREATE UNIQUE INDEX disbursements_one_live_per_debit
            ON disbursements (bank_transaction_id)
            WHERE bank_transaction_id IS NOT NULL AND status IN ('.self::LIVE_STATUSES.')');

        DB::statement('CREATE UNIQUE INDEX disbursements_one_live_per_fee
            ON disbursements (fee_bank_transaction_id)
            WHERE fee_bank_transaction_id IS NOT NULL AND status IN ('.self::LIVE_STATUSES.')');

        /*
         * ─── Ni transferencia de uno y comisión de otro ──────────────
         *
         * Los índices miran cada columna por separado. Esto cruza las dos:
         * un movimiento no puede ser la transferencia de un egreso vivo y
         * la comisión de otro. Bloquea los movimientos para que dos
         * vinculaciones simultáneas se serialicen.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_debits_not_shared() RETURNS trigger AS $$
            BEGIN
                IF NEW.status NOT IN ('pending', 'report_received', 'bank_debit_observed',
                                      'ready_for_validation', 'confirmed') THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM bank_transactions
                 WHERE id IN (NEW.bank_transaction_id, NEW.fee_bank_transaction_id)
                   FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM disbursements d
                     WHERE d.id <> NEW.id
                       AND d.status IN ('pending', 'report_received', 'bank_debit_observed',
                                        'ready_for_validation', 'confirmed')
                       AND (
                            (NEW.fee_bank_transaction_id IS NOT NULL AND d.bank_transaction_id = NEW.fee_bank_transaction_id)
                         OR (NEW.bank_transaction_id IS NOT NULL AND d.fee_bank_transaction_id = NEW.bank_transaction_id)
                       )
                ) THEN
                    RAISE EXCEPTION 'Ese movimiento ya está reservado por otro egreso.'
                        USING ERRCODE = 'unique_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER disbursements_debits_not_shared
                BEFORE INSERT OR UPDATE OF bank_transaction_id, fee_bank_transaction_id, status ON disbursements
                FOR EACH ROW EXECUTE FUNCTION disbursements_debits_not_shared();
        SQL);

        /*
         * ─── Confirmar es afirmar que el par cierra ──────────────────
         *
         * Antes la base exigía que hubiera un débito; cuánto era y de dónde
         * salía lo controlaba solo el Action. Ahora lo exige la base, con y
         * sin comisión. Corre al pasar a `confirmed` —o al insertar ya
         * confirmado—: después, el par queda congelado por el append-only.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_transfer_debits_match() RETURNS trigger AS $$
            DECLARE
                v_cuenta bigint;
                v_principal RECORD;
                v_comision RECORD;
                v_total numeric(19, 2);
            BEGIN
                IF NEW.status <> 'confirmed' OR NEW.method <> 'bank_transfer' THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status = 'confirmed' THEN
                    RETURN NEW;
                END IF;

                SELECT organism_bank_account_id INTO v_cuenta
                  FROM payment_orders WHERE id = NEW.payment_order_id;

                SELECT bank_account_id, direction, amount, transaction_date, operation_id, causal_code
                  INTO v_principal
                  FROM bank_transactions WHERE id = NEW.bank_transaction_id;

                IF v_principal.direction IS DISTINCT FROM 'debit'
                    OR v_principal.bank_account_id IS DISTINCT FROM v_cuenta THEN
                    RAISE EXCEPTION 'La transferencia de un egreso es un débito de la cuenta de su Orden.'
                        USING ERRCODE = 'check_violation';
                END IF;

                v_total := abs(v_principal.amount);

                IF NEW.fee_bank_transaction_id IS NOT NULL THEN
                    SELECT bank_account_id, direction, amount, transaction_date, operation_id, causal_code
                      INTO v_comision
                      FROM bank_transactions WHERE id = NEW.fee_bank_transaction_id;

                    IF v_comision.direction IS DISTINCT FROM 'debit'
                        OR v_comision.bank_account_id IS DISTINCT FROM v_principal.bank_account_id
                        OR v_comision.transaction_date IS DISTINCT FROM v_principal.transaction_date
                        OR btrim(coalesce(v_principal.operation_id, '')) = ''
                        OR v_comision.operation_id IS DISTINCT FROM v_principal.operation_id THEN
                        RAISE EXCEPTION 'La comisión de un egreso es un débito de la misma cuenta, fecha y operación que su transferencia.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    -- El par de causales de Macro (`TransferFees::PAIRS`): solo una transferencia
                    -- E-set lleva comisión, y solo la suya.
                    IF btrim(coalesce(v_principal.causal_code, '')) <> '3913'
                        OR btrim(coalesce(v_comision.causal_code, '')) <> '3914' THEN
                        RAISE EXCEPTION 'Solo una transferencia E-set (3913) lleva comisión, y es la del causal 3914.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    v_total := v_total + abs(v_comision.amount);
                END IF;

                IF v_total <> NEW.amount THEN
                    RAISE EXCEPTION 'La transferencia y su comisión suman %, y el egreso es por %.', v_total, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER disbursements_transfer_debits_match
                BEFORE INSERT OR UPDATE ON disbursements
                FOR EACH ROW EXECUTE FUNCTION disbursements_transfer_debits_match();
        SQL);

        /* El append-only, con la comisión congelada igual que el débito. */
        DB::unprepared(self::APPEND_ONLY_WITH_FEE);

        /*
         * ─── El rol nuevo y el sentido de los roles de pago ──────────
         */
        DB::unprepared(<<<'SQL'
            ALTER TABLE bank_transaction_allocations DROP CONSTRAINT bank_transaction_allocations_role_check;
            ALTER TABLE bank_transaction_allocations ADD CONSTRAINT bank_transaction_allocations_role_check
                CHECK (allocation_role IN (
                    'funds_received', 'payment_confirmation', 'cash_deposit_confirmation',
                    'cheque_rejection_credit', 'cheque_rejection_debit', 'bank_fee'
                ));

            -- La transferencia de un pago y su comisión: débitos enteros del extracto, sin otro uso.
            CREATE OR REPLACE FUNCTION payment_debit_movement_whole() RETURNS trigger AS $$
            DECLARE
                v_movimiento RECORD;
                v_otros numeric(19, 2);
            BEGIN
                SELECT abs(amount) AS importe, direction INTO v_movimiento
                  FROM bank_transactions WHERE id = NEW.bank_transaction_id;
                SELECT coalesce(sum(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_otros
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id AND id <> NEW.id;

                IF v_movimiento.direction <> 'debit' THEN
                    RAISE EXCEPTION 'Un pago y su comisión salen de la cuenta: ese movimiento es un crédito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.amount <> v_movimiento.importe OR v_otros <> 0 THEN
                    RAISE EXCEPTION 'La transferencia de un pago y su comisión se imputan enteras y sin otro uso.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER payment_debit_movement_whole
                AFTER INSERT ON bank_transaction_allocations
                FOR EACH ROW
                WHEN (NEW.allocation_role IN ('payment_confirmation', 'bank_fee') AND NEW.reversal_of_id IS NULL)
                EXECUTE FUNCTION payment_debit_movement_whole();
        SQL);

        /*
         * ─── Fuera del circuito, solo lo que nadie usa ───────────────
         *
         * El UPDATE ya tiene tomada la fila del movimiento cuando esto
         * corre, y las imputaciones y vinculaciones toman ese mismo
         * bloqueo: una exclusión y una validación simultáneas se ordenan,
         * y la segunda ve lo que confirmó la primera.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_transactions_ignore_only_unused() RETURNS trigger AS $$
            DECLARE
                v_imputado numeric(19, 2);
            BEGIN
                SELECT coalesce(sum(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0)
                  INTO v_imputado
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.id;

                IF v_imputado <> 0 THEN
                    RAISE EXCEPTION 'Un movimiento con imputaciones no se deja fuera del circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM disbursements d
                     WHERE (d.bank_transaction_id = NEW.id OR d.fee_bank_transaction_id = NEW.id)
                       AND d.status IN ('pending', 'report_received', 'bank_debit_observed',
                                        'ready_for_validation', 'confirmed')
                ) THEN
                    RAISE EXCEPTION 'Un movimiento vinculado a un pago en curso no se deja fuera del circuito.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_transactions_ignore_only_unused
                BEFORE UPDATE OF reconciliation_status ON bank_transactions
                FOR EACH ROW
                WHEN (NEW.reconciliation_status = 'ignored' AND OLD.reconciliation_status IS DISTINCT FROM 'ignored')
                EXECUTE FUNCTION bank_transactions_ignore_only_unused();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_transactions_ignore_only_unused ON bank_transactions;
            DROP FUNCTION IF EXISTS bank_transactions_ignore_only_unused();
            DROP TRIGGER IF EXISTS payment_debit_movement_whole ON bank_transaction_allocations;
            DROP FUNCTION IF EXISTS payment_debit_movement_whole();

            ALTER TABLE bank_transaction_allocations DROP CONSTRAINT bank_transaction_allocations_role_check;
            ALTER TABLE bank_transaction_allocations ADD CONSTRAINT bank_transaction_allocations_role_check
                CHECK (allocation_role IN (
                    'funds_received', 'payment_confirmation', 'cash_deposit_confirmation',
                    'cheque_rejection_credit', 'cheque_rejection_debit'
                ));

            DROP TRIGGER IF EXISTS disbursements_transfer_debits_match ON disbursements;
            DROP FUNCTION IF EXISTS disbursements_transfer_debits_match();
            DROP TRIGGER IF EXISTS disbursements_debits_not_shared ON disbursements;
            DROP FUNCTION IF EXISTS disbursements_debits_not_shared();
            DROP INDEX IF EXISTS disbursements_one_live_per_fee;
            DROP INDEX IF EXISTS disbursements_one_live_per_debit;
        SQL);

        DB::unprepared(self::APPEND_ONLY_ORIGINAL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE disbursements DROP CONSTRAINT IF EXISTS disbursements_fee_needs_debit_check;
            ALTER TABLE disbursements DROP COLUMN fee_bank_transaction_id;
        SQL);
    }

    /**
     * `disbursements_append_only()` con la comisión congelada igual que el
     * débito. Se reescribe entera porque una función de plpgsql no se
     * parchea por partes.
     */
    private const APPEND_ONLY_WITH_FEE = <<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                THEN
                    RAISE EXCEPTION 'El débito de un egreso confirmado no se cambia: hay un asiento que lo referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.fee_bank_transaction_id IS DISTINCT FROM OLD.fee_bank_transaction_id
                THEN
                    RAISE EXCEPTION 'La comisión de un egreso confirmado no se cambia: hay un asiento que la referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** La de la creación de la tabla, para el `down()`. */
    private const APPEND_ONLY_ORIGINAL = <<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                THEN
                    RAISE EXCEPTION 'El débito de un egreso confirmado no se cambia: hay un asiento que lo referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;
};
