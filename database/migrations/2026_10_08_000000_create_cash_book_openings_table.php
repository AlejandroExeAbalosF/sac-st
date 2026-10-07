<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La apertura de cada libro, como hecho propio.
 *
 * Hasta acá la apertura era solo un asiento `opening_balance` —más uno por
 * cada cheque detallado— que se reconocía por su clave de idempotencia. Eso
 * alcanzaba mientras fuera un aviso. Para **exigirla** antes de mover
 * dinero hace falta un dato que exista aunque no haya asiento: el día que
 * se abren los libros en dólares puede no haber ningún dólar, y un asiento
 * en cero no existe.
 *
 * Una fila por caja y moneda —pesos y dólares son dos libros sobre el
 * mismo cajón— con la fecha del saldo, quién lo declaró y el total
 * declarado. Total cero es «no había nada al abrir», dicho a propósito.
 *
 * Las guardas que agrega:
 *
 * - `journal_lines_require_opening` y `financial_events_require_opening`:
 *   no se asienta en un libro sin apertura, ni con fecha anterior a ella.
 * - `cash_counts_require_opening`: no se arquea un libro sin apertura.
 * - `period_closings_require_opening`: no se cierra un libro sin apertura.
 * - `cash_book_openings_consistent`: lo declarado coincide con los asientos
 *   de apertura, y la apertura es el primer hecho de su libro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_book_openings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();
            $table->char('currency', 3);

            /*
             * El primer día del libro en el sistema; lo declarado es el
             * cierre del día anterior. Lo de antes es histórico.
             */
            $table->date('opened_on');

            /*
             * La suma de lo que la apertura declaró en el cajón, los
             * cheques y la cuenta. Cero es una apertura sin saldo.
             */
            $table->decimal('declared_total', 19, 2);

            /*
             * Nulo solo para lo que no sale de una pantalla —seeders y la
             * carga de las aperturas anteriores a esta tabla—, igual que
             * `financial_events.posted_by`.
             */
            $table->foreignId('opened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('notes', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['cash_box_id', 'currency']);
        });

        DB::statement("ALTER TABLE cash_book_openings ADD CONSTRAINT cash_book_openings_currency_check
            CHECK (currency IN ('ARS', 'USD'))");
        DB::statement('ALTER TABLE cash_book_openings ADD CONSTRAINT cash_book_openings_total_check
            CHECK (declared_total >= 0)');

        $this->backfill();

        /*
         * Una apertura no se corrige: duplicaría o borraría el saldo
         * histórico. `forbid_mutation()` es la función genérica de las
         * tablas append-only.
         */
        DB::statement('CREATE TRIGGER cash_book_openings_append_only
            BEFORE UPDATE OR DELETE ON cash_book_openings
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');

        $this->openingIsConsistent();
        $this->entriesRequireOpening();
        $this->countsAndClosingsRequireOpening();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS period_closings_require_opening ON period_closings;
            DROP FUNCTION IF EXISTS period_closings_require_opening();
            DROP TRIGGER IF EXISTS cash_counts_require_opening ON cash_counts;
            DROP FUNCTION IF EXISTS cash_counts_require_opening();
            DROP TRIGGER IF EXISTS financial_events_require_opening ON financial_events;
            DROP FUNCTION IF EXISTS financial_events_require_opening();
            DROP TRIGGER IF EXISTS journal_lines_require_opening ON journal_lines;
            DROP FUNCTION IF EXISTS journal_lines_require_opening();
            DROP FUNCTION IF EXISTS book_opening_check(bigint, char, date, text);
            DROP TRIGGER IF EXISTS cash_book_openings_consistent ON cash_book_openings;
            DROP FUNCTION IF EXISTS cash_book_openings_consistent();
            DROP FUNCTION IF EXISTS opening_entries_total(bigint, char);
            DROP TRIGGER IF EXISTS cash_book_openings_append_only ON cash_book_openings;
        SQL);

        Schema::dropIfExists('cash_book_openings');
    }

    /**
     * Las aperturas que ya existen, tal como las dejaron sus asientos.
     *
     * La fecha es la del asiento más antiguo de ese libro y el total, la
     * suma de sus débitos: el principal y los de cada cheque detallado. El
     * responsable y la nota salen del asiento principal, el de la clave
     * `opening-balance:{caja}:{moneda}`; si solo hubo cheques, no hay
     * principal y quedan vacíos.
     */
    private function backfill(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO cash_book_openings (cash_box_id, currency, opened_on, declared_total, opened_by, notes, created_at)
            SELECT fe.cash_box_id,
                   jl.currency,
                   MIN(fe.event_date),
                   SUM(jl.debit),
                   principal.posted_by,
                   LEFT(principal.description, 300),
                   COALESCE(MIN(fe.posted_at), now())
              FROM financial_events fe
              JOIN journal_lines jl ON jl.financial_event_id = fe.id
              LEFT JOIN financial_events principal
                ON principal.idempotency_key = 'opening-balance:' || fe.cash_box_id || ':' || jl.currency
             WHERE fe.event_type = 'opening_balance'
               AND fe.status IN ('posted', 'reversed')
               AND fe.cash_box_id IS NOT NULL
             GROUP BY fe.cash_box_id, jl.currency, principal.posted_by, principal.description
        SQL);
    }

    /**
     * Lo declarado coincide con el libro, y nada en el libro es anterior.
     *
     * Diferida porque la apertura se escribe antes que sus asientos, en la
     * misma transacción: recién al confirmar están todos. Si el total es
     * cero no puede haber asiento de apertura; si no, la suma de sus
     * débitos tiene que darlo exacto.
     *
     * Y la apertura es el primer hecho de su libro: abrir con fecha
     * posterior a un movimiento, un arqueo o un cierre ya registrados
     * dejaría ese hecho operando sobre un saldo que todavía no existía.
     */
    private function openingIsConsistent(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION opening_entries_total(p_cash_box_id bigint, p_currency char(3))
            RETURNS numeric(19,2) AS $$
                SELECT COALESCE(SUM(jl.debit), 0)
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE fe.event_type = 'opening_balance'
                   AND fe.status IN ('posted', 'reversed')
                   AND fe.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION cash_book_openings_consistent() RETURNS trigger AS $$
            DECLARE
                v_asentado NUMERIC(19,2);
                v_fecha DATE;
            BEGIN
                v_asentado := opening_entries_total(NEW.cash_box_id, NEW.currency);

                IF v_asentado <> NEW.declared_total THEN
                    RAISE EXCEPTION
                        'La apertura en % declara % y sus asientos suman %.',
                        NEW.currency, NEW.declared_total, v_asentado
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT MIN(fe.event_date) INTO v_fecha
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE fe.cash_box_id = NEW.cash_box_id
                   AND jl.currency = NEW.currency
                   AND fe.event_type <> 'opening_balance'
                   AND fe.status <> 'draft';

                IF v_fecha IS NULL OR v_fecha >= NEW.opened_on THEN
                    SELECT MIN(counted_on) INTO v_fecha
                      FROM cash_counts
                     WHERE cash_box_id = NEW.cash_box_id
                       AND currency = NEW.currency;
                END IF;

                IF v_fecha IS NULL OR v_fecha >= NEW.opened_on THEN
                    SELECT MIN(period_to) INTO v_fecha
                      FROM period_closings
                     WHERE cash_box_id = NEW.cash_box_id
                       AND currency = NEW.currency;
                END IF;

                IF v_fecha IS NOT NULL AND v_fecha < NEW.opened_on THEN
                    RAISE EXCEPTION
                        'El libro en % ya tiene registros del %: la apertura no puede ser posterior.',
                        NEW.currency, v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cash_book_openings_consistent
                AFTER INSERT ON cash_book_openings
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cash_book_openings_consistent();
        SQL);
    }

    /**
     * No se asienta en un libro sin apertura, ni antes de ella.
     *
     * El mismo día de la apertura sí: la fecha es la del saldo declarado y
     * lo que pase ese día opera sobre él. Un asiento de apertura no pide
     * apertura —es ella—, pero sí que su fecha sea la declarada y que el
     * total de los asientos de apertura siga siendo el declarado.
     *
     * Diferidas como las del cierre (`journal_lines_period_open`): las
     * líneas se insertan después del evento, y la apertura antes que sus
     * asientos.
     */
    private function entriesRequireOpening(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION book_opening_check(
                p_cash_box_id bigint, p_currency char(3), p_event_date date, p_event_type text
            ) RETURNS void AS $$
            DECLARE
                v_apertura RECORD;
                v_asentado NUMERIC(19,2);
            BEGIN
                SELECT opened_on, declared_total INTO v_apertura
                  FROM cash_book_openings
                 WHERE cash_box_id = p_cash_box_id
                   AND currency = p_currency;

                IF NOT FOUND THEN
                    RAISE EXCEPTION
                        'El libro en % de esta caja todavia no tiene apertura: no admite movimientos.',
                        p_currency
                        USING ERRCODE = 'check_violation';
                END IF;

                IF p_event_type = 'opening_balance' THEN
                    IF p_event_date <> v_apertura.opened_on THEN
                        RAISE EXCEPTION
                            'Un asiento de apertura en % tiene que llevar la fecha de la apertura (%).',
                            p_currency, v_apertura.opened_on
                            USING ERRCODE = 'check_violation';
                    END IF;

                    v_asentado := opening_entries_total(p_cash_box_id, p_currency);

                    IF v_asentado <> v_apertura.declared_total THEN
                        RAISE EXCEPTION
                            'La apertura en % declara % y sus asientos suman %.',
                            p_currency, v_apertura.declared_total, v_asentado
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN;
                END IF;

                IF p_event_date < v_apertura.opened_on THEN
                    RAISE EXCEPTION
                        'La apertura en % es del %: no admite movimientos con fecha %.',
                        p_currency, v_apertura.opened_on, p_event_date
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION journal_lines_require_opening() RETURNS trigger AS $$
            DECLARE
                evento RECORD;
            BEGIN
                SELECT cash_box_id, event_date, status, event_type
                  INTO evento
                  FROM financial_events
                 WHERE id = NEW.financial_event_id;

                IF evento.cash_box_id IS NULL OR evento.status = 'draft' THEN
                    RETURN NULL;
                END IF;

                PERFORM book_opening_check(evento.cash_box_id, NEW.currency, evento.event_date, evento.event_type);

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_lines_require_opening
                AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION journal_lines_require_opening();

            -- Las líneas ya escritas se vuelven a mirar si el evento cambia
            -- de fecha o de caja, o pasa de borrador a posteado.
            CREATE OR REPLACE FUNCTION financial_events_require_opening() RETURNS trigger AS $$
            DECLARE
                v_moneda CHAR(3);
            BEGIN
                IF NEW.cash_box_id IS NULL OR NEW.status = 'draft' THEN
                    RETURN NULL;
                END IF;

                IF NEW.event_date = OLD.event_date
                    AND NEW.cash_box_id IS NOT DISTINCT FROM OLD.cash_box_id
                    AND NOT (OLD.status = 'draft' AND NEW.status = 'posted')
                THEN
                    RETURN NULL;
                END IF;

                FOR v_moneda IN
                    SELECT DISTINCT currency FROM journal_lines WHERE financial_event_id = NEW.id
                LOOP
                    PERFORM book_opening_check(NEW.cash_box_id, v_moneda, NEW.event_date, NEW.event_type);
                END LOOP;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER financial_events_require_opening
                AFTER UPDATE ON financial_events
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION financial_events_require_opening();
        SQL);
    }

    /**
     * Tampoco se arquea ni se cierra un libro sin apertura.
     *
     * Inmediatas, como `cash_counts_period_open`: el arqueo de la apertura
     * se guarda después de la fila de apertura, en la misma transacción. Un
     * cierre mensual del mes en que se abrió vale: alcanza con que termine
     * el día de la apertura o después.
     */
    private function countsAndClosingsRequireOpening(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_counts_require_opening() RETURNS trigger AS $$
            DECLARE
                v_abierto DATE;
            BEGIN
                SELECT opened_on INTO v_abierto
                  FROM cash_book_openings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency;

                IF v_abierto IS NULL THEN
                    RAISE EXCEPTION
                        'El libro en % de esta caja todavia no tiene apertura: no se puede arquear.',
                        NEW.currency
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.counted_on < v_abierto THEN
                    RAISE EXCEPTION
                        'La apertura en % es del %: no se arquea el %.',
                        NEW.currency, v_abierto, NEW.counted_on
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_counts_require_opening
                BEFORE INSERT ON cash_counts
                FOR EACH ROW EXECUTE FUNCTION cash_counts_require_opening();

            CREATE OR REPLACE FUNCTION period_closings_require_opening() RETURNS trigger AS $$
            DECLARE
                v_abierto DATE;
            BEGIN
                SELECT opened_on INTO v_abierto
                  FROM cash_book_openings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency;

                IF v_abierto IS NULL THEN
                    RAISE EXCEPTION
                        'El libro en % de esta caja todavia no tiene apertura: no hay nada que cerrar.',
                        NEW.currency
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.period_to < v_abierto THEN
                    RAISE EXCEPTION
                        'La apertura en % es del %: no se cierra un periodo que termina el %.',
                        NEW.currency, v_abierto, NEW.period_to
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER period_closings_require_opening
                BEFORE INSERT ON period_closings
                FOR EACH ROW EXECUTE FUNCTION period_closings_require_opening();
        SQL);
    }
};
