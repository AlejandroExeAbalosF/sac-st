<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saca el corte de la cuenta compartida.
 *
 * El corte declaraba, una vez y para la cuenta entera, el saldo del banco a
 * una fecha (`2026_10_21_000000_create_bank_account_openings`). El área no lo
 * necesita: el saldo de la cuenta de Multas y Aranceles no se reparte, cada
 * caja reconoce solo lo que identifica, y la frontera entre lo viejo y lo
 * nuevo ya la pone la apertura de cada caja. El saldo del banco lo trae el
 * extracto.
 *
 * Lo que se va:
 *
 * - `bank_account_openings`, con su trigger append-only y su guarda;
 * - `cash_book_openings_after_bank_cutoff` y
 *   `journal_lines_opening_without_shared_bank`;
 * - el tramo de `bank_allocation_after_opening` que miraba el corte: la regla
 *   de la apertura de cada caja queda como estaba;
 * - el sujeto `bank_account_opening` de los adjuntos.
 *
 * Lo que se queda: `cash_book_openings.bank_account_id`, la cuenta con la que
 * opera cada libro, que Multas usa con o sin corte.
 *
 * Nunca salió en una versión. Si aun así encuentra un corte cargado, no
 * sigue: borrarlo en silencio perdería el saldo que alguien declaró.
 */
