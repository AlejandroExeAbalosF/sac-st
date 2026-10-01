<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
return new class extends Migration
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
};
