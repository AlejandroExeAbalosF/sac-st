<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un crédito viejo de la cuenta de Multas que se identifica como pago.
 *
 * Lo que entró al banco antes de la apertura de la caja de Multas está en
 * el saldo informado al corte, sin repartir: no entra al libro como dinero
 * nuevo. Si después se sabe que fue el pago de una multa, se registra como
 * pago anterior al sistema con el movimiento que lo respalda (DER físico
 * §3.8 y §4). No genera asientos.
 *
 * - Un crédito respalda un solo pago vigente, por su importe entero: un
 *   pago no se reparte en partes (confirmado por el usuario).
 * - Tiene que ser de la cuenta con la que opera la caja de Multas, anterior
 *   a su apertura, y esa apertura tiene que existir: así la frontera entre
 *   lo histórico y lo cobrado ya no se mueve.
 * - No puede estar imputado en un libro, y una vez vinculado no se imputa.
 * - La fecha del pago es la del banco y no se revisa después.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fine_historical_movements', function (Blueprint $table): void {
            /*
             * `NO ACTION` y no `RESTRICT`: protege igual, pero responde con
             * el código de clave foránea (23503) y no con el de las guardas
             * propias (23001), y así la reversión de un extracto puede decir
             * por qué no borra el movimiento.
             */
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions');
        });

        DB::statement("CREATE UNIQUE INDEX fine_historical_movements_bank_credit_unique
            ON fine_historical_movements (bank_transaction_id)
            WHERE bank_transaction_id IS NOT NULL AND status <> 'rejected'");

        DB::unprepared(<<<'SQL'
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
                   AND o.bank_account_id = v_tx.bank_account_id;

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'El credito no es de la cuenta con la que opera la caja de Multas, o la caja todavia no se abrio.'
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

            CREATE TRIGGER fine_historical_movements_bank_credit
                BEFORE INSERT OR UPDATE ON fine_historical_movements
                FOR EACH ROW EXECUTE FUNCTION fine_historical_bank_credit();

            CREATE OR REPLACE FUNCTION bank_allocation_not_fine_history() RETURNS trigger AS $$
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                PERFORM 1 FROM bank_transactions WHERE id = NEW.bank_transaction_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM fine_historical_movements
                     WHERE bank_transaction_id = NEW.bank_transaction_id
                       AND status <> 'rejected'
                ) THEN
                    RAISE EXCEPTION 'El movimiento respalda un pago anterior de una multa: no se imputa en un libro.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_allocation_not_fine_history
                BEFORE INSERT ON bank_transaction_allocations
                FOR EACH ROW EXECUTE FUNCTION bank_allocation_not_fine_history();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_allocation_not_fine_history ON bank_transaction_allocations;
            DROP FUNCTION IF EXISTS bank_allocation_not_fine_history();
            DROP TRIGGER IF EXISTS fine_historical_movements_bank_credit ON fine_historical_movements;
            DROP FUNCTION IF EXISTS fine_historical_bank_credit();
            DROP INDEX IF EXISTS fine_historical_movements_bank_credit_unique;
        SQL);

        Schema::table('fine_historical_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_transaction_id');
        });
    }
};