return new class extends Migration
{
    private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'fine_payment', 'cheque_rejection'";

    public function up(): void
    {
        if (DB::table('bank_account_openings')->exists()
            || DB::table('attachments')->where('subject_type', 'bank_account_opening')->exists()) {
            throw new RuntimeException(
                'Hay cortes de cuenta cargados: decidí qué hacer con ellos antes de quitar la tabla.',
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER journal_lines_opening_without_shared_bank ON journal_lines;
            DROP FUNCTION journal_lines_opening_without_shared_bank();
            DROP TRIGGER cash_book_openings_after_bank_cutoff ON cash_book_openings;
            DROP FUNCTION cash_book_openings_after_bank_cutoff();
            DROP TRIGGER bank_account_openings_guard ON bank_account_openings;
            DROP FUNCTION bank_account_openings_guard();

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
        SQL);

        Schema::drop('bank_account_openings');

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');
    }

    /**
     * Vuelve a poner el corte tal como lo dejó
     * `2026_10_21_000000_create_bank_account_openings`.
     */
    public function down(): void
    {
        Schema::create('bank_account_openings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->unique()->constrained('bank_accounts')->restrictOnDelete();
            $table->date('cutoff_on');
            $table->decimal('statement_balance', 19, 2);
            $table->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $table->string('notes', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'bank_account_opening'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER bank_account_openings_append_only
                BEFORE UPDATE OR DELETE ON bank_account_openings
                FOR EACH ROW EXECUTE FUNCTION forbid_mutation();

            CREATE OR REPLACE FUNCTION bank_account_openings_guard() RETURNS trigger AS $$
            DECLARE v_fecha DATE;
            BEGIN
                PERFORM 1 FROM bank_accounts WHERE id = NEW.bank_account_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1
                      FROM journal_lines jl
                      JOIN financial_events fe ON fe.id = jl.financial_event_id
                     WHERE fe.event_type = 'opening_balance'
                       AND fe.status IN ('posted', 'reversed')
                       AND jl.account_code = 'BANK_ACCOUNT'
                       AND jl.bank_account_id = NEW.bank_account_id
                ) THEN
                    RAISE EXCEPTION 'La apertura de una caja ya declaro el saldo de esta cuenta: no se le declara un corte compartido.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT MIN(opened_on) INTO v_fecha
                  FROM cash_book_openings
                 WHERE bank_account_id = NEW.bank_account_id;

                IF v_fecha IS NOT NULL AND v_fecha <= NEW.cutoff_on THEN
                    RAISE EXCEPTION 'Un libro opera con esta cuenta desde el %: el corte tiene que ser anterior.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT MIN(t.transaction_date) INTO v_fecha
                  FROM bank_transactions t
                 WHERE t.bank_account_id = NEW.bank_account_id
                   AND t.transaction_date <= NEW.cutoff_on
                   AND (
                        SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                          FROM bank_transaction_allocations a
                         WHERE a.bank_transaction_id = t.id
                       ) > 0;

                IF v_fecha IS NOT NULL THEN
                    RAISE EXCEPTION 'Hay un movimiento del % ya imputado en un libro: el corte no puede incluirlo.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_account_openings_guard BEFORE INSERT ON bank_account_openings
                FOR EACH ROW EXECUTE FUNCTION bank_account_openings_guard();

            CREATE OR REPLACE FUNCTION cash_book_openings_after_bank_cutoff() RETURNS trigger AS $$
            DECLARE v_corte DATE;
            BEGIN
                IF NEW.bank_account_id IS NULL THEN RETURN NEW; END IF;

                PERFORM 1 FROM bank_accounts WHERE id = NEW.bank_account_id FOR UPDATE;

                SELECT cutoff_on INTO v_corte FROM bank_account_openings WHERE bank_account_id = NEW.bank_account_id;

                IF v_corte IS NOT NULL AND v_corte >= NEW.opened_on THEN
                    RAISE EXCEPTION 'La cuenta tiene corte al %: el libro se abre despues de esa fecha.', v_corte
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_book_openings_after_bank_cutoff BEFORE INSERT ON cash_book_openings
                FOR EACH ROW EXECUTE FUNCTION cash_book_openings_after_bank_cutoff();

            CREATE OR REPLACE FUNCTION journal_lines_opening_without_shared_bank() RETURNS trigger AS $$
            BEGIN
                IF NEW.account_code <> 'BANK_ACCOUNT' OR NEW.bank_account_id IS NULL THEN RETURN NEW; END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.financial_event_id AND event_type = 'opening_balance'
                ) THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM bank_accounts WHERE id = NEW.bank_account_id FOR UPDATE;

                IF EXISTS (SELECT 1 FROM bank_account_openings WHERE bank_account_id = NEW.bank_account_id) THEN
                    RAISE EXCEPTION 'La cuenta tiene un corte compartido: su saldo no se declara en la apertura de una caja.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_opening_without_shared_bank BEFORE INSERT ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_lines_opening_without_shared_bank();

            CREATE OR REPLACE FUNCTION bank_allocation_after_opening() RETURNS trigger AS $$
            DECLARE caja BIGINT; cuenta BIGINT; moneda CHAR(3); fecha DATE; apertura DATE; corte DATE;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                SELECT cash_box_id INTO caja FROM financial_events WHERE id = NEW.financial_event_id;
                IF caja IS NOT NULL THEN
                    PERFORM 1 FROM cash_boxes WHERE id = caja FOR UPDATE;
                END IF;

                SELECT t.bank_account_id, b.currency, t.transaction_date INTO cuenta, moneda, fecha
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id;

                PERFORM 1 FROM bank_accounts WHERE id = cuenta FOR SHARE;
                SELECT cutoff_on INTO corte FROM bank_account_openings WHERE bank_account_id = cuenta;

                IF fecha <= corte THEN
                    RAISE EXCEPTION 'El movimiento bancario del % esta dentro del saldo informado al corte de la cuenta (%): no entra como dinero nuevo.', fecha, corte
                        USING ERRCODE = 'check_violation';
                END IF;

                IF caja IS NULL THEN RETURN NEW; END IF;
                SELECT opened_on INTO apertura FROM cash_book_openings
                 WHERE cash_box_id = caja AND currency = moneda;
                IF fecha < apertura THEN
                    RAISE EXCEPTION 'El movimiento bancario del % es anterior a la apertura en % del %: corresponde al circuito historico.', fecha, moneda, apertura
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
