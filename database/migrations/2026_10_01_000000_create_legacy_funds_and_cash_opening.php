<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidación cronológica del proceso. Cada bloque conserva la migración
 * original para mantener visibles sus reglas y permitir el rollback inverso.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->migrations() as $migration) {
            $this->run($migration, 'up');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->migrations()) as $migration) {
            $this->run($migration, 'down');
        }
    }

    private function run(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];

        if (! is_callable($callback)) {
            throw new LogicException("La migración consolidada no tiene {$method}().");
        }

        $callback();
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        return [
            /**
             * Del sistema anterior no se paga más de lo que se declaró al abrir los libros.
             *
             * `PayLegacyBeneficiary` lo comprobaba antes de escribir, pero solo en PHP y
             * sin bloquear nada: dos pagos simultáneos leían el mismo pendiente y
             * pasaban los dos, y una escritura que no entrara por el Action no
             * encontraba ninguna guarda. Un saldo negativo de `LEGACY_FUNDS` diría que
             * se entregó plata vieja que nunca estuvo.
             *
             * El trigger es **diferido** porque mira el saldo del asiento entero, y
             * toma un **bloqueo** por caja y moneda antes de sumar: la segunda
             * transacción espera a que la primera confirme y, como en `READ COMMITTED`
             * cada consulta ve lo ya confirmado, suma con el consumo de la otra. Es un
             * bloqueo consultivo propio, como el de `ensure_an_active_administrator`,
             * y no la fila de la caja: así serializa solo los consumos de plata vieja y
             * vale también para una escritura que no pasó por `PostJournalEntry`.
             *
             * Solo controla los débitos: acreditar `LEGACY_FUNDS` nunca lo deja
             * negativo. Incluye las líneas de eventos posteados y revertidos, igual que
             * `CashBalance`.
             */
            // Migración original: 2026_10_01_000000_keep_legacy_funds_non_negative.php
            new class extends Migration
            {
                public function up(): void
                {
                    $negativos = DB::scalar(<<<'SQL'
            SELECT string_agg(format('caja %s en %s: %s', COALESCE(cash_box_id::text, 'sin caja'), currency, saldo), '; ')
              FROM (
                SELECT jl.cash_box_id, jl.currency, SUM(jl.credit) - SUM(jl.debit) AS saldo
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE jl.account_code = 'LEGACY_FUNDS'
                   AND fe.status <> 'draft'
                 GROUP BY jl.cash_box_id, jl.currency
                HAVING SUM(jl.credit) - SUM(jl.debit) < 0
              ) AS saldos
        SQL);

                    if (is_string($negativos)) {
                        throw new RuntimeException(
                            'LEGACY_FUNDS ya tiene saldo negativo y la guarda no se puede crear: '.$negativos
                        );
                    }

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_balance_check(p_cash_box_id bigint, p_currency char(3))
            RETURNS void AS $$
            DECLARE
                v_saldo NUMERIC(19,2);
            BEGIN
                PERFORM pg_advisory_xact_lock(
                    hashtext('sacst.legacy_funds.' || p_currency),
                    COALESCE(p_cash_box_id, 0)::int
                );

                SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0)
                  INTO v_saldo
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE jl.account_code = 'LEGACY_FUNDS'
                   AND jl.cash_box_id IS NOT DISTINCT FROM p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft';

                IF v_saldo < 0 THEN
                    RAISE EXCEPTION
                        'Del sistema anterior no queda tanto por pagar: el saldo en % quedaria en %.',
                        p_currency, v_saldo
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION legacy_funds_line_non_negative() RETURNS trigger AS $$
            BEGIN
                PERFORM legacy_funds_balance_check(NEW.cash_box_id, NEW.currency);

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_lines_legacy_funds_non_negative
                AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (NEW.account_code = 'LEGACY_FUNDS' AND NEW.debit > 0)
                EXECUTE FUNCTION legacy_funds_line_non_negative();

            -- Un borrador no cuenta: el débito pesa recién cuando el evento se postea.
            CREATE OR REPLACE FUNCTION legacy_funds_event_non_negative() RETURNS trigger AS $$
            DECLARE
                v_linea RECORD;
            BEGIN
                FOR v_linea IN
                    SELECT DISTINCT cash_box_id, currency
                      FROM journal_lines
                     WHERE financial_event_id = NEW.id
                       AND account_code = 'LEGACY_FUNDS'
                       AND debit > 0
                LOOP
                    PERFORM legacy_funds_balance_check(v_linea.cash_box_id, v_linea.currency);
                END LOOP;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER financial_events_legacy_funds_non_negative
                AFTER UPDATE OF status ON financial_events
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (OLD.status = 'draft' AND NEW.status <> 'draft')
                EXECUTE FUNCTION legacy_funds_event_non_negative();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS financial_events_legacy_funds_non_negative ON financial_events;
            DROP TRIGGER IF EXISTS journal_lines_legacy_funds_non_negative ON journal_lines;
            DROP FUNCTION IF EXISTS legacy_funds_event_non_negative();
            DROP FUNCTION IF EXISTS legacy_funds_line_non_negative();
            DROP FUNCTION IF EXISTS legacy_funds_balance_check(bigint, char);
        SQL);
                }
            },

            /**
             * Las cuotas que se pagaron fuera del circuito, y los papeles que lo prueban.
             *
             * Un expediente histórico se carga con las pantallas de siempre. Lo nuevo
             * es poder decir de una cuota que **ya se pagó**, de una de dos maneras:
             *
             * - `before_opening`: en papel, antes de que el sistema abriera los libros.
             *   No hay asiento que hacer: la plata entró y salió antes de la apertura.
             * - `legacy_disbursement`: desde «Pagos anteriores», con un egreso que el
             *   sistema ya contabilizó. El vínculo es documental y no toca ese asiento.
             *
             * Los papeles —recibo de ingreso, Orden de Pago y recibo de egreso— se
             * guardan con su número de talonario y su fecha, nunca con numeración del
             * sistema: ese número es el que alguien va a buscar en el archivo.
             *
             * Todo es **append-only con anulación**: un registro mal cargado se anula
             * con motivo y la cuota vuelve a estar pendiente. Borrarlo dejaría sin
             * rastro que alguien la dio por pagada.
             */
            // Migración original: 2026_10_01_010000_create_legacy_settlement_tables.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE beneficiary_installments DROP CONSTRAINT installments_workflow_status_check');
                    DB::statement("ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_workflow_status_check
            CHECK (workflow_status IN ('active', 'suspended', 'blocked', 'cancelled', 'paid', 'legacy_settled'))");

                    $this->createSettlements();
                    $this->createDocuments();
                    $this->appendOnly();
                    $this->coherence();
                    $this->noMovementsOnSettledInstallments();
                    $this->cutoffDate();
                    $this->legacyDisbursementLink();
                }

                private function createSettlements(): void
                {
                    Schema::create('legacy_settlements', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('haber_id')->constrained('haberes')->restrictOnDelete();
                        $table->foreignId('beneficiary_installment_id')->constrained('beneficiary_installments')->restrictOnDelete();
                        $table->string('mode', 30);
                        /*
                         * El importe de la cuota cuando se dio por pagada. Queda fijo:
                         * la cuota no se puede editar mientras esté saldada, y la base
                         * exige que su importe siga siendo este.
                         */
                        $table->decimal('amount', 19, 2);
                        $table->date('paid_on')->nullable();
                        $table->string('payment_medium', 20)->nullable();
                        $table->foreignId('legacy_disbursement_receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();
                        $table->string('notes', 500)->nullable();
                        $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('recorded_at')->useCurrent();
                        $table->timestampTz('voided_at')->nullable();
                        $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->string('void_reason', 500)->nullable();

                        $table->index('legacy_disbursement_receipt_id');
                    });

                    DB::statement('ALTER TABLE legacy_settlements ADD CONSTRAINT legacy_settlements_installment_fk
            FOREIGN KEY (beneficiary_installment_id, haber_id)
            REFERENCES beneficiary_installments (id, haber_id)');
                    DB::statement("ALTER TABLE legacy_settlements ADD CONSTRAINT legacy_settlements_mode_check
            CHECK (mode IN ('before_opening', 'legacy_disbursement'))");
                    DB::statement('ALTER TABLE legacy_settlements ADD CONSTRAINT legacy_settlements_amount_check
            CHECK (amount > 0)');
                    DB::statement("ALTER TABLE legacy_settlements ADD CONSTRAINT legacy_settlements_medium_check
            CHECK (payment_medium IS NULL OR payment_medium IN ('cash', 'cheque', 'bank'))");
                    /*
                     * En papel, la fecha y el medio los dice quien carga. Desde «Pagos
                     * anteriores» los dice el recibo vinculado, y guardarlos de nuevo
                     * abriría la puerta a que los dos digan cosas distintas.
                     */
                    DB::statement("ALTER TABLE legacy_settlements ADD CONSTRAINT legacy_settlements_mode_fields_check
            CHECK (
                (mode = 'before_opening'
                    AND paid_on IS NOT NULL AND payment_medium IS NOT NULL
                    AND legacy_disbursement_receipt_id IS NULL)
                OR (mode = 'legacy_disbursement'
                    AND paid_on IS NULL AND payment_medium IS NULL
                    AND legacy_disbursement_receipt_id IS NOT NULL)
            )");
                    DB::statement('ALTER TABLE legacy_settlements ADD CONSTRAINT legacy_settlements_void_check
            CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');
                    DB::statement('CREATE UNIQUE INDEX legacy_settlements_one_per_installment
            ON legacy_settlements (beneficiary_installment_id) WHERE voided_at IS NULL');
                }

                private function createDocuments(): void
                {
                    Schema::create('legacy_documents', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('haber_id')->constrained('haberes')->restrictOnDelete();
                        $table->foreignId('beneficiary_installment_id')->constrained('beneficiary_installments')->restrictOnDelete();
                        /*
                         * El registro de pago que lo trajo, si lo trajo uno. Al anularlo
                         * caen sus papeles; un recibo de ingreso cargado antes, al
                         * apartar fondos, no le pertenece y sobrevive.
                         */
                        $table->foreignId('legacy_settlement_id')->nullable()->constrained('legacy_settlements')->restrictOnDelete();
                        $table->string('kind', 30);
                        $table->string('number', 40);
                        $table->date('issued_on');
                        /** Lo que dice el papel. Inmutable, aunque la cuota cambie después. */
                        $table->decimal('amount', 19, 2);
                        $table->string('medium', 20)->nullable();
                        $table->string('notes', 500)->nullable();
                        $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('recorded_at')->useCurrent();
                        $table->timestampTz('voided_at')->nullable();
                        $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->string('void_reason', 500)->nullable();

                        $table->index('beneficiary_installment_id');
                        $table->index('legacy_settlement_id');
                    });

                    DB::statement('ALTER TABLE legacy_documents ADD CONSTRAINT legacy_documents_installment_fk
            FOREIGN KEY (beneficiary_installment_id, haber_id)
            REFERENCES beneficiary_installments (id, haber_id)');
                    DB::statement("ALTER TABLE legacy_documents ADD CONSTRAINT legacy_documents_kind_check
            CHECK (kind IN ('income_receipt', 'payment_order', 'expense_receipt'))");
                    DB::statement("ALTER TABLE legacy_documents ADD CONSTRAINT legacy_documents_number_check
            CHECK (btrim(number) <> '')");
                    DB::statement('ALTER TABLE legacy_documents ADD CONSTRAINT legacy_documents_amount_check
            CHECK (amount > 0)');
                    DB::statement("ALTER TABLE legacy_documents ADD CONSTRAINT legacy_documents_medium_check
            CHECK (medium IS NULL OR medium IN ('cash', 'cheque', 'bank'))");
                    DB::statement('ALTER TABLE legacy_documents ADD CONSTRAINT legacy_documents_void_check
            CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL))');

                    /*
                     * El mismo papel no se carga dos veces. Número y fecha juntos,
                     * porque no se sabe si la numeración de los talonarios se reinicia:
                     * dos recibos con el mismo número en fechas distintas pueden ser
                     * legítimos —de eso avisa el Action—; el mismo número el mismo día,
                     * no. El número se compara sin espacios ni mayúsculas.
                     */
                    DB::statement('CREATE UNIQUE INDEX legacy_documents_paper_unique
            ON legacy_documents (kind, upper(btrim(number)), issued_on) WHERE voided_at IS NULL');
                    DB::statement('CREATE UNIQUE INDEX legacy_documents_one_kind_per_installment
            ON legacy_documents (beneficiary_installment_id, kind) WHERE voided_at IS NULL');

                    // La foto del papel se guarda como cualquier otro adjunto.
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN (
                'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document'
            ))");
                }

                /**
                 * Ni se borran ni se editan: solo se anulan, una vez.
                 */
                private function appendOnly(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_records_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un registro anulado del sistema anterior no se modifica.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason']) THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se edita: se anula y se carga de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER legacy_settlements_append_only
                BEFORE UPDATE OR DELETE ON legacy_settlements
                FOR EACH ROW EXECUTE FUNCTION legacy_records_append_only();

            CREATE TRIGGER legacy_documents_append_only
                BEFORE UPDATE OR DELETE ON legacy_documents
                FOR EACH ROW EXECUTE FUNCTION legacy_records_append_only();
        SQL);
                }

                /**
                 * El estado de la cuota y su registro dicen lo mismo, siempre.
                 *
                 * Diferido, porque el Action escribe el registro y cambia el estado en
                 * dos sentencias. Mirado al confirmar:
                 *
                 * - `legacy_settled` si y solo si hay un registro vigente;
                 * - el importe de la cuota es el que quedó registrado;
                 * - el registro vigente tiene su recibo de ingreso de papel, y el que
                 *   viene de «Pagos anteriores» no tiene recibo de egreso de papel:
                 *   su egreso es el recibo del sistema.
                 *
                 * Es lo que impide que una baja del haber barra una cuota pagada a
                 * `cancelled`, o que alguien le corrija el importe.
                 */
                private function coherence(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_settlement_coherence(p_installment_id bigint) RETURNS void AS $$
            DECLARE
                v_status TEXT;
                v_expected NUMERIC(19,2);
                v_registro RECORD;
            BEGIN
                SELECT workflow_status, expected_amount INTO v_status, v_expected
                  FROM beneficiary_installments WHERE id = p_installment_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT id, mode, amount INTO v_registro
                  FROM legacy_settlements
                 WHERE beneficiary_installment_id = p_installment_id AND voided_at IS NULL;

                IF v_status = 'legacy_settled' AND v_registro.id IS NULL THEN
                    RAISE EXCEPTION 'La cuota % figura pagada fuera del circuito sin un registro que lo respalde.', p_installment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_status <> 'legacy_settled' AND v_registro.id IS NOT NULL THEN
                    RAISE EXCEPTION 'La cuota % tiene registrado un pago fuera del circuito: primero hay que anular ese registro.', p_installment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_registro.id IS NULL THEN
                    RETURN;
                END IF;

                IF v_expected <> v_registro.amount THEN
                    RAISE EXCEPTION 'El importe de la cuota % no puede cambiar: se dio por pagada con %.', p_installment_id, v_registro.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = p_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'Una cuota pagada fuera del circuito necesita su recibo de ingreso de papel.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_registro.mode = 'legacy_disbursement' AND EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = p_installment_id
                       AND kind = 'expense_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'Una cuota pagada desde Pagos anteriores ya tiene su recibo de egreso del sistema: no lleva uno de papel.'
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION legacy_settlement_coherence_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'beneficiary_installments' THEN
                    PERFORM legacy_settlement_coherence(NEW.id);
                ELSE
                    PERFORM legacy_settlement_coherence(NEW.beneficiary_installment_id);
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER installments_legacy_settlement_coherence
                AFTER INSERT OR UPDATE OF workflow_status, expected_amount ON beneficiary_installments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION legacy_settlement_coherence_trigger();

            CREATE CONSTRAINT TRIGGER legacy_settlements_coherence
                AFTER INSERT OR UPDATE ON legacy_settlements
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION legacy_settlement_coherence_trigger();

            CREATE CONSTRAINT TRIGGER legacy_documents_coherence
                AFTER INSERT OR UPDATE ON legacy_documents
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION legacy_settlement_coherence_trigger();
        SQL);
                }

                /**
                 * Una cuota pagada fuera del circuito no se vuelve a pagar adentro.
                 *
                 * Los dos sentidos, y los dos con bloqueo sobre la cuota:
                 *
                 * - registrar el pago exige que la cuota no tenga movimientos
                 *   (`FOR UPDATE`);
                 * - una cuota saldada no acepta movimientos nuevos (`FOR SHARE`).
                 *
                 * El bloqueo es lo que los ordena: si una asignación y el registro
                 * llegan juntos, el segundo espera al primero y ve lo que hizo.
                 */
                private function noMovementsOnSettledInstallments(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_settlement_requires_clean_installment() RETURNS trigger AS $$
            BEGIN
                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM funding_allocations
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                    HAVING COALESCE(SUM(CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END), 0) <> 0
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene fondos asignados: no se puede dar por pagada fuera del circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM payment_orders
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND status NOT IN ('voided', 'rejected')
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene una Orden de Pago: no se puede dar por pagada fuera del circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM disbursements
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un egreso: no se puede dar por pagada fuera del circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM deposit_tickets
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND status <> 'discarded'
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un comprobante de depósito vigente: no se puede dar por pagada fuera del circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM receipts
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND status = 'issued'
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo del sistema emitido: no se puede dar por pagada fuera del circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER legacy_settlements_clean_installment
                BEFORE INSERT ON legacy_settlements
                FOR EACH ROW EXECUTE FUNCTION legacy_settlement_requires_clean_installment();

            CREATE OR REPLACE FUNCTION reject_movement_on_legacy_settled_installment() RETURNS trigger AS $$
            DECLARE
                v_status TEXT;
            BEGIN
                IF NEW.beneficiary_installment_id IS NULL THEN
                    RETURN NEW;
                END IF;

                -- Devolver lo asignado nunca es un movimiento nuevo. Va anidado:
                -- PL/pgSQL no corta el AND, y las otras tablas no tienen la columna.
                IF TG_TABLE_NAME = 'funding_allocations' THEN
                    IF NEW.allocation_kind = 'reversal' THEN
                        RETURN NEW;
                    END IF;
                END IF;

                SELECT workflow_status INTO v_status
                  FROM beneficiary_installments
                 WHERE id = NEW.beneficiary_installment_id
                   FOR SHARE;

                IF v_status = 'legacy_settled' THEN
                    RAISE EXCEPTION 'La cuota % ya se pagó fuera del circuito: no admite movimientos nuevos.', NEW.beneficiary_installment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER funding_allocations_not_on_legacy_settled
                BEFORE INSERT ON funding_allocations
                FOR EACH ROW EXECUTE FUNCTION reject_movement_on_legacy_settled_installment();

            CREATE TRIGGER payment_orders_not_on_legacy_settled
                BEFORE INSERT ON payment_orders
                FOR EACH ROW EXECUTE FUNCTION reject_movement_on_legacy_settled_installment();

            CREATE TRIGGER disbursements_not_on_legacy_settled
                BEFORE INSERT ON disbursements
                FOR EACH ROW EXECUTE FUNCTION reject_movement_on_legacy_settled_installment();

            CREATE TRIGGER deposit_tickets_not_on_legacy_settled
                BEFORE INSERT ON deposit_tickets
                FOR EACH ROW EXECUTE FUNCTION reject_movement_on_legacy_settled_installment();

            CREATE TRIGGER receipts_not_on_legacy_settled
                BEFORE INSERT ON receipts
                FOR EACH ROW EXECUTE FUNCTION reject_movement_on_legacy_settled_installment();
        SQL);
                }

                /**
                 * Los papeles históricos son anteriores a la apertura de la caja.
                 *
                 * El corte es **la primera apertura vigente de la caja de Haberes**,
                 * en cualquier moneda: es el día en que el sistema empezó a contar.
                 * Sin apertura no hay corte, y sin corte no se cargan papeles: no se
                 * podría decir si un recibo es de antes o de después.
                 *
                 * Y al revés: una apertura no puede registrarse con fecha igual o
                 * anterior a un papel ya cargado, porque lo convertiría en posterior.
                 */
                private function cutoffDate(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(fe.event_date)
                  FROM financial_events fe
                  JOIN cash_boxes cb ON cb.id = fe.cash_box_id
                 WHERE cb.code = 'haberes'
                   AND fe.event_type = 'opening_balance'
                   AND fe.status = 'posted'
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_paper_before_opening() RETURNS trigger AS $$
            DECLARE
                v_apertura DATE;
                v_fecha DATE;
            BEGIN
                IF TG_TABLE_NAME = 'legacy_documents' THEN
                    v_fecha := NEW.issued_on;
                ELSE
                    v_fecha := NEW.paid_on;
                END IF;

                IF v_fecha IS NULL THEN
                    RETURN NEW;
                END IF;

                v_apertura := haberes_opening_date();

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'La caja de Haberes todavía no tiene apertura: sin ella no se puede saber si un papel es anterior al sistema.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_fecha >= v_apertura THEN
                    RAISE EXCEPTION 'La fecha % no es anterior a la apertura de la caja (%).', v_fecha, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER legacy_documents_before_opening
                BEFORE INSERT ON legacy_documents
                FOR EACH ROW EXECUTE FUNCTION legacy_paper_before_opening();

            CREATE TRIGGER legacy_settlements_before_opening
                BEFORE INSERT ON legacy_settlements
                FOR EACH ROW EXECUTE FUNCTION legacy_paper_before_opening();

            CREATE OR REPLACE FUNCTION opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                    UNION ALL
                    SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.event_date <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior fechados hasta el %: la apertura tiene que ser posterior.', v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER financial_events_opening_after_legacy_papers
                BEFORE INSERT OR UPDATE OF status ON financial_events
                FOR EACH ROW
                WHEN (NEW.event_type = 'opening_balance' AND NEW.status = 'posted')
                EXECUTE FUNCTION opening_after_legacy_papers();
        SQL);
                }

                /**
                 * El vínculo con un pago hecho desde «Pagos anteriores».
                 *
                 * El recibo tiene que ser un egreso del sistema anterior —sin cuota,
                 * ligado a un `legacy_disbursement`, emitido—, del mismo beneficiario
                 * y en la misma moneda que el haber. Un recibo puede cubrir varias
                 * cuotas, pero no más de lo que pagó: la suma se hace con el recibo
                 * bloqueado, así que dos vínculos simultáneos no leen el mismo
                 * disponible.
                 *
                 * Y el recibo no se anula mientras tenga vínculos: primero se anula el
                 * vínculo, que es documental, y recién después el pago.
                 */
                private function legacyDisbursementLink(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_settlement_receipt_link() RETURNS trigger AS $$
            DECLARE
                v_recibo RECORD;
                v_haber RECORD;
                v_vinculado NUMERIC(19,2);
            BEGIN
                IF NEW.legacy_disbursement_receipt_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT id, receipt_type, beneficiary_installment_id, status, person_id, amount
                  INTO v_recibo
                  FROM receipts WHERE id = NEW.legacy_disbursement_receipt_id
                   FOR UPDATE;

                IF v_recibo.receipt_type <> 'expense'
                    OR v_recibo.beneficiary_installment_id IS NOT NULL
                    OR NOT EXISTS (
                        SELECT 1 FROM receipt_financial_events rfe
                          JOIN financial_events fe ON fe.id = rfe.financial_event_id
                         WHERE rfe.receipt_id = v_recibo.id
                           AND fe.event_type = 'legacy_disbursement'
                    ) THEN
                    RAISE EXCEPTION 'El recibo % no es un pago hecho desde Pagos anteriores.', v_recibo.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recibo.status <> 'issued' THEN
                    RAISE EXCEPTION 'El recibo % no está vigente.', v_recibo.id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT beneficiary_id, currency INTO v_haber FROM haberes WHERE id = NEW.haber_id;

                IF v_recibo.person_id <> v_haber.beneficiary_id THEN
                    RAISE EXCEPTION 'El recibo % se le pagó a otra persona.', v_recibo.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM receipt_financial_events rfe
                      JOIN journal_lines jl ON jl.financial_event_id = rfe.financial_event_id
                     WHERE rfe.receipt_id = v_recibo.id
                       AND jl.currency <> v_haber.currency
                ) THEN
                    RAISE EXCEPTION 'El recibo % se pagó en otra moneda que la del haber.', v_recibo.id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(amount), 0) INTO v_vinculado
                  FROM legacy_settlements
                 WHERE legacy_disbursement_receipt_id = v_recibo.id AND voided_at IS NULL;

                IF v_vinculado + NEW.amount > v_recibo.amount THEN
                    RAISE EXCEPTION 'El recibo % es de % y ya cubre %: no alcanza para %.',
                        v_recibo.id, v_recibo.amount, v_vinculado, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER legacy_settlements_receipt_link
                BEFORE INSERT ON legacy_settlements
                FOR EACH ROW EXECUTE FUNCTION legacy_settlement_receipt_link();

            CREATE OR REPLACE FUNCTION receipts_keep_legacy_settlement_links() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM legacy_settlements
                     WHERE legacy_disbursement_receipt_id = NEW.id AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'El recibo % respalda cuotas pagadas desde Pagos anteriores: primero hay que anular esos vínculos.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER receipts_keep_legacy_settlement_links
                BEFORE UPDATE OF status ON receipts
                FOR EACH ROW
                WHEN (OLD.status = 'issued' AND NEW.status <> 'issued')
                EXECUTE FUNCTION receipts_keep_legacy_settlement_links();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS receipts_keep_legacy_settlement_links ON receipts;
            DROP FUNCTION IF EXISTS receipts_keep_legacy_settlement_links();
            DROP TRIGGER IF EXISTS financial_events_opening_after_legacy_papers ON financial_events;
            DROP FUNCTION IF EXISTS opening_after_legacy_papers();
            DROP TRIGGER IF EXISTS funding_allocations_not_on_legacy_settled ON funding_allocations;
            DROP TRIGGER IF EXISTS payment_orders_not_on_legacy_settled ON payment_orders;
            DROP TRIGGER IF EXISTS disbursements_not_on_legacy_settled ON disbursements;
            DROP TRIGGER IF EXISTS deposit_tickets_not_on_legacy_settled ON deposit_tickets;
            DROP TRIGGER IF EXISTS receipts_not_on_legacy_settled ON receipts;
            DROP FUNCTION IF EXISTS reject_movement_on_legacy_settled_installment();
            DROP TRIGGER IF EXISTS installments_legacy_settlement_coherence ON beneficiary_installments;
        SQL);

                    Schema::dropIfExists('legacy_documents');
                    Schema::dropIfExists('legacy_settlements');

                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN (
                'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing'
            ))");

                    DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS legacy_settlement_receipt_link();
            DROP FUNCTION IF EXISTS legacy_paper_before_opening();
            DROP FUNCTION IF EXISTS haberes_opening_date();
            DROP FUNCTION IF EXISTS legacy_settlement_requires_clean_installment();
            DROP FUNCTION IF EXISTS legacy_settlement_coherence_trigger();
            DROP FUNCTION IF EXISTS legacy_settlement_coherence(bigint);
            DROP FUNCTION IF EXISTS legacy_records_append_only();
        SQL);

                    /*
                     * Volver atrás con cuotas saldadas las dejaría sin estado válido:
                     * mejor que la migración falle a que el CHECK las acepte mintiendo.
                     */
                    DB::statement('ALTER TABLE beneficiary_installments DROP CONSTRAINT installments_workflow_status_check');
                    DB::statement("ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_workflow_status_check
            CHECK (workflow_status IN ('active', 'suspended', 'blocked', 'cancelled', 'paid'))");
                }
            },

            /**
             * Apartar del saldo del sistema anterior la plata de una cuota.
             *
             * Cuando un expediente histórico todavía tiene plata en custodia, esa
             * plata está en `LEGACY_FUNDS`: la apertura la acreditó ahí sin saber de
             * quién era. Apartarla es decirlo, con un asiento que no la mueve de lugar:
             *
             * ```text
             * legacy_funds_allocated
             * Débito   LEGACY_FUNDS        deja de ser «del sistema anterior»
             * Crédito  BENEFICIARY_FUNDS   y pasa a ser de esta cuota
             * ```
             *
             * Desde ahí la cuota sigue el circuito de siempre. Lo que cambia es de
             * dónde sale el dinero y qué recibo de ingreso lo respalda:
             *
             * - **la recepción** dice su origen: `received` (entró por el circuito),
             *   `opening` (un cheque de la cartera de la apertura) o `legacy` (efectivo
             *   o un depósito directo apartados). Una `legacy` nace con su asignación
             *   y no se reutiliza;
             * - **el recibo de ingreso** es el de papel: la Orden de Pago puede apuntar
             *   a él en lugar de a uno del sistema, nunca a los dos.
             *
             * Y una cuota no mezcla dinero del sistema anterior con dinero actual: sus
             * respaldos documentales serían dos y la Orden imprime uno.
             */
            // Migración original: 2026_10_01_020000_allow_allocating_legacy_funds.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check');
                    DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
            CHECK (event_type IN (
                'funds_received', 'funds_allocated', 'cash_disbursement',
                'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                'cash_adjustment', 'opening_balance', 'legacy_disbursement',
                'reversal', 'authorized_adjustment', 'legacy_funds_allocated'
            ))");

                    $this->fundReceiptOrigin();
                    $this->legacyEntryShape();
                    $this->allocationOrigin();
                    $this->paperIncomeReceipt();
                }

                /**
                 * De dónde viene el dinero de cada recepción.
                 *
                 * Lo dice el tipo de su evento y por eso la base lo exige: un cheque de
                 * la apertura es `opening`, lo apartado es `legacy`, y todo lo que entró
                 * por el circuito es `received`.
                 */
                private function fundReceiptOrigin(): void
                {
                    Schema::table('fund_receipts', function (Blueprint $table): void {
                        $table->string('origin', 20)->default('received');
                        /*
                         * La cuenta donde está un depósito directo apartado. Las demás
                         * recepciones bancarias la toman del movimiento del extracto que
                         * las confirmó; esta no tiene movimiento: la plata ya estaba.
                         */
                        $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
                    });

                    DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_origin_check
            CHECK (origin IN ('received', 'opening', 'legacy'))");
                    DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_legacy_bank_account_check
            CHECK ((origin = 'legacy' AND medium = 'bank') = (bank_account_id IS NOT NULL))");

                    DB::statement("UPDATE fund_receipts SET origin = 'opening'
            WHERE financial_event_id IN (SELECT id FROM financial_events WHERE event_type = 'opening_balance')");

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_origin_matches_event() RETURNS trigger AS $$
            DECLARE
                v_tipo TEXT;
                v_esperado TEXT;
            BEGIN
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.financial_event_id;

                v_esperado := CASE v_tipo
                    WHEN 'opening_balance' THEN 'opening'
                    WHEN 'legacy_funds_allocated' THEN 'legacy'
                    ELSE 'received'
                END;

                IF NEW.origin IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'Una recepción de un evento % tiene origen %, no %.', v_tipo, v_esperado, NEW.origin
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_origin_matches_event
                BEFORE INSERT ON fund_receipts
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_origin_matches_event();

            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;
        SQL);
                }

                /**
                 * El asiento de apartar solo cambia de dueño al dinero.
                 *
                 * Débito de `LEGACY_FUNDS`, crédito de `BENEFICIARY_FUNDS` y nada más:
                 * una pata de caja o de banco movería plata que no se movió, y el
                 * arqueo dejaría de cerrar. Diferido, porque las líneas se escriben de
                 * a una.
                 */
                private function legacyEntryShape(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_allocation_shape(p_event_id bigint) RETURNS void AS $$
            DECLARE
                v_tipo TEXT;
                v_estado TEXT;
            BEGIN
                SELECT event_type, status INTO v_tipo, v_estado FROM financial_events WHERE id = p_event_id;

                IF v_tipo IS DISTINCT FROM 'legacy_funds_allocated' OR v_estado = 'draft' THEN
                    RETURN;
                END IF;

                IF EXISTS (
                    SELECT 1 FROM journal_lines
                     WHERE financial_event_id = p_event_id
                       AND NOT (
                           (account_code = 'LEGACY_FUNDS' AND debit > 0 AND credit = 0)
                           OR (account_code = 'BENEFICIARY_FUNDS' AND credit > 0 AND debit = 0)
                       )
                ) THEN
                    RAISE EXCEPTION 'Apartar fondos del sistema anterior solo debita LEGACY_FUNDS y acredita BENEFICIARY_FUNDS: no mueve el dinero de lugar.'
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION legacy_allocation_shape_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'journal_lines' THEN
                    PERFORM legacy_allocation_shape(NEW.financial_event_id);
                ELSE
                    PERFORM legacy_allocation_shape(NEW.id);
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_lines_legacy_allocation_shape
                AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION legacy_allocation_shape_trigger();

            CREATE CONSTRAINT TRIGGER financial_events_legacy_allocation_shape
                AFTER INSERT OR UPDATE OF status ON financial_events
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (NEW.event_type = 'legacy_funds_allocated')
                EXECUTE FUNCTION legacy_allocation_shape_trigger();
        SQL);
                }

                /**
                 * Cada recepción se asigna por su vía, y una cuota no mezcla las dos.
                 *
                 * - Lo que entró por el circuito se asigna con `funds_allocated`, que
                 *   debita `UNASSIGNED_FUNDS`. Lo del sistema anterior —un cheque de la
                 *   apertura o lo apartado— nunca estuvo ahí: se asigna con
                 *   `legacy_funds_allocated`, que debita `LEGACY_FUNDS`. Es lo que
                 *   cierra la puerta por la que hoy un cheque de la apertura se podía
                 *   asignar por el camino normal.
                 * - Una recepción `legacy` nace con su asignación y no admite otra:
                 *   liberada, la plata vuelve a `LEGACY_FUNDS` y la recepción queda
                 *   inerte, sin necesidad de marcarla revertida.
                 * - Una cuota con dinero vigente de una vía no recibe de la otra, y una
                 *   con recibo de ingreso de papel solo se financia con dinero del
                 *   sistema anterior: es lo que ese papel respalda.
                 *
                 * Bloquea la cuota antes de sumar, y la recepción antes de comparar con
                 * su importe: dos asignaciones simultáneas se ordenan y la segunda ve a
                 * la primera.
                 */
                private function allocationOrigin(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION allocation_respects_origin() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_tipo TEXT;
                v_anterior NUMERIC(19,2);
                v_actual NUMERIC(19,2);
            BEGIN
                IF NEW.allocation_kind = 'reversal' THEN
                    RETURN NEW;
                END IF;

                SELECT origin, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.allocation_event_id;

                IF v_recepcion.origin = 'received' AND v_tipo IS DISTINCT FROM 'funds_allocated' THEN
                    RAISE EXCEPTION 'Lo que entró por el circuito se asigna con funds_allocated, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_tipo IS DISTINCT FROM 'legacy_funds_allocated' THEN
                    RAISE EXCEPTION 'El dinero del sistema anterior se asigna apartándolo de LEGACY_FUNDS, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'legacy' AND NEW.allocation_event_id <> v_recepcion.financial_event_id THEN
                    RAISE EXCEPTION 'Una recepción apartada del sistema anterior no se vuelve a asignar.'
                        USING ERRCODE = 'check_violation';
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                SELECT
                    COALESCE(SUM(CASE WHEN r.origin IN ('opening', 'legacy')
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0),
                    COALESCE(SUM(CASE WHEN r.origin = 'received'
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0)
                  INTO v_anterior, v_actual
                  FROM funding_allocations a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                 WHERE a.beneficiary_installment_id = NEW.beneficiary_installment_id;

                IF v_recepcion.origin = 'received' AND v_anterior > 0 THEN
                    RAISE EXCEPTION 'La cuota está financiada con dinero del sistema anterior: no admite dinero actual.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_actual > 0 THEN
                    RAISE EXCEPTION 'La cuota tiene dinero actual asignado: no admite dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'received' AND EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo de ingreso de papel: solo se financia con dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER funding_allocations_respect_origin
                BEFORE INSERT ON funding_allocations
                FOR EACH ROW EXECUTE FUNCTION allocation_respects_origin();

            CREATE OR REPLACE FUNCTION allocation_within_receipt() RETURNS trigger AS $$
            DECLARE
                importe_recibido NUMERIC(19,2);
                total_asignado NUMERIC(19,2);
            BEGIN
                -- Bloqueada antes de sumar: dos asignaciones del mismo
                -- cheque no leen el mismo saldo libre.
                SELECT amount INTO importe_recibido
                FROM fund_receipts WHERE id = NEW.fund_receipt_id
                FOR UPDATE;

                SELECT COALESCE(SUM(
                    CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END
                ), 0)
                INTO total_asignado
                FROM funding_allocations
                WHERE fund_receipt_id = NEW.fund_receipt_id;

                IF total_asignado > importe_recibido THEN
                    RAISE EXCEPTION
                        'La recepcion es de % y ya tiene % asignados.',
                        importe_recibido, total_asignado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                /**
                 * El recibo de ingreso de papel, en lugar del del sistema.
                 *
                 * Una cuota apartada del sistema anterior ya tiene su recibo: el de
                 * talonario que se le dio al empleador. Emitirle otro del sistema sería
                 * un segundo recibo por el mismo dinero y le daría un número de hoy a un
                 * hecho de otro año.
                 *
                 * La Orden de Pago apunta a uno de los dos, nunca a ambos, e imprime el
                 * número del talonario. El papel no se anula mientras lo cite una Orden
                 * o respalde dinero apartado.
                 */
                private function paperIncomeReceipt(): void
                {
                    DB::statement('ALTER TABLE payment_orders ALTER COLUMN income_receipt_id DROP NOT NULL');

                    Schema::table('payment_orders', function (Blueprint $table): void {
                        $table->foreignId('legacy_income_document_id')->nullable()->constrained('legacy_documents')->restrictOnDelete();
                    });

                    DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_one_income_receipt_check
            CHECK ((income_receipt_id IS NULL) <> (legacy_income_document_id IS NULL))');
                    DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_paper_prints_talonario_check
            CHECK (legacy_income_document_id IS NULL OR income_receipt_number_source = 'talonario')");

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_orders_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una Orden de Pago no se borra: se anula y se emite la que la reemplaza.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.order_date IS DISTINCT FROM OLD.order_date
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.beneficiary_bank_account_id IS DISTINCT FROM OLD.beneficiary_bank_account_id
                    OR NEW.beneficiary_cbu_snapshot IS DISTINCT FROM OLD.beneficiary_cbu_snapshot
                    OR NEW.beneficiary_name_snapshot IS DISTINCT FROM OLD.beneficiary_name_snapshot
                    OR NEW.beneficiary_document_snapshot IS DISTINCT FROM OLD.beneficiary_document_snapshot
                    OR NEW.employer_name_snapshot IS DISTINCT FROM OLD.employer_name_snapshot
                    OR NEW.expediente_number_snapshot IS DISTINCT FROM OLD.expediente_number_snapshot
                    OR NEW.organism_bank_account_id IS DISTINCT FROM OLD.organism_bank_account_id
                    OR NEW.income_receipt_id IS DISTINCT FROM OLD.income_receipt_id
                    OR NEW.legacy_income_document_id IS DISTINCT FROM OLD.legacy_income_document_id
                    OR NEW.income_receipt_number_snapshot IS DISTINCT FROM OLD.income_receipt_number_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos impresos de una Orden no se editan: se anula y se emite otra.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION income_receipt_paper_or_system() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'receipts' THEN
                    IF NEW.receipt_type = 'income' AND NEW.beneficiary_installment_id IS NOT NULL AND EXISTS (
                        SELECT 1 FROM legacy_documents
                         WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                           AND kind = 'income_receipt' AND voided_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'La cuota ya tiene su recibo de ingreso de papel: no lleva uno del sistema.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.kind = 'income_receipt' AND EXISTS (
                    SELECT 1 FROM receipts
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND receipt_type = 'income' AND status = 'issued'
                ) THEN
                    RAISE EXCEPTION 'La cuota ya tiene un recibo de ingreso del sistema: no lleva uno de papel.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER receipts_income_paper_or_system
                BEFORE INSERT ON receipts
                FOR EACH ROW EXECUTE FUNCTION income_receipt_paper_or_system();

            CREATE TRIGGER legacy_documents_income_paper_or_system
                BEFORE INSERT ON legacy_documents
                FOR EACH ROW EXECUTE FUNCTION income_receipt_paper_or_system();

            CREATE OR REPLACE FUNCTION legacy_income_document_keeps_backing() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM payment_orders
                     WHERE legacy_income_document_id = OLD.id
                       AND status NOT IN ('voided', 'rejected')
                ) THEN
                    RAISE EXCEPTION 'El recibo de ingreso de papel lo cita una Orden de Pago vigente: primero hay que anular la Orden.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF (
                    SELECT COALESCE(SUM(CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END), 0)
                      FROM funding_allocations a
                      JOIN fund_receipts r ON r.id = a.fund_receipt_id
                     WHERE a.beneficiary_installment_id = OLD.beneficiary_installment_id
                       AND r.origin IN ('opening', 'legacy')
                ) > 0 THEN
                    RAISE EXCEPTION 'El recibo de ingreso de papel respalda dinero apartado: primero hay que liberarlo.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER legacy_documents_income_keeps_backing
                BEFORE UPDATE OF voided_at ON legacy_documents
                FOR EACH ROW
                WHEN (OLD.kind = 'income_receipt' AND OLD.voided_at IS NULL AND NEW.voided_at IS NOT NULL)
                EXECUTE FUNCTION legacy_income_document_keeps_backing();
        SQL);
                }

                public function down(): void
                {
                    if (DB::table('financial_events')->where('event_type', 'legacy_funds_allocated')->exists()) {
                        throw new RuntimeException('Hay fondos del sistema anterior apartados: no se puede volver atrás sin perder ese registro.');
                    }

                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS legacy_documents_income_keeps_backing ON legacy_documents;
            DROP FUNCTION IF EXISTS legacy_income_document_keeps_backing();
            DROP TRIGGER IF EXISTS legacy_documents_income_paper_or_system ON legacy_documents;
            DROP TRIGGER IF EXISTS receipts_income_paper_or_system ON receipts;
            DROP FUNCTION IF EXISTS income_receipt_paper_or_system();
            DROP TRIGGER IF EXISTS funding_allocations_respect_origin ON funding_allocations;
            DROP FUNCTION IF EXISTS allocation_respects_origin();
            DROP TRIGGER IF EXISTS financial_events_legacy_allocation_shape ON financial_events;
            DROP TRIGGER IF EXISTS journal_lines_legacy_allocation_shape ON journal_lines;
            DROP FUNCTION IF EXISTS legacy_allocation_shape_trigger();
            DROP FUNCTION IF EXISTS legacy_allocation_shape(bigint);
            DROP TRIGGER IF EXISTS fund_receipts_origin_matches_event ON fund_receipts;
            DROP FUNCTION IF EXISTS fund_receipts_origin_matches_event();
        SQL);

                    /*
                     * Las tres funciones reemplazadas vuelven a su versión anterior
                     * antes de sacar las columnas que las nuevas nombran.
                     */
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;

            CREATE OR REPLACE FUNCTION payment_orders_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una Orden de Pago no se borra: se anula y se emite la que la reemplaza.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.order_date IS DISTINCT FROM OLD.order_date
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.beneficiary_bank_account_id IS DISTINCT FROM OLD.beneficiary_bank_account_id
                    OR NEW.beneficiary_cbu_snapshot IS DISTINCT FROM OLD.beneficiary_cbu_snapshot
                    OR NEW.beneficiary_name_snapshot IS DISTINCT FROM OLD.beneficiary_name_snapshot
                    OR NEW.beneficiary_document_snapshot IS DISTINCT FROM OLD.beneficiary_document_snapshot
                    OR NEW.employer_name_snapshot IS DISTINCT FROM OLD.employer_name_snapshot
                    OR NEW.expediente_number_snapshot IS DISTINCT FROM OLD.expediente_number_snapshot
                    OR NEW.organism_bank_account_id IS DISTINCT FROM OLD.organism_bank_account_id
                    OR NEW.income_receipt_id IS DISTINCT FROM OLD.income_receipt_id
                    OR NEW.income_receipt_number_snapshot IS DISTINCT FROM OLD.income_receipt_number_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos impresos de una Orden no se editan: se anula y se emite otra.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION allocation_within_receipt() RETURNS trigger AS $$
            DECLARE
                importe_recibido NUMERIC(19,2);
                total_asignado NUMERIC(19,2);
            BEGIN
                SELECT amount INTO importe_recibido
                FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                SELECT COALESCE(SUM(
                    CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END
                ), 0)
                INTO total_asignado
                FROM funding_allocations
                WHERE fund_receipt_id = NEW.fund_receipt_id;

                IF total_asignado > importe_recibido THEN
                    RAISE EXCEPTION
                        'La recepcion es de % y ya tiene % asignados.',
                        importe_recibido, total_asignado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

                    DB::statement('ALTER TABLE payment_orders DROP CONSTRAINT IF EXISTS payment_orders_paper_prints_talonario_check');
                    DB::statement('ALTER TABLE payment_orders DROP CONSTRAINT IF EXISTS payment_orders_one_income_receipt_check');

                    Schema::table('payment_orders', function (Blueprint $table): void {
                        $table->dropConstrainedForeignId('legacy_income_document_id');
                    });

                    DB::statement('ALTER TABLE payment_orders ALTER COLUMN income_receipt_id SET NOT NULL');

                    DB::statement('ALTER TABLE fund_receipts DROP CONSTRAINT IF EXISTS fund_receipts_legacy_bank_account_check');
                    DB::statement('ALTER TABLE fund_receipts DROP CONSTRAINT IF EXISTS fund_receipts_origin_check');

                    Schema::table('fund_receipts', function (Blueprint $table): void {
                        $table->dropConstrainedForeignId('bank_account_id');
                        $table->dropColumn('origin');
                    });

                    DB::statement('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check');
                    DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
            CHECK (event_type IN (
                'funds_received', 'funds_allocated', 'cash_disbursement',
                'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                'cash_adjustment', 'opening_balance', 'legacy_disbursement',
                'reversal', 'authorized_adjustment'
            ))");
                }
            },

            /**
             * Un cheque del sistema anterior se asigna entero, y sigue en la cartera
             * aunque se libere.
             *
             * El cheque es un papel que se entrega o se deposita entero: no hay forma
             * de darle al beneficiario «$35.000 de un cheque de $40.000». Si se
             * repartiera entre cuotas, al entregar una se marcaría entregado el papel
             * completo y el libro conservaría el resto en `CHEQUES_IN_CUSTODY` sin
             * ningún cheque que lo respalde. Por eso, para la plata del sistema
             * anterior —cheques de la apertura o identificados al apartar—, lo
             * asignado de un cheque es **cero o el cheque entero**.
             *
             * Y un cheque identificado al apartar es un papel de la cartera, no un
             * importe de un solo uso: liberada su asignación, vuelve a poder apartarse
             * para otra cuota, igual que uno de la apertura. Lo que sigue siendo de un
             * solo uso es el efectivo y el depósito directo apartados, que son montos.
             *
             * La cuenta de un depósito directo apartado tiene que estar en la moneda
             * de la recepción: un depósito en pesos no está en una cuenta en dólares.
             */
            // Migración original: 2026_10_03_000000_keep_legacy_cheques_whole.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION allocation_respects_origin() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_tipo TEXT;
                v_anterior NUMERIC(19,2);
                v_actual NUMERIC(19,2);
            BEGIN
                IF NEW.allocation_kind = 'reversal' THEN
                    RETURN NEW;
                END IF;

                SELECT origin, medium, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.allocation_event_id;

                IF v_recepcion.origin = 'received' AND v_tipo IS DISTINCT FROM 'funds_allocated' THEN
                    RAISE EXCEPTION 'Lo que entró por el circuito se asigna con funds_allocated, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_tipo IS DISTINCT FROM 'legacy_funds_allocated' THEN
                    RAISE EXCEPTION 'El dinero del sistema anterior se asigna apartándolo de LEGACY_FUNDS, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                -- El efectivo y el depósito apartados son montos de un solo uso;
                -- un cheque es un papel de la cartera y se puede volver a apartar.
                IF v_recepcion.origin = 'legacy'
                    AND v_recepcion.medium <> 'cheque'
                    AND NEW.allocation_event_id <> v_recepcion.financial_event_id THEN
                    RAISE EXCEPTION 'Una recepción apartada del sistema anterior no se vuelve a asignar.'
                        USING ERRCODE = 'check_violation';
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                SELECT
                    COALESCE(SUM(CASE WHEN r.origin IN ('opening', 'legacy')
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0),
                    COALESCE(SUM(CASE WHEN r.origin = 'received'
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0)
                  INTO v_anterior, v_actual
                  FROM funding_allocations a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                 WHERE a.beneficiary_installment_id = NEW.beneficiary_installment_id;

                IF v_recepcion.origin = 'received' AND v_anterior > 0 THEN
                    RAISE EXCEPTION 'La cuota está financiada con dinero del sistema anterior: no admite dinero actual.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_actual > 0 THEN
                    RAISE EXCEPTION 'La cuota tiene dinero actual asignado: no admite dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'received' AND EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo de ingreso de papel: solo se financia con dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION legacy_cheque_stays_whole() RETURNS trigger AS $$
            DECLARE
                v_cheque RECORD;
                v_asignado NUMERIC(19,2);
            BEGIN
                SELECT id, amount, cheque_number INTO v_cheque
                  FROM fund_receipts
                 WHERE id = NEW.fund_receipt_id
                   AND medium = 'cheque'
                   AND origin IN ('opening', 'legacy');

                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END), 0)
                  INTO v_asignado
                  FROM funding_allocations
                 WHERE fund_receipt_id = v_cheque.id;

                IF v_asignado <> 0 AND v_asignado <> v_cheque.amount THEN
                    RAISE EXCEPTION 'El cheque % es de % y quedaría asignado por %: un cheque del sistema anterior se asigna y se libera entero.',
                        v_cheque.cheque_number, v_cheque.amount, v_asignado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER funding_allocations_legacy_cheque_whole
                AFTER INSERT ON funding_allocations
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION legacy_cheque_stays_whole();

            CREATE OR REPLACE FUNCTION fund_receipts_bank_account_currency() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM bank_accounts
                     WHERE id = NEW.bank_account_id AND currency = NEW.currency
                ) THEN
                    RAISE EXCEPTION 'La cuenta % no es de la moneda de la recepción (%).', NEW.bank_account_id, NEW.currency
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_bank_account_currency
                BEFORE INSERT ON fund_receipts
                FOR EACH ROW
                WHEN (NEW.bank_account_id IS NOT NULL)
                EXECUTE FUNCTION fund_receipts_bank_account_currency();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fund_receipts_bank_account_currency ON fund_receipts;
            DROP FUNCTION IF EXISTS fund_receipts_bank_account_currency();
            DROP TRIGGER IF EXISTS funding_allocations_legacy_cheque_whole ON funding_allocations;
            DROP FUNCTION IF EXISTS legacy_cheque_stays_whole();

            CREATE OR REPLACE FUNCTION allocation_respects_origin() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_tipo TEXT;
                v_anterior NUMERIC(19,2);
                v_actual NUMERIC(19,2);
            BEGIN
                IF NEW.allocation_kind = 'reversal' THEN
                    RETURN NEW;
                END IF;

                SELECT origin, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.allocation_event_id;

                IF v_recepcion.origin = 'received' AND v_tipo IS DISTINCT FROM 'funds_allocated' THEN
                    RAISE EXCEPTION 'Lo que entró por el circuito se asigna con funds_allocated, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_tipo IS DISTINCT FROM 'legacy_funds_allocated' THEN
                    RAISE EXCEPTION 'El dinero del sistema anterior se asigna apartándolo de LEGACY_FUNDS, no con %.', v_tipo
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'legacy' AND NEW.allocation_event_id <> v_recepcion.financial_event_id THEN
                    RAISE EXCEPTION 'Una recepción apartada del sistema anterior no se vuelve a asignar.'
                        USING ERRCODE = 'check_violation';
                END IF;

                PERFORM 1 FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id FOR UPDATE;

                SELECT
                    COALESCE(SUM(CASE WHEN r.origin IN ('opening', 'legacy')
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0),
                    COALESCE(SUM(CASE WHEN r.origin = 'received'
                        THEN CASE WHEN a.allocation_kind = 'reversal' THEN -a.amount ELSE a.amount END END), 0)
                  INTO v_anterior, v_actual
                  FROM funding_allocations a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                 WHERE a.beneficiary_installment_id = NEW.beneficiary_installment_id;

                IF v_recepcion.origin = 'received' AND v_anterior > 0 THEN
                    RAISE EXCEPTION 'La cuota está financiada con dinero del sistema anterior: no admite dinero actual.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin IN ('opening', 'legacy') AND v_actual > 0 THEN
                    RAISE EXCEPTION 'La cuota tiene dinero actual asignado: no admite dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.origin = 'received' AND EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo de ingreso de papel: solo se financia con dinero del sistema anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }
            },

            /**
             * El cheque sigue al traslado, y cada línea va en la moneda de lo que mueve.
             *
             * **El estado del cheque.** Depositar un cheque lo sacaba de
             * `CHEQUES_IN_CUSTODY` en el libro pero lo dejaba `in_custody` en
             * `fund_receipts`: la planilla de caja lo seguía listando, y lo que el
             * inventario llama «sin detallar» quedaba corto. Ahora el estado se ata al
             * traslado vigente que lo llevó: `deposited` mientras está en tránsito,
             * `cleared` cuando el extracto lo acredita, y de vuelta en custodia si el
             * traslado se cancela. Los cheques ya depositados se corrigen acá.
             *
             * **La moneda.** Varias operaciones escribían sus líneas sin moneda, y
             * `EntryLine` cae en pesos. Con un haber en dólares eso partía el libro:
             * `BENEFICIARY_FUNDS` en pesos para una cuota en dólares. Se exige en la
             * base que una línea con cuota vaya en la moneda del haber, y que las
             * líneas del asiento de una recepción vayan en la moneda de la recepción,
             * que además pasa a ser inmutable.
             */
            // Migración original: 2026_10_03_010000_keep_cheque_status_and_currency_coherent.php
            new class extends Migration
            {
                public function up(): void
                {
                    $this->assertCurrenciesAlreadyCoherent();
                    $this->backfillChequeStatus();

                    $this->chequeFollowsTransfer();
                    $this->lineCurrencyFollowsHaber();
                    $this->receiptCurrencyFollowsEvent();
                    $this->freezeReceiptCurrency();
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cheque_follows_transfer_item ON cash_to_bank_transfer_items;
            DROP TRIGGER IF EXISTS cheque_follows_transfer_status ON cash_to_bank_transfers;
            DROP TRIGGER IF EXISTS cheque_follows_transfer_receipt ON fund_receipts;
            DROP FUNCTION IF EXISTS cheque_follows_transfer_from_item();
            DROP FUNCTION IF EXISTS cheque_follows_transfer_from_transfer();
            DROP FUNCTION IF EXISTS cheque_follows_transfer_from_receipt();
            DROP FUNCTION IF EXISTS cheque_follows_transfer(bigint);

            DROP TRIGGER IF EXISTS journal_lines_installment_currency ON journal_lines;
            DROP FUNCTION IF EXISTS journal_lines_installment_currency();

            DROP TRIGGER IF EXISTS fund_receipts_currency_matches_event ON fund_receipts;
            DROP FUNCTION IF EXISTS fund_receipts_currency_matches_event();

            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;
        SQL);
                }

                /**
                 * Las líneas ya escritas no se pueden corregir —son append-only—, así
                 * que si alguna ya contradice la regla la migración se detiene y dice
                 * cuántas, en vez de crear una guarda que el libro ya incumple.
                 */
                private function assertCurrenciesAlreadyCoherent(): void
                {
                    $deCuota = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM journal_lines jl
              JOIN haberes h ON h.id = jl.haber_id
             WHERE jl.currency <> h.currency
        SQL);

                    $deRecepcion = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM fund_receipts fr
              JOIN journal_lines jl ON jl.financial_event_id = fr.financial_event_id
             WHERE jl.currency <> fr.currency
        SQL);

                    if ($deCuota > 0 || $deRecepcion > 0) {
                        throw new RuntimeException(sprintf(
                            'Hay líneas en una moneda distinta de la de su haber (%d) o de su recepción (%d). '
                            .'Hay que revisarlas antes de crear la guarda.',
                            $deCuota,
                            $deRecepcion,
                        ));
                    }
                }

                /**
                 * Los cheques que ya viajaron en un traslado vigente toman el estado
                 * de ese traslado. `cheque_status` es la única columna de la recepción
                 * que se mueve, así que el append-only lo permite.
                 */
                private function backfillChequeStatus(): void
                {
                    DB::statement(<<<'SQL'
            UPDATE fund_receipts fr
               SET cheque_status = CASE t.status
                                       WHEN 'deposited' THEN 'deposited'
                                       ELSE 'cleared'
                                   END
              FROM cash_to_bank_transfer_items i
              JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
             WHERE i.fund_receipt_id = fr.id
               AND fr.medium = 'cheque'
               AND t.status IN ('deposited', 'bank_confirmed')
        SQL);
                }

                /**
                 * El estado del cheque es el de su traslado vigente.
                 *
                 * Con uno vigente, `deposited` o `cleared` según si el extracto ya lo
                 * acreditó; sin ninguno, no puede figurar en el banco. Se controla
                 * desde los tres lados que pueden romperlo —el ítem nuevo, el cambio de
                 * estado del traslado y el cambio de estado del cheque—, diferido
                 * porque las tres escrituras ocurren en la misma transacción y en
                 * cualquier orden.
                 */
                private function chequeFollowsTransfer(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cheque_follows_transfer(p_receipt_id bigint) RETURNS void AS $$
            DECLARE
                v_cheque RECORD;
                v_esperado TEXT;
            BEGIN
                SELECT medium, cheque_status, cheque_number INTO v_cheque
                  FROM fund_receipts WHERE id = p_receipt_id;

                IF v_cheque.medium IS DISTINCT FROM 'cheque' THEN
                    RETURN;
                END IF;

                SELECT CASE t.status WHEN 'deposited' THEN 'deposited' ELSE 'cleared' END
                  INTO v_esperado
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = p_receipt_id
                   AND t.status <> 'cancelled'
                 LIMIT 1;

                IF v_esperado IS NOT NULL AND v_cheque.cheque_status IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'El cheque % viajó en un traslado vigente: tiene que figurar %, no %.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_esperado, v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_esperado IS NULL AND v_cheque.cheque_status IN ('deposited', 'cleared') THEN
                    RAISE EXCEPTION 'El cheque % figura % sin ningún traslado vigente que lo haya llevado al banco.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cheque_follows_transfer_from_item() RETURNS trigger AS $$
            BEGIN
                PERFORM cheque_follows_transfer(NEW.fund_receipt_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cheque_follows_transfer_from_transfer() RETURNS trigger AS $$
            DECLARE
                v_recepcion bigint;
            BEGIN
                FOR v_recepcion IN
                    SELECT fund_receipt_id FROM cash_to_bank_transfer_items
                     WHERE cash_to_bank_transfer_id = NEW.id
                LOOP
                    PERFORM cheque_follows_transfer(v_recepcion);
                END LOOP;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cheque_follows_transfer_from_receipt() RETURNS trigger AS $$
            BEGIN
                PERFORM cheque_follows_transfer(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_item
                AFTER INSERT ON cash_to_bank_transfer_items
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_item();

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_status
                AFTER UPDATE OF status ON cash_to_bank_transfers
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_transfer();

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_receipt
                AFTER UPDATE OF cheque_status ON fund_receipts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_receipt();
        SQL);
                }

                /**
                 * Una línea con cuota va en la moneda del haber. Es la que se rompía:
                 * el asiento cuadra por moneda igual, así que una cuota en dólares con
                 * `BENEFICIARY_FUNDS` en pesos pasaba sin que nada protestara.
                 */
                private function lineCurrencyFollowsHaber(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_installment_currency() RETURNS trigger AS $$
            DECLARE
                v_moneda char(3);
            BEGIN
                SELECT currency INTO v_moneda FROM haberes WHERE id = NEW.haber_id;

                IF NEW.currency IS DISTINCT FROM v_moneda THEN
                    RAISE EXCEPTION 'La línea es en % y el haber % es en %.', NEW.currency, NEW.haber_id, v_moneda
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_installment_currency
                BEFORE INSERT ON journal_lines
                FOR EACH ROW
                WHEN (NEW.haber_id IS NOT NULL)
                EXECUTE FUNCTION journal_lines_installment_currency();
        SQL);
                }

                /**
                 * Las líneas del asiento de una recepción van en la moneda de la
                 * recepción. Es lo que permite que el resto —asignar, desasignar,
                 * revertir— tome la moneda de la recepción sin volver a preguntarla.
                 * Diferido: el asiento se escribe antes que la fila de la recepción.
                 */
                private function receiptCurrencyFollowsEvent(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_currency_matches_event() RETURNS trigger AS $$
            DECLARE
                v_otra char(3);
            BEGIN
                SELECT currency INTO v_otra
                  FROM journal_lines
                 WHERE financial_event_id = NEW.financial_event_id
                   AND currency <> NEW.currency
                 LIMIT 1;

                IF v_otra IS NOT NULL THEN
                    RAISE EXCEPTION 'La recepción es en % y su asiento tiene líneas en %.', NEW.currency, v_otra
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fund_receipts_currency_matches_event
                AFTER INSERT ON fund_receipts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_currency_matches_event();
        SQL);
                }

                /** La moneda se suma a los datos de la recepción que no se editan. */
                private function freezeReceiptCurrency(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.currency IS DISTINCT FROM OLD.currency
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;
        SQL);
                }
            },

            /**
             * Un cheque se deposita como llegó a la Secretaría: entero y de una vez.
             *
             * El traslado de una cuota que tiene solo una parte de un cheque lleva el
             * papel completo: la parte de la cuota, la de otra cuota que lo comparte y
             * la que todavía no tiene dueño. Lo que la base garantiza es el resultado:
             * entre los traslados vigentes, los ítems de un cheque suman cero o el
             * cheque entero, y están todos en el mismo traslado.
             *
             * Diferido porque los ítems de un traslado se escriben de a uno. Solo
             * mira la inserción de ítems: son inmutables, y lo único que le pasa
             * después a un traslado es cancelarse, que saca todos sus ítems a la vez.
             */
            // Migración original: 2026_10_03_020000_keep_deposited_cheques_whole.php
            new class extends Migration
            {
                public function up(): void
                {
                    $partidos = (int) DB::scalar(<<<'SQL'
            SELECT count(*) FROM (
                SELECT i.fund_receipt_id
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                  JOIN fund_receipts fr ON fr.id = i.fund_receipt_id
                 WHERE t.status <> 'cancelled'
                   AND fr.medium = 'cheque'
                 GROUP BY i.fund_receipt_id, fr.amount
                HAVING count(DISTINCT t.id) > 1 OR sum(i.amount) <> fr.amount
            ) AS partidos
        SQL);

                    if ($partidos > 0) {
                        throw new RuntimeException(sprintf(
                            'Hay %d cheques depositados en parte o en más de un traslado. Hay que revisarlos antes de crear la guarda.',
                            $partidos,
                        ));
                    }

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cheque_travels_whole() RETURNS trigger AS $$
            DECLARE
                v_cheque RECORD;
                v_traslados integer;
                v_viaja NUMERIC(19,2);
            BEGIN
                SELECT medium, amount, cheque_number INTO v_cheque
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                IF v_cheque.medium IS DISTINCT FROM 'cheque' THEN
                    RETURN NULL;
                END IF;

                SELECT count(DISTINCT t.id), coalesce(sum(i.amount), 0)
                  INTO v_traslados, v_viaja
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = NEW.fund_receipt_id
                   AND t.status <> 'cancelled';

                IF v_traslados > 1 THEN
                    RAISE EXCEPTION 'El cheque % ya viaja en otro traslado vigente.',
                        coalesce(v_cheque.cheque_number, '(sin número)')
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_viaja <> 0 AND v_viaja <> v_cheque.amount THEN
                    RAISE EXCEPTION 'El cheque % es de % y el traslado lleva %: un cheque se deposita entero.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.amount, v_viaja
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cheque_travels_whole
                AFTER INSERT ON cash_to_bank_transfer_items
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_travels_whole();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cheque_travels_whole ON cash_to_bank_transfer_items;
            DROP FUNCTION IF EXISTS cheque_travels_whole();
        SQL);
                }
            },

            /**
             * Del sistema anterior no se aparta ni se paga más de lo que quedaba en ese lugar.
             *
             * `LEGACY_FUNDS` es un saldo único para el efectivo, los cheques y el
             * banco, y el cajón y la cuenta mezclan plata vieja con la que entró
             * después. Controlar «hay plata en el cajón» y «queda saldo del sistema
             * anterior» por separado dejaba pasar esto: el cajón alcanza por un cobro
             * de hoy —de otro beneficiario— y el saldo viejo alcanza por la plata que
             * estaba en el banco. Se apartaba como efectivo viejo un efectivo nuevo.
             *
             * Ahora se lleva lo que queda **por lugar**, sin columnas nuevas, porque
             * cada hecho ya dice de dónde es:
             *
             * - la apertura y los pagos desde Pagos anteriores tienen la línea del
             *   lugar —efectivo, cheques o la cuenta bancaria— con sus reversiones;
             * - un apartado de efectivo o de depósito directo deja una recepción
             *   `legacy` con su medio y su cuenta, y liberarlo revierte su asiento.
             *
             * ```text
             * queda en un lugar = lo que la apertura declaró ahí
             *                   − lo pagado desde Pagos anteriores con ese lugar
             *                   − lo apartado de ahí, neto de lo liberado
             * ```
             *
             * Los cheques no entran: cada uno es un papel con número y ya se controla
             * de a uno (`legacy_cheque_stays_whole`, lo sin detallar). Lo que queda en
             * cheques es el resto: el saldo total menos el efectivo y las cuentas.
             *
             * El control vive en la función que ya protegía el total, así que corre en
             * los mismos momentos —al debitar `LEGACY_FUNDS`, diferido, y al postear un
             * borrador— y bajo el mismo bloqueo por caja y moneda.
             */
            // Migración original: 2026_10_06_000000_keep_legacy_funds_by_place.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_at(
                p_cash_box_id bigint,
                p_currency char(3),
                p_account text,
                p_bank_account_id bigint
            ) RETURNS numeric AS $$
            DECLARE
                v_declarado NUMERIC(19,2);
                v_apartado NUMERIC(19,2);
            BEGIN
                -- La apertura suma en el lugar y el pago desde Pagos anteriores
                -- resta; una reversión cuenta como el evento que revierte.
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  INTO v_declarado
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                  LEFT JOIN financial_events original ON original.id = fe.reversal_of_id
                 WHERE jl.account_code = p_account
                   AND jl.bank_account_id IS NOT DISTINCT FROM p_bank_account_id
                   AND jl.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft'
                   AND COALESCE(original.event_type, fe.event_type) IN ('opening_balance', 'legacy_disbursement');

                -- Lo apartado de ahí: el débito a LEGACY_FUNDS del apartado,
                -- menos lo que devolvieron las liberaciones que lo revierten.
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  INTO v_apartado
                  FROM fund_receipts r
                  JOIN financial_events fe
                    ON fe.id = r.financial_event_id OR fe.reversal_of_id = r.financial_event_id
                  JOIN journal_lines jl
                    ON jl.financial_event_id = fe.id AND jl.account_code = 'LEGACY_FUNDS'
                 WHERE r.origin = 'legacy'
                   AND r.medium = CASE p_account WHEN 'CASH_ON_HAND' THEN 'cash' WHEN 'BANK_ACCOUNT' THEN 'bank' END
                   AND r.bank_account_id IS NOT DISTINCT FROM p_bank_account_id
                   AND r.cash_box_id = p_cash_box_id
                   AND r.currency = p_currency
                   AND fe.status <> 'draft';

                RETURN v_declarado - v_apartado;
            END;
            $$ LANGUAGE plpgsql STABLE;

            -- Las cuentas bancarias donde hubo plata del sistema anterior.
            CREATE OR REPLACE FUNCTION legacy_funds_bank_accounts(p_cash_box_id bigint, p_currency char(3))
            RETURNS SETOF bigint AS $$
                SELECT DISTINCT jl.bank_account_id
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                  LEFT JOIN financial_events original ON original.id = fe.reversal_of_id
                 WHERE jl.account_code = 'BANK_ACCOUNT'
                   AND jl.bank_account_id IS NOT NULL
                   AND jl.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
                   AND COALESCE(original.event_type, fe.event_type) IN ('opening_balance', 'legacy_disbursement')
                UNION
                SELECT r.bank_account_id
                  FROM fund_receipts r
                 WHERE r.origin = 'legacy'
                   AND r.medium = 'bank'
                   AND r.cash_box_id = p_cash_box_id
                   AND r.currency = p_currency;
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_funds_places_check(p_cash_box_id bigint, p_currency char(3))
            RETURNS void AS $$
            DECLARE
                v_cuenta bigint;
                v_queda NUMERIC(19,2);
            BEGIN
                v_queda := legacy_funds_at(p_cash_box_id, p_currency, 'CASH_ON_HAND', NULL);

                IF v_queda < 0 THEN
                    RAISE EXCEPTION
                        'Del sistema anterior no queda tanto en efectivo: quedaria en %.', v_queda
                        USING ERRCODE = 'check_violation';
                END IF;

                FOR v_cuenta IN SELECT legacy_funds_bank_accounts(p_cash_box_id, p_currency) LOOP
                    v_queda := legacy_funds_at(p_cash_box_id, p_currency, 'BANK_ACCOUNT', v_cuenta);

                    IF v_queda < 0 THEN
                        RAISE EXCEPTION
                            'Del sistema anterior no queda tanto en la cuenta %: quedaria en %.', v_cuenta, v_queda
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

                    $this->assertExistingPlacesNonNegative();

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_balance_check(p_cash_box_id bigint, p_currency char(3))
            RETURNS void AS $$
            DECLARE
                v_saldo NUMERIC(19,2);
            BEGIN
                PERFORM pg_advisory_xact_lock(
                    hashtext('sacst.legacy_funds.' || p_currency),
                    COALESCE(p_cash_box_id, 0)::int
                );

                SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0)
                  INTO v_saldo
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE jl.account_code = 'LEGACY_FUNDS'
                   AND jl.cash_box_id IS NOT DISTINCT FROM p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft';

                IF v_saldo < 0 THEN
                    RAISE EXCEPTION
                        'Del sistema anterior no queda tanto por pagar: el saldo en % quedaria en %.',
                        p_currency, v_saldo
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Y en cada lugar: el total no alcanza si la plata vieja está en otro.
                IF p_cash_box_id IS NOT NULL THEN
                    PERFORM legacy_funds_places_check(p_cash_box_id, p_currency);
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_balance_check(p_cash_box_id bigint, p_currency char(3))
            RETURNS void AS $$
            DECLARE
                v_saldo NUMERIC(19,2);
            BEGIN
                PERFORM pg_advisory_xact_lock(
                    hashtext('sacst.legacy_funds.' || p_currency),
                    COALESCE(p_cash_box_id, 0)::int
                );

                SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0)
                  INTO v_saldo
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE jl.account_code = 'LEGACY_FUNDS'
                   AND jl.cash_box_id IS NOT DISTINCT FROM p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft';

                IF v_saldo < 0 THEN
                    RAISE EXCEPTION
                        'Del sistema anterior no queda tanto por pagar: el saldo en % quedaria en %.',
                        p_currency, v_saldo
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS legacy_funds_places_check(bigint, char);
            DROP FUNCTION IF EXISTS legacy_funds_bank_accounts(bigint, char);
            DROP FUNCTION IF EXISTS legacy_funds_at(bigint, char, text, bigint);
        SQL);
                }

                /**
                 * Lo ya registrado no se corrige —el libro es append-only—: si algún
                 * lugar ya quedó en negativo, la migración se detiene y lo nombra.
                 */
                private function assertExistingPlacesNonNegative(): void
                {
                    $negativos = DB::scalar(<<<'SQL'
            SELECT string_agg(format('caja %s en %s, %s: %s', caja, moneda, lugar, queda), '; ')
              FROM (
                SELECT c.caja, c.moneda, 'efectivo' AS lugar,
                       legacy_funds_at(c.caja, c.moneda, 'CASH_ON_HAND', NULL) AS queda
                  FROM (SELECT DISTINCT cash_box_id AS caja, currency AS moneda
                          FROM journal_lines
                         WHERE account_code = 'LEGACY_FUNDS' AND cash_box_id IS NOT NULL) c
                UNION ALL
                SELECT c.caja, c.moneda, 'cuenta ' || cuenta,
                       legacy_funds_at(c.caja, c.moneda, 'BANK_ACCOUNT', cuenta)
                  FROM (SELECT DISTINCT cash_box_id AS caja, currency AS moneda
                          FROM journal_lines
                         WHERE account_code = 'LEGACY_FUNDS' AND cash_box_id IS NOT NULL) c,
                       LATERAL legacy_funds_bank_accounts(c.caja, c.moneda) AS cuenta
              ) AS lugares
             WHERE queda < 0
        SQL);

                    if (is_string($negativos)) {
                        throw new RuntimeException(
                            'El saldo del sistema anterior ya es negativo en algún lugar y la guarda no se puede crear: '.$negativos
                        );
                    }
                }
            },

            /**
             * Se retira el pago suelto de casos del sistema anterior.
             *
             * «Pagos anteriores» pagaba un caso viejo sin expediente, haber ni cuota:
             * una persona, un importe y una referencia en texto libre que nadie
             * verificaba. Con la carga de expedientes históricos el mismo pago tiene
             * un camino trazable —cargar el expediente, reservar la plata para la
             * cuota y pagarla por el circuito—, y el suelto quedó como una segunda
             * vía sin controles por la que el mismo caso podía pagarse dos veces.
             *
             * No se usó: ni en producción ni en desarrollo hay pagos sueltos ni cuotas
             * vinculadas a ellos, y la migración se detiene si encuentra alguno. Se
             * retira entero, de la base también:
             *
             * - el tipo de evento `legacy_disbursement`;
             * - la modalidad de pago histórico «desde Pagos anteriores», con su
             *   columna del recibo vinculado y las guardas del vínculo;
             * - su término en el saldo del sistema anterior por lugar.
             *
             * Una cuota histórica pagada es ahora solo la pagada antes de la apertura,
             * así que su fecha y su medio de pago pasan a ser obligatorios.
             */
            // Migración original: 2026_10_07_000000_remove_loose_legacy_payments.php
            new class extends Migration
            {
                public function up(): void
                {
                    $this->assertNothingToRemove();

                    $this->dropReceiptLink();
                    $this->settlementsWithoutMode();
                    $this->eventTypes();
                    $this->legacyFundsByPlace();
                }

                /**
                 * Sin vuelta atrás desde acá: el código que usaba esto también se fue,
                 * y reponer solo el esquema dejaría columnas y tipos que nada escribe.
                 */
                public function down(): void
                {
                    throw new RuntimeException(
                        'El retiro de los pagos sueltos del sistema anterior no se revierte con una migración: '
                        .'habría que reponer también el código que los registraba.'
                    );
                }

                private function assertNothingToRemove(): void
                {
                    $pagos = (int) DB::scalar("SELECT count(*) FROM financial_events WHERE event_type = 'legacy_disbursement'");
                    $vinculos = (int) DB::scalar("SELECT count(*) FROM legacy_settlements WHERE mode <> 'before_opening'");

                    if ($pagos > 0 || $vinculos > 0) {
                        throw new RuntimeException(sprintf(
                            'Hay %d pagos sueltos del sistema anterior y %d cuotas vinculadas a ellos: no se pueden retirar sin perderlos.',
                            $pagos,
                            $vinculos,
                        ));
                    }
                }

                private function dropReceiptLink(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS legacy_settlements_receipt_link ON legacy_settlements;
            DROP FUNCTION IF EXISTS legacy_settlement_receipt_link();
            DROP TRIGGER IF EXISTS receipts_keep_legacy_settlement_links ON receipts;
            DROP FUNCTION IF EXISTS receipts_keep_legacy_settlement_links();
        SQL);
                }

                /**
                 * Una sola modalidad no necesita columna: la cuota se pagó antes de la
                 * apertura, con su fecha y su medio. La coherencia pierde la regla del
                 * recibo de egreso, que era solo de la modalidad que se va.
                 */
                private function settlementsWithoutMode(): void
                {
                    DB::statement('ALTER TABLE legacy_settlements DROP CONSTRAINT legacy_settlements_mode_fields_check');
                    DB::statement('ALTER TABLE legacy_settlements DROP CONSTRAINT legacy_settlements_mode_check');

                    Schema::table('legacy_settlements', function ($table): void {
                        $table->dropIndex(['legacy_disbursement_receipt_id']);
                        $table->dropConstrainedForeignId('legacy_disbursement_receipt_id');
                        $table->dropColumn('mode');
                    });

                    DB::statement('ALTER TABLE legacy_settlements ALTER COLUMN paid_on SET NOT NULL');
                    DB::statement('ALTER TABLE legacy_settlements ALTER COLUMN payment_medium SET NOT NULL');

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_settlement_coherence(p_installment_id bigint) RETURNS void AS $$
            DECLARE
                v_status TEXT;
                v_expected NUMERIC(19,2);
                v_registro RECORD;
            BEGIN
                SELECT workflow_status, expected_amount INTO v_status, v_expected
                  FROM beneficiary_installments WHERE id = p_installment_id;

                IF NOT FOUND THEN
                    RETURN;
                END IF;

                SELECT id, amount INTO v_registro
                  FROM legacy_settlements
                 WHERE beneficiary_installment_id = p_installment_id AND voided_at IS NULL;

                IF v_status = 'legacy_settled' AND v_registro.id IS NULL THEN
                    RAISE EXCEPTION 'La cuota % figura pagada fuera del circuito sin un registro que lo respalde.', p_installment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_status <> 'legacy_settled' AND v_registro.id IS NOT NULL THEN
                    RAISE EXCEPTION 'La cuota % tiene registrado un pago fuera del circuito: primero hay que anular ese registro.', p_installment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_registro.id IS NULL THEN
                    RETURN;
                END IF;

                IF v_expected <> v_registro.amount THEN
                    RAISE EXCEPTION 'El importe de la cuota % no puede cambiar: se dio por pagada con %.', p_installment_id, v_registro.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM legacy_documents
                     WHERE beneficiary_installment_id = p_installment_id
                       AND kind = 'income_receipt' AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'Una cuota pagada fuera del circuito necesita su recibo de ingreso de papel.'
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                private function eventTypes(): void
                {
                    DB::statement('ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check');
                    DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
            CHECK (event_type IN (
                'funds_received', 'funds_allocated', 'cash_disbursement',
                'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                'cash_adjustment', 'opening_balance',
                'reversal', 'authorized_adjustment', 'legacy_funds_allocated'
            ))");
                }

                /**
                 * Lo que queda del sistema anterior en un lugar ya no resta pagos
                 * sueltos: solo la apertura, y lo reservado neto de lo liberado.
                 */
                private function legacyFundsByPlace(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_at(
                p_cash_box_id bigint,
                p_currency char(3),
                p_account text,
                p_bank_account_id bigint
            ) RETURNS numeric AS $$
            DECLARE
                v_declarado NUMERIC(19,2);
                v_apartado NUMERIC(19,2);
            BEGIN
                -- La apertura suma en el lugar; una reversión cuenta como el
                -- evento que revierte.
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  INTO v_declarado
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                  LEFT JOIN financial_events original ON original.id = fe.reversal_of_id
                 WHERE jl.account_code = p_account
                   AND jl.bank_account_id IS NOT DISTINCT FROM p_bank_account_id
                   AND jl.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft'
                   AND COALESCE(original.event_type, fe.event_type) = 'opening_balance';

                -- Lo reservado de ahí: el débito a LEGACY_FUNDS del apartado,
                -- menos lo que devolvieron las liberaciones que lo revierten.
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  INTO v_apartado
                  FROM fund_receipts r
                  JOIN financial_events fe
                    ON fe.id = r.financial_event_id OR fe.reversal_of_id = r.financial_event_id
                  JOIN journal_lines jl
                    ON jl.financial_event_id = fe.id AND jl.account_code = 'LEGACY_FUNDS'
                 WHERE r.origin = 'legacy'
                   AND r.medium = CASE p_account WHEN 'CASH_ON_HAND' THEN 'cash' WHEN 'BANK_ACCOUNT' THEN 'bank' END
                   AND r.bank_account_id IS NOT DISTINCT FROM p_bank_account_id
                   AND r.cash_box_id = p_cash_box_id
                   AND r.currency = p_currency
                   AND fe.status <> 'draft';

                RETURN v_declarado - v_apartado;
            END;
            $$ LANGUAGE plpgsql STABLE;

            CREATE OR REPLACE FUNCTION legacy_funds_bank_accounts(p_cash_box_id bigint, p_currency char(3))
            RETURNS SETOF bigint AS $$
                SELECT DISTINCT jl.bank_account_id
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                  LEFT JOIN financial_events original ON original.id = fe.reversal_of_id
                 WHERE jl.account_code = 'BANK_ACCOUNT'
                   AND jl.bank_account_id IS NOT NULL
                   AND jl.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
                   AND COALESCE(original.event_type, fe.event_type) = 'opening_balance'
                UNION
                SELECT r.bank_account_id
                  FROM fund_receipts r
                 WHERE r.origin = 'legacy'
                   AND r.medium = 'bank'
                   AND r.cash_box_id = p_cash_box_id
                   AND r.currency = p_currency;
            $$ LANGUAGE sql STABLE;
        SQL);
                }
            },

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
            // Migración original: 2026_10_08_000000_create_cash_book_openings_table.php
            new class extends Migration
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
            },

            /**
             * El corte de los papeles históricos sale de la apertura, no de su asiento.
             *
             * `haberes_opening_date()` buscaba el asiento `opening_balance`, y la guarda
             * que impide abrir después de un papel ya cargado colgaba del mismo
             * asiento. Desde `cash_book_openings` la apertura puede existir sin
             * asiento —se abre declarando que no había nada—, y en ese caso la carga
             * histórica respondía «la caja todavía no tiene apertura» aunque la
             * tuviera, y la guarda no se disparaba.
             *
             * Las dos pasan a mirar la fila de apertura. Para los datos existentes no
             * cambia nada: cada apertura con asiento tiene su fila, con la misma fecha.
             */
            // Migración original: 2026_10_08_010000_read_legacy_cutoff_from_cash_book_openings.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(o.opened_on)
                  FROM cash_book_openings o
                  JOIN cash_boxes cb ON cb.id = o.cash_box_id
                 WHERE cb.code = 'haberes'
            $$ LANGUAGE sql STABLE;

            DROP TRIGGER IF EXISTS financial_events_opening_after_legacy_papers ON financial_events;
            DROP FUNCTION IF EXISTS opening_after_legacy_papers();

            CREATE OR REPLACE FUNCTION cash_book_opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                    UNION ALL
                    SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.opened_on <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior fechados hasta el %: la apertura tiene que ser posterior.', v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_book_openings_after_legacy_papers
                BEFORE INSERT ON cash_book_openings
                FOR EACH ROW EXECUTE FUNCTION cash_book_opening_after_legacy_papers();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cash_book_openings_after_legacy_papers ON cash_book_openings;
            DROP FUNCTION IF EXISTS cash_book_opening_after_legacy_papers();

            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(fe.event_date)
                  FROM financial_events fe
                  JOIN cash_boxes cb ON cb.id = fe.cash_box_id
                 WHERE cb.code = 'haberes'
                   AND fe.event_type = 'opening_balance'
                   AND fe.status = 'posted'
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                    UNION ALL
                    SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.event_date <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior fechados hasta el %: la apertura tiene que ser posterior.', v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER financial_events_opening_after_legacy_papers
                BEFORE INSERT OR UPDATE OF status ON financial_events
                FOR EACH ROW
                WHEN (NEW.event_type = 'opening_balance' AND NEW.status = 'posted')
                EXECUTE FUNCTION opening_after_legacy_papers();
        SQL);
                }
            },

            /**
             * Cada moneda corta los papeles históricos en su propia apertura.
             *
             * Pesos y dólares son cajas separadas, y cada una se abre cuando le toca:
             * los dólares, recién cuando llegan dólares. El corte de la carga
             * histórica era la primera apertura de cualquier moneda, así que la de
             * pesos cortaba también los papeles de un haber en dólares: un recibo
             * manual en dólares de un día en que los dólares todavía se llevaban a
             * mano no se podía cargar.
             *
             * Ahora el papel se compara contra la apertura de la moneda de su haber,
             * y abrir una moneda solo tiene que ser posterior a los papeles de esa
             * misma moneda.
             */
            // Migración original: 2026_10_08_020000_cut_legacy_papers_by_currency.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date(p_currency char(3)) RETURNS date AS $$
                SELECT o.opened_on
                  FROM cash_book_openings o
                  JOIN cash_boxes cb ON cb.id = o.cash_box_id
                 WHERE cb.code = 'haberes'
                   AND o.currency = p_currency
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_paper_before_opening() RETURNS trigger AS $$
            DECLARE
                v_apertura DATE;
                v_fecha DATE;
                v_moneda CHAR(3);
            BEGIN
                IF TG_TABLE_NAME = 'legacy_documents' THEN
                    v_fecha := NEW.issued_on;
                ELSE
                    v_fecha := NEW.paid_on;
                END IF;

                IF v_fecha IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT currency INTO v_moneda FROM haberes WHERE id = NEW.haber_id;
                v_apertura := haberes_opening_date(v_moneda);

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'La caja de Haberes todavía no tiene apertura en %: sin ella no se puede saber si un papel es anterior al sistema.', v_moneda
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_fecha >= v_apertura THEN
                    RAISE EXCEPTION 'La fecha % no es anterior a la apertura de la caja en % (%).', v_fecha, v_moneda, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_book_opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT d.issued_on AS fecha
                      FROM legacy_documents d
                      JOIN haberes h ON h.id = d.haber_id
                     WHERE d.voided_at IS NULL AND h.currency = NEW.currency
                    UNION ALL
                    SELECT s.paid_on
                      FROM legacy_settlements s
                      JOIN haberes h ON h.id = s.haber_id
                     WHERE s.voided_at IS NULL AND s.paid_on IS NOT NULL AND h.currency = NEW.currency
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.opened_on <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior en % fechados hasta el %: la apertura tiene que ser posterior.', NEW.currency, v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS haberes_opening_date();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(o.opened_on)
                  FROM cash_book_openings o
                  JOIN cash_boxes cb ON cb.id = o.cash_box_id
                 WHERE cb.code = 'haberes'
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_paper_before_opening() RETURNS trigger AS $$
            DECLARE
                v_apertura DATE;
                v_fecha DATE;
            BEGIN
                IF TG_TABLE_NAME = 'legacy_documents' THEN
                    v_fecha := NEW.issued_on;
                ELSE
                    v_fecha := NEW.paid_on;
                END IF;

                IF v_fecha IS NULL THEN
                    RETURN NEW;
                END IF;

                v_apertura := haberes_opening_date();

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'La caja de Haberes todavía no tiene apertura: sin ella no se puede saber si un papel es anterior al sistema.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_fecha >= v_apertura THEN
                    RAISE EXCEPTION 'La fecha % no es anterior a la apertura de la caja (%).', v_fecha, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_book_opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                    UNION ALL
                    SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.opened_on <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior fechados hasta el %: la apertura tiene que ser posterior.', v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS haberes_opening_date(char);
        SQL);
                }
            },

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
            // Migración original: 2026_10_08_030000_guard_bank_cutoff_and_cash_adjustments.php
            new class extends Migration
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
            },

            /**
             * El detalle de cada cierre queda congelado con sus totales.
             *
             * Los totales del cierre ya eran un snapshot, pero la planilla volvía a
             * consultar el libro para el detalle —recibos e inventario de cheques—, y
             * el inventario salía del estado actual del cheque: un cheque depositado
             * después desaparecía de la planilla de un día anterior mientras el saldo
             * lo seguía contando. Ahora el detalle se guarda al cerrar, en la misma
             * transacción, y la planilla se dibuja desde ahí.
             *
             * - `period_closing_evidence`: una fila por versión del cierre, append-only.
             *   Reabrir y volver a cerrar agrega una versión; la anterior queda.
             * - `period_closings.evidence_version`: cuál rige. **Cero** marca los
             *   cierres hechos antes de esta migración: su detalle no se inventa a
             *   partir de datos actuales.
             * - `period_closing_has_evidence`: un cierre no queda cerrado sin su
             *   detalle de la versión vigente.
             */
            // Migración original: 2026_10_08_040000_freeze_period_closing_evidence.php
            new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('period_closings', function (Blueprint $table): void {
                        // Cero identifica cierres anteriores: no inventamos su detalle.
                        $table->unsignedInteger('evidence_version')->default(0);
                    });
                    Schema::create('period_closing_evidence', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('period_closing_id')->constrained('period_closings')->restrictOnDelete();
                        $table->unsignedInteger('version');
                        $table->jsonb('payload');
                        $table->timestampTz('created_at')->useCurrent();
                        $table->unique(['period_closing_id', 'version']);
                    });
                    DB::statement('ALTER TABLE period_closing_evidence ADD CONSTRAINT closing_evidence_version_positive CHECK (version > 0)');
                    DB::statement('CREATE TRIGGER closing_evidence_append_only BEFORE UPDATE OR DELETE ON period_closing_evidence
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                    AND status = 'closed' AND evidence_version = NEW.version) THEN
                    RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
                END IF;
                IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object' OR NEW.payload->>'schema' IS DISTINCT FROM '1' THEN
                    RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER closing_evidence_matches_version BEFORE INSERT ON period_closing_evidence
                FOR EACH ROW EXECUTE FUNCTION closing_evidence_matches_version();
            CREATE OR REPLACE FUNCTION period_closing_has_evidence() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'closed' AND NEW.evidence_version > 0 AND NOT EXISTS (
                    SELECT 1 FROM period_closing_evidence WHERE period_closing_id = NEW.id AND version = NEW.evidence_version
                ) THEN
                    RAISE EXCEPTION 'El cierre requiere su detalle congelado en la misma transaccion.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER period_closing_has_evidence AFTER INSERT OR UPDATE ON period_closings
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION period_closing_has_evidence();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS period_closing_has_evidence ON period_closings;
            DROP FUNCTION IF EXISTS period_closing_has_evidence();
            DROP TRIGGER IF EXISTS closing_evidence_matches_version ON period_closing_evidence;
            DROP FUNCTION IF EXISTS closing_evidence_matches_version();
        SQL);
                    Schema::dropIfExists('period_closing_evidence');
                    Schema::table('period_closings', function (Blueprint $table): void {
                        $table->dropColumn('evidence_version');
                    });
                }
            },

            // 2026_10_08_050000_configure_document_numbering.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE document_series ADD COLUMN display_prefix varchar(12);
            UPDATE document_series SET display_prefix = code;
            ALTER TABLE document_series DROP CONSTRAINT document_series_code_unique;
            CREATE UNIQUE INDEX document_series_active_code_unique ON document_series(code) WHERE is_active;
            CREATE UNIQUE INDEX document_series_active_type_unique ON document_series(document_type) WHERE is_active AND origin = 'system';
            ALTER TABLE document_series ADD CONSTRAINT document_series_format_check CHECK (
                (display_prefix IS NULL OR display_prefix ~ '^[0-9]{1,12}$')
                AND code_padding BETWEEN 1 AND 12 AND number_padding BETWEEN 1 AND 12
                AND format_pattern IN ('{code}/{number}', '{code}-{number}')
            );
            CREATE UNIQUE INDEX receipts_formatted_number_unique ON receipts(formatted_number);
            CREATE UNIQUE INDEX payment_orders_formatted_number_unique ON payment_orders(formatted_number);
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(receipt_type, talonario_number)
                WHERE talonario_number IS NOT NULL;
            CREATE OR REPLACE FUNCTION document_series_preserve_history() RETURNS trigger AS $$
            DECLARE used_number bigint;
            BEGIN
                SELECT max(number) INTO used_number FROM (
                    SELECT number FROM receipts WHERE document_series_id = OLD.id
                    UNION ALL SELECT number FROM payment_orders WHERE document_series_id = OLD.id
                ) numbers;
                IF used_number IS NOT NULL AND (
                    NEW.code IS DISTINCT FROM OLD.code OR NEW.display_prefix IS DISTINCT FROM OLD.display_prefix
                    OR NEW.format_pattern IS DISTINCT FROM OLD.format_pattern
                    OR NEW.code_padding IS DISTINCT FROM OLD.code_padding OR NEW.number_padding IS DISTINCT FROM OLD.number_padding
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.origin IS DISTINCT FROM OLD.origin
                ) THEN
                    RAISE EXCEPTION 'Una serie utilizada conserva su formato; crea una nueva version.' USING ERRCODE = 'check_violation';
                END IF;
                IF used_number IS NOT NULL AND NEW.next_number <= used_number THEN
                    RAISE EXCEPTION 'El proximo numero debe superar los comprobantes emitidos.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER document_series_preserve_history BEFORE UPDATE ON document_series
                FOR EACH ROW EXECUTE FUNCTION document_series_preserve_history();
        SQL);
                }

                public function down(): void
                {
                    // Puede haber varias versiones del mismo código: no se descarta historia al revertir.
                    if (DB::table('document_series')->select('code')->groupBy('code')->havingRaw('count(*) > 1')->exists()) {
                        throw new RuntimeException('Hay series versionadas. No se puede retirar esta migración sin perder su historia.');
                    }
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER document_series_preserve_history ON document_series;
            DROP FUNCTION document_series_preserve_history();
            DROP INDEX receipts_formatted_number_unique;
            DROP INDEX payment_orders_formatted_number_unique;
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(document_series_id, talonario_number)
                WHERE talonario_number IS NOT NULL;
            DROP INDEX document_series_active_code_unique;
            DROP INDEX document_series_active_type_unique;
            ALTER TABLE document_series DROP CONSTRAINT document_series_format_check;
            ALTER TABLE document_series DROP COLUMN display_prefix;
            ALTER TABLE document_series ADD CONSTRAINT document_series_code_unique UNIQUE(code);
        SQL);
                }
            },
        ];
    }
};
