<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El corte de una cuenta bancaria compartida.
 *
 * Hasta acá el corte del banco era la apertura de **la caja**: lo anterior
 * a esa fecha ya estaba en el saldo de «depósitos directos» que la caja
 * declaró. Con una cuenta que usan dos cajas —Multas y, mañana,
 * Aranceles— ese saldo no se puede atribuir a ninguna: se declara una vez,
 * para la cuenta, tal como lo informa el banco y sin repartir (M08, M12).
 *
 * Lo que agrega:
 *
 * - `bank_account_openings`: una por cuenta, append-only. `cutoff_on` es
 *   el último día incluido en `statement_balance`.
 * - `cash_book_openings.bank_account_id`: la cuenta con la que opera cada
 *   libro, con la moneda atada por FK compuesta. Haberes se completa desde
 *   la pata bancaria de su apertura.
 * - `bank_account_openings_guard`: no hay corte sobre una cuenta cuyo saldo
 *   ya declaró una caja, ni sobre una con créditos imputados hasta esa
 *   fecha, ni posterior a la apertura de un libro que opera con ella.
 * - `cash_book_openings_after_bank_cutoff`: lo mismo desde la apertura.
 * - `journal_lines_opening_without_shared_bank`: una apertura no declara
 *   saldo bancario sobre una cuenta con corte.
 * - `bank_allocation_after_opening`, redefinida: un movimiento hasta el
 *   corte de su cuenta tampoco entra al libro como dinero nuevo.
 *
 * Todas toman el candado de la cuenta (`bank_accounts`), así el corte y lo
 * que lo contradice no pasan a la vez.
 */
return new class extends Migration
{
    private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document',
                'fine_case', 'fine', 'fine_historical_movement'";

    public function up(): void
    {
        Schema::create('bank_account_openings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->unique()->constrained('bank_accounts')->restrictOnDelete();

            /* El último día que el saldo informado incluye. */
            $table->date('cutoff_on');

            /*
             * El saldo según el banco al cierre de `cutoff_on`, sin repartir.
             * Puede ser negativo: es lo que dice el banco, no lo que el
             * sistema querría.
             */
            $table->decimal('statement_balance', 19, 2);

            $table->foreignId('declared_by')->constrained('users')->restrictOnDelete();
            $table->string('notes', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('CREATE TRIGGER bank_account_openings_append_only
            BEFORE UPDATE OR DELETE ON bank_account_openings
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');

        DB::statement('ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_id_currency_unique UNIQUE (id, currency)');

        Schema::table('cash_book_openings', function (Blueprint $table): void {
            $table->unsignedBigInteger('bank_account_id')->nullable();
        });

        DB::statement('ALTER TABLE cash_book_openings ADD CONSTRAINT cash_book_openings_bank_account_fk
            FOREIGN KEY (bank_account_id, currency) REFERENCES bank_accounts (id, currency) ON DELETE RESTRICT');

        $this->backfillBookAccounts();
        $this->guards();

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'bank_account_opening'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_opening_without_shared_bank ON journal_lines;
            DROP FUNCTION IF EXISTS journal_lines_opening_without_shared_bank();
            DROP TRIGGER IF EXISTS cash_book_openings_after_bank_cutoff ON cash_book_openings;
            DROP FUNCTION IF EXISTS cash_book_openings_after_bank_cutoff();
            DROP TRIGGER IF EXISTS bank_account_openings_guard ON bank_account_openings;
            DROP FUNCTION IF EXISTS bank_account_openings_guard();

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

        DB::statement('ALTER TABLE cash_book_openings DROP CONSTRAINT cash_book_openings_bank_account_fk');
        Schema::table('cash_book_openings', function (Blueprint $table): void {
            $table->dropColumn('bank_account_id');
        });
        DB::statement('ALTER TABLE bank_accounts DROP CONSTRAINT bank_accounts_id_currency_unique');

        Schema::dropIfExists('bank_account_openings');
    }

    /**
     * La cuenta de los libros ya abiertos, tal como la dijo su apertura.
     *
     * Sale de la pata `BANK_ACCOUNT` de los asientos de apertura: es la
     * cuenta donde la caja declaró sus depósitos directos. Si un libro no
     * declaró saldo bancario, o lo declaró en más de una cuenta, queda sin
     * cuenta: no se adivina.
     *
     * La tabla es append-only; el candado se levanta solo para esta carga,
     * dentro de la migración, y lo único que se escribe es un dato que ya
     * estaba en el libro.
     */
    private function backfillBookAccounts(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cash_book_openings DISABLE TRIGGER cash_book_openings_append_only;

            UPDATE cash_book_openings o
               SET bank_account_id = c.bank_account_id
              FROM (
                    SELECT fe.cash_box_id, jl.currency, MIN(jl.bank_account_id) AS bank_account_id
                      FROM journal_lines jl
                      JOIN financial_events fe ON fe.id = jl.financial_event_id
                     WHERE fe.event_type = 'opening_balance'
                       AND fe.status IN ('posted', 'reversed')
                       AND jl.account_code = 'BANK_ACCOUNT'
                       AND jl.bank_account_id IS NOT NULL
                     GROUP BY fe.cash_box_id, jl.currency
                    HAVING COUNT(DISTINCT jl.bank_account_id) = 1
                   ) c
             WHERE c.cash_box_id = o.cash_box_id
               AND c.currency = o.currency;

            ALTER TABLE cash_book_openings ENABLE TRIGGER cash_book_openings_append_only;
        SQL);
    }

    private function guards(): void
    {
        DB::unprepared(<<<'SQL'
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

                -- Primero la caja y después la cuenta: el mismo orden que la apertura.
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
