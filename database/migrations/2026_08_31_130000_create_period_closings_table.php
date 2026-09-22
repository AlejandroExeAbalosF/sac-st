<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierres de período — §9.9 del DER.
 *
 * El anverso de la planilla de caja, fila por fila: saldo inicial,
 * ingresos, egresos, lo depositado en el banco y el saldo final, en tres
 * columnas —efectivo, cheques y depósitos directos— y por moneda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_closings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();

            /** Un cierre por moneda: los totales de pesos y de dólares no se suman. */
            $table->char('currency', 3)->default('ARS');

            $table->string('period_type', 10);
            $table->date('period_from');
            $table->date('period_to');

            /*
             * Los tres saldos de apertura. La planilla arrastra tres
             * columnas y el cierre conserva esa estructura o no reproduce
             * el papel.
             */
            $table->decimal('opening_cash', 19, 2)->default(0);
            $table->decimal('opening_cheques', 19, 2)->default(0);
            $table->decimal('opening_bank_deposits', 19, 2)->default(0);

            $table->decimal('received_cash', 19, 2)->default(0);
            $table->decimal('received_cheques', 19, 2)->default(0);
            $table->decimal('received_bank_deposits', 19, 2)->default(0);

            $table->decimal('disbursed_cash', 19, 2)->default(0);
            $table->decimal('disbursed_cheques', 19, 2)->default(0);
            $table->decimal('disbursed_bank_deposits', 19, 2)->default(0);

            /*
             * La fila «DEPOSITOS BANCO MACRO CTA. 310000123456789»: lo que
             * salió de la caja hacia el banco ese día. El cheque en custodia
             * también se deposita, y por eso son dos y no una.
             */
            $table->decimal('deposited_to_bank_cash', 19, 2)->default(0);
            $table->decimal('deposited_to_bank_cheques', 19, 2)->default(0);

            /*
             * La cola de trabajo al cierre: plata recibida cuyo dueño
             * todavía no se determinó. No es un saldo de ubicación como los
             * tres de arriba —es de atribución— y por eso no entra en la
             * aritmética de las columnas.
             */
            $table->decimal('total_unassigned', 19, 2)->default(0);

            $table->string('status', 20)->default('draft');

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();

            $table->string('notes', 1000)->nullable();
            $table->timestampsTz();

            $table->unique(['cash_box_id', 'period_type', 'period_from', 'period_to', 'currency']);
            $table->index(['cash_box_id', 'period_to']);
            $table->index(['status']);
        });

        /*
         * ─── La aritmética de la planilla, impuesta por la base ──────────
         *
         * `SALDO FINAL = SALDO INICIAL + INGRESOS − EGRESOS − DEPOSITADO`.
         * Verificado contra el 02/06/2026: 6.852.300 + 24.985.600 −
         * 25.077.450 − 0 = 6.760.450.
         */
        DB::statement('ALTER TABLE period_closings
            ADD COLUMN closing_cash NUMERIC(19,2) GENERATED ALWAYS AS (
                opening_cash + received_cash - disbursed_cash - deposited_to_bank_cash
            ) STORED');

        DB::statement('ALTER TABLE period_closings
            ADD COLUMN closing_cheques NUMERIC(19,2) GENERATED ALWAYS AS (
                opening_cheques + received_cheques - disbursed_cheques - deposited_to_bank_cheques
            ) STORED');

        DB::statement('ALTER TABLE period_closings
            ADD COLUMN closing_bank_deposits NUMERIC(19,2) GENERATED ALWAYS AS (
                opening_bank_deposits + received_bank_deposits - disbursed_bank_deposits
            ) STORED');

        /*
         * La planilla emitida del día. Anotarla es lo único que un período
         * ya cerrado admite sin reabrirse, y el trigger de más abajo es
         * quien deja pasar ese UPDATE y ningún otro.
         */
        Schema::table('period_closings', function (Blueprint $table): void {
            $table->foreignId('sheet_attachment_id')->nullable()
                ->constrained('attachments')->nullOnDelete();
        });

        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_currency_check
            CHECK (currency IN ('ARS', 'USD'))");
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_type_check
            CHECK (period_type IN ('daily', 'monthly'))");
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_status_check
            CHECK (status IN ('draft', 'closed', 'reopened'))");

        DB::statement('ALTER TABLE period_closings ADD CONSTRAINT period_closings_range_check
            CHECK (period_from <= period_to)');

        /** Un cierre diario cubre un día. Si cubre dos, no es diario. */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_daily_check
            CHECK (period_type <> 'daily' OR period_from = period_to)");

        /** Un cierre mensual empieza el 1 y termina el último día del mes. */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_monthly_check
            CHECK (
                period_type <> 'monthly'
                OR (period_from = date_trunc('month', period_from)::date
                    AND period_to = (date_trunc('month', period_from) + INTERVAL '1 month - 1 day')::date)
            )");

        /*
         * Invariante 4 del §9.9: *«Toda reapertura conserva motivo, usuario
         * y fecha»*. Los tres o ninguno.
         */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_reopen_check
            CHECK (
                (status = 'reopened')
                = (reopened_by IS NOT NULL AND reopened_at IS NOT NULL AND reopen_reason IS NOT NULL)
            )");

        /** Cerrar deja firma y hora, y el borrador todavía no las tiene. */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_closed_check
            CHECK (
                (status = 'draft')
                = (closed_by IS NULL AND closed_at IS NULL)
            )");

        $this->cierreCongelado();
        $this->guardasDelCierre();
    }

    /**
     * Las guardas que el cierre le impone al resto del sistema.
     *
     * Viven acá y no en las tablas que vigilan porque todas preguntan lo
     * mismo: si el período que cubre esa fecha está cerrado. Antes de que
     * `period_closings` exista no hay nada que preguntar.
     *
     * - `financial_events_period_open` y `journal_lines_period_open`: no se
     *   asienta dentro de un período cerrado, ni con una fecha anterior que
     *   desactualice un cierre posterior.
     * - `cash_counts_period_open`: tampoco se arquea un día ya cerrado.
     * - `period_closings_ready_to_close`: qué exige un cierre para pasar a
     *   `closed` —arqueo resuelto, período terminado, y los días del mes
     *   cerrados si es mensual—.
     *
     * Todas son por moneda: los libros de pesos y de dólares se cierran por
     * separado.
     */
    private function guardasDelCierre(): void
    {
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION financial_events_period_open() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                cierre RECORD;
            BEGIN
                IF NEW.cash_box_id IS NULL THEN
                    RETURN NULL;
                END IF;
                IF NEW.status = 'draft' THEN
                    RETURN NULL;
                END IF;
                -- Una edición administrativa de un hecho ya posteado no
                -- agrega un movimiento. En cambio, pasar de borrador a
                -- posteado sí debe validar las líneas que ahora impactan.
                IF TG_OP = 'UPDATE'
                    AND NEW.event_date = OLD.event_date
                    AND NEW.cash_box_id IS NOT DISTINCT FROM OLD.cash_box_id
                    AND NOT (OLD.status = 'draft' AND NEW.status = 'posted')
                THEN
                    RETURN NULL;
                END IF;
                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND NEW.event_date BETWEEN period_from AND period_to
                   AND EXISTS (
                        SELECT 1
                          FROM journal_lines
                         WHERE financial_event_id = NEW.id
                           AND journal_lines.currency = period_closings.currency
                   )
                 ORDER BY period_to DESC
                 LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION
                        'El periodo % en % del % al % ya esta cerrado: no admite movimientos con fecha %.',
                        cierre.period_type, cierre.currency, cierre.period_from, cierre.period_to, NEW.event_date
                        USING ERRCODE = 'restrict_violation';
                END IF;
                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND period_from > NEW.event_date
                   AND EXISTS (
                        SELECT 1
                          FROM journal_lines
                         WHERE financial_event_id = NEW.id
                           AND journal_lines.currency = period_closings.currency
                   )
                 ORDER BY period_from
                 LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION
                        'Un movimiento del % en % cambiaria el saldo inicial del cierre % del % al %, que ya esta cerrado.',
                        NEW.event_date, cierre.currency, cierre.period_type, cierre.period_from, cierre.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION journal_lines_period_open() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                evento RECORD;
                cierre RECORD;
            BEGIN
                SELECT cash_box_id, event_date, status
                  INTO evento
                  FROM financial_events
                 WHERE id = NEW.financial_event_id;
                IF evento.cash_box_id IS NULL OR evento.status = 'draft' THEN
                    RETURN NULL;
                END IF;
                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = evento.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'closed'
                   AND evento.event_date BETWEEN period_from AND period_to
                 ORDER BY period_to DESC
                 LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION
                        'El periodo % en % del % al % ya esta cerrado: no admite movimientos con fecha %.',
                        cierre.period_type, cierre.currency, cierre.period_from, cierre.period_to, evento.event_date
                        USING ERRCODE = 'restrict_violation';
                END IF;
                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = evento.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'closed'
                   AND period_from > evento.event_date
                 ORDER BY period_from
                 LIMIT 1;
                IF FOUND THEN
                    RAISE EXCEPTION
                        'Un movimiento del % en % cambiaria el saldo inicial del cierre % del % al %, que ya esta cerrado.',
                        evento.event_date, cierre.currency, cierre.period_type, cierre.period_from, cierre.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NULL;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION cash_counts_period_open() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                desde DATE;
                hasta DATE;
            BEGIN
                SELECT period_from, period_to
                  INTO desde, hasta
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'closed'
                   AND NEW.counted_on BETWEEN period_from AND period_to
                 ORDER BY period_to DESC
                 LIMIT 1;
                IF desde IS NOT NULL THEN
                    RAISE EXCEPTION
                        'El % pertenece a un periodo cerrado (% al %): hay que reabrirlo para volver a contar.',
                        NEW.counted_on, desde, hasta
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            DECLARE
                pendientes INT;
                estado_arqueo TEXT;
                saldo_arqueo NUMERIC(19,2);
                diferencia_arqueo NUMERIC(19,2);
                instante_arqueo TIMESTAMPTZ;
                saldo_libro NUMERIC(19,2);
            BEGIN
                IF NEW.status <> 'closed'
                   OR (TG_OP = 'UPDATE' AND OLD.status = 'closed') THEN
                    RETURN NEW;
                END IF;
                IF NEW.period_to > (CURRENT_TIMESTAMP AT TIME ZONE 'America/Argentina/Salta')::date THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo que termina el %: esa fecha todavia no llego.', NEW.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;
                /*
                 * Un borrador de otra moneda no tiene nada que ver con este
                 * cierre. El que todavia no tiene lineas si frena las dos:
                 * sin lineas no hay moneda a la cual atribuirlo, y lo que
                 * no se sabe se trata como si molestara.
                 */
                SELECT count(*) INTO pendientes
                  FROM financial_events
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'draft'
                   AND event_date BETWEEN NEW.period_from AND NEW.period_to
                   AND (
                        EXISTS (
                            SELECT 1
                              FROM journal_lines
                             WHERE journal_lines.financial_event_id = financial_events.id
                               AND journal_lines.currency = NEW.currency
                        )
                        OR NOT EXISTS (
                            SELECT 1
                              FROM journal_lines
                             WHERE journal_lines.financial_event_id = financial_events.id
                        )
                   );
                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % asiento(s) en borrador adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;
                SELECT count(*) INTO pendientes
                  FROM cash_counts
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'draft'
                   AND counted_on BETWEEN NEW.period_from AND NEW.period_to;
                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % arqueo(s) sin resolver adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;
                SELECT count(*) INTO pendientes
                  FROM bank_statement_imports
                 WHERE status IN ('uploaded', 'parsing')
                   AND (
                        period_from IS NULL
                        OR (period_from <= NEW.period_to AND period_to >= NEW.period_from)
                   );
                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo mientras se importa un extracto con movimientos suyos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                IF NEW.period_type = 'monthly' THEN
                    /*
                     * El mes no cierra sobre dias que nunca se cerraron. Se
                     * exigen los dias con movimiento y no los del
                     * calendario: un sabado sin un solo asiento no tiene
                     * nada que arquear ni que cerrar.
                     */
                    SELECT count(*) INTO pendientes
                      FROM (
                            SELECT DISTINCT financial_events.event_date
                              FROM financial_events
                              JOIN journal_lines
                                ON journal_lines.financial_event_id = financial_events.id
                             WHERE financial_events.cash_box_id = NEW.cash_box_id
                               AND financial_events.status IN ('posted', 'reversed')
                               AND financial_events.event_date BETWEEN NEW.period_from AND NEW.period_to
                               AND journal_lines.currency = NEW.currency
                      ) AS operados
                     WHERE NOT EXISTS (
                            SELECT 1
                              FROM period_closings AS dia
                             WHERE dia.cash_box_id = NEW.cash_box_id
                               AND dia.currency = NEW.currency
                               AND dia.period_type = 'daily'
                               AND dia.status = 'closed'
                               AND dia.period_from = operados.event_date
                     );
                    IF pendientes > 0 THEN
                        RAISE EXCEPTION
                            'El mes no se cierra con % dia(s) con movimiento sin cerrar.', pendientes
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;
                IF NEW.period_type = 'daily' THEN
                    /*
                     * Imputar la diferencia mueve el libro a proposito: el
                     * asiento contra CASH_DIFFERENCE lo corre hasta igualar
                     * lo contado. Por eso un arqueo imputado se compara
                     * contra lo que concluyo que hay --contado mas lo no
                     * recontado-- y no contra el expected_amount, que quedo
                     * congelado antes de esa imputacion.
                     */
                    SELECT status,
                           CASE
                               WHEN status = 'adjusted'
                                   THEN counted_amount + uncounted_amount
                               ELSE expected_amount
                           END,
                           difference_amount,
                           counted_at
                      INTO estado_arqueo, saldo_arqueo, diferencia_arqueo, instante_arqueo
                      FROM cash_counts
                     WHERE cash_box_id = NEW.cash_box_id
                       AND currency = NEW.currency
                       AND counted_on = NEW.period_to
                     ORDER BY sequence DESC
                     LIMIT 1;
                    IF estado_arqueo IS NULL THEN
                        RAISE EXCEPTION
                            'Antes de cerrar el dia hay que contar el cajon y revisar el arqueo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF estado_arqueo NOT IN ('reviewed', 'adjusted') THEN
                        RAISE EXCEPTION
                            'El ultimo arqueo no puede respaldar este cierre: hay que contar y revisarlo otra vez.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF estado_arqueo = 'reviewed' AND diferencia_arqueo <> 0 THEN
                        RAISE EXCEPTION
                            'El arqueo del dia cierra con una diferencia de % sin imputar.', diferencia_arqueo
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF TG_OP = 'UPDATE'
                       AND OLD.status = 'reopened'
                       AND instante_arqueo < OLD.reopened_at THEN
                        RAISE EXCEPTION
                            'El cierre fue reabierto despues de este arqueo: hay que volver a contar el cajon.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    SELECT COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0)
                      INTO saldo_libro
                      FROM journal_lines
                      JOIN financial_events
                        ON financial_events.id = journal_lines.financial_event_id
                     WHERE financial_events.status IN ('posted', 'reversed')
                       AND financial_events.event_date <= NEW.period_to
                       AND journal_lines.cash_box_id = NEW.cash_box_id
                       AND journal_lines.currency = NEW.currency
                       AND journal_lines.account_code = 'CASH_ON_HAND';
                    IF saldo_arqueo <> saldo_libro THEN
                        RAISE EXCEPTION
                            'El saldo del libro cambio desde el ultimo arqueo: hay que volver a contar el cajon.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement('CREATE TRIGGER cash_counts_period_open BEFORE INSERT ON cash_counts FOR EACH ROW EXECUTE FUNCTION cash_counts_period_open()');

        DB::statement('CREATE CONSTRAINT TRIGGER financial_events_period_open AFTER INSERT OR UPDATE ON financial_events DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION financial_events_period_open()');

        DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_period_open AFTER INSERT ON journal_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journal_lines_period_open()');

        DB::statement('CREATE TRIGGER period_closings_ready_to_close BEFORE INSERT OR UPDATE ON period_closings FOR EACH ROW EXECUTE FUNCTION period_closings_ready_to_close()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS period_closings_frozen ON period_closings');
        DB::statement('DROP FUNCTION IF EXISTS period_closings_frozen()');

        Schema::dropIfExists('period_closings');
    }

    /**
     * Los totales se congelan como snapshot al cerrar.
     *
     * Regla 2 del §9.9. Un cierre cerrado que siguiera recalculándose
     * dejaría de ser un cierre: el papel que el área archiva y la fila de
     * la base tienen que decir lo mismo dentro de cinco años. La única
     * salida es la reapertura, que deja motivo y responsable.
     */
    private function cierreCongelado(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'draft' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Un periodo cerrado no se borra: se reabre.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                /*
                 * Anotar la planilla recien emitida es lo unico que un
                 * periodo cerrado admite sin reabrirse.
                 *
                 * Se compara la fila entera menos esa columna: si lo demas
                 * quedo igual, el cambio es solo el puntero al adjunto y
                 * pasa. Cualquier otra cosa que venga de contrabando en el
                 * mismo UPDATE se rechaza.
                 *
                 * Los tres saldos finales se excluyen porque **son columnas
                 * generadas**, y en un BEFORE UPDATE todavia no estan
                 * calculadas: PostgreSQL las resuelve despues de los
                 * triggers, asi que en NEW llegan nulas y toda comparacion
                 * daria distinto. No se pierde nada: dependen de las
                 * columnas base, que si se comparan.
                 */
                IF (to_jsonb(NEW) - 'sheet_attachment_id' - 'updated_at'
                        - 'closing_cash' - 'closing_cheques' - 'closing_bank_deposits')
                    = (to_jsonb(OLD) - 'sheet_attachment_id' - 'updated_at'
                        - 'closing_cash' - 'closing_cheques' - 'closing_bank_deposits')
                THEN
                    RETURN NEW;
                END IF;

                -- Del cierre solo se sale reabriendo.
                IF NEW.status <> 'reopened' THEN
                    RAISE EXCEPTION
                        'El periodo % — % ya esta cerrado. Reabrilo con motivo antes de tocarlo.',
                        OLD.period_from, OLD.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.opening_cash IS DISTINCT FROM OLD.opening_cash
                    OR NEW.opening_cheques IS DISTINCT FROM OLD.opening_cheques
                    OR NEW.opening_bank_deposits IS DISTINCT FROM OLD.opening_bank_deposits
                    OR NEW.received_cash IS DISTINCT FROM OLD.received_cash
                    OR NEW.received_cheques IS DISTINCT FROM OLD.received_cheques
                    OR NEW.received_bank_deposits IS DISTINCT FROM OLD.received_bank_deposits
                    OR NEW.disbursed_cash IS DISTINCT FROM OLD.disbursed_cash
                    OR NEW.disbursed_cheques IS DISTINCT FROM OLD.disbursed_cheques
                    OR NEW.disbursed_bank_deposits IS DISTINCT FROM OLD.disbursed_bank_deposits
                    OR NEW.deposited_to_bank_cash IS DISTINCT FROM OLD.deposited_to_bank_cash
                    OR NEW.deposited_to_bank_cheques IS DISTINCT FROM OLD.deposited_to_bank_cheques
                THEN
                    RAISE EXCEPTION 'Reabrir un periodo no reescribe su snapshot: lo habilita a recalcularse.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER period_closings_frozen
            BEFORE UPDATE OR DELETE ON period_closings
            FOR EACH ROW EXECUTE FUNCTION period_closings_frozen()');
    }
};
