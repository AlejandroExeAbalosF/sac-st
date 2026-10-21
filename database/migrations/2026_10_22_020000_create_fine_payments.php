<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos de multas, su aplicación y su recibo — DER físico de Multas §6.1–6.5.
 *
 * - `fine_payments`: el pago, presentado o recibido. Solo el aplicado toca
 *   la deuda (M02).
 * - `fine_payment_applications`: el pago tocó dinero real. Una recepción
 *   entera por pago; lo que excede la deuda es excedente, no queda sin
 *   aplicar (M05, M06).
 * - `fine_payment_receipts`: el recibo de Multas que lo documenta, con lo que
 *   decía la multa en ese momento congelado (M09).
 * - `fine_excess_reclassifications`: cuánto de lo ya cobrado pasó a ser
 *   excedente, o dejó de serlo, por un ajuste de la obligación (M05).
 *
 * El excedente asentado de cada multa —aplicaciones más reclasificaciones—
 * tiene que ser el que dice `fine_balances`. Lo impone la base al confirmar
 * cada transacción que lo pueda mover.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->payments();
        $this->applications();
        $this->receipts();
        $this->reclassifications();
        $this->balancesView();
        $this->settlement();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fund_receipts_keep_fine_applications ON fund_receipts;
            DROP TRIGGER IF EXISTS funding_allocations_not_from_multas ON funding_allocations;
            DROP FUNCTION IF EXISTS fund_receipts_keep_fine_applications();
            DROP FUNCTION IF EXISTS funding_allocations_not_from_multas();
            DROP TRIGGER IF EXISTS receipts_multas_belong_to_a_payment ON receipts;
            DROP TRIGGER IF EXISTS financial_events_fine_events_linked ON financial_events;
            DROP TRIGGER IF EXISTS fine_historical_movements_excess_settled ON fine_historical_movements;
            DROP TRIGGER IF EXISTS fine_debt_entries_excess_settled ON fine_debt_entries;
            DROP FUNCTION IF EXISTS receipts_multas_belong_to_a_payment();
            DROP FUNCTION IF EXISTS financial_events_fine_events_linked();

            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       0::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess
              FROM base;
        SQL);

        Schema::dropIfExists('fine_excess_reclassifications');
        Schema::dropIfExists('fine_payment_receipts');
        Schema::dropIfExists('fine_payment_applications');
        Schema::dropIfExists('fine_payments');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fine_excess_settled();
            DROP FUNCTION IF EXISTS fine_payment_settled();
            DROP FUNCTION IF EXISTS fine_excess_reclassification_coherent();
            DROP FUNCTION IF EXISTS fine_payment_receipt_coherent();
            DROP FUNCTION IF EXISTS fine_payment_application_coherent();
            DROP FUNCTION IF EXISTS fine_payments_guard();
            DROP FUNCTION IF EXISTS fine_payment_rows_append_only();
            DROP FUNCTION IF EXISTS multas_cash_box_id();
        SQL);
    }

    private function payments(): void
    {
        Schema::create('fine_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->string('medium', 20);

            /*
             * El origen judicial no es un medio (M02): una orden de pago
             * judicial llega como transferencia o como cheque.
             */
            $table->boolean('judicial_origin')->default(false);
            $table->string('judicial_reference', 120)->nullable();

            $table->string('status', 20);
            /** Para un cheque, el importe del instrumento entero. */
            $table->decimal('amount', 19, 2);

            /*
             * Cuándo se presentó el comprobante, con la precisión que tenga, y
             * cuándo se cargó. El término se juzga por la presentación (M08);
             * la carga es evidencia. Una presentación anterior a la carga
             * lleva su respaldo.
             */
            $table->timestampTz('presented_at')->nullable();
            $table->string('presented_precision', 10);
            $table->string('presentation_evidence', 300)->nullable();
            $table->timestampTz('reported_at')->useCurrent();
            /** El día en que el sancionado dice haber pagado por el banco. */
            $table->date('declared_paid_on')->nullable();

            /*
             * «Recibí de»: la carátula de la multa (M09). La persona es un
             * vínculo opcional, no una condición para cobrar.
             */
            $table->foreignId('payer_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->string('payer_name', 300);

            /*
             * Pago por recurso (M07): el 20 % de la obligación vigente al
             * registrarlo, sin centavos, con la base congelada. El recibo
             * externo que lo acompaña dice su propio importe.
             */
            $table->string('appeal_kind', 20)->nullable();
            $table->decimal('appeal_base_amount', 19, 2)->nullable();
            $table->decimal('appeal_required_amount', 19, 2)->nullable();
            $table->string('external_receipt_reference', 120)->nullable();
            $table->decimal('external_receipt_amount', 19, 2)->nullable();

            /** Quien resolvió la diferencia de un recurso, y por qué. */
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();

            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('discarded_at')->nullable();
            $table->string('discard_reason', 500)->nullable();

            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'fine_id']);
            $table->index(['fine_id', 'status']);
            $table->index('status');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_medium_check
                    CHECK (medium IN ('cash', 'cheque', 'bank_transfer', 'direct_deposit', 'echeq')),
                ADD CONSTRAINT fine_payments_status_check
                    CHECK (status IN ('reported', 'applied', 'discarded', 'under_review')),
                ADD CONSTRAINT fine_payments_amount_check CHECK (amount > 0),
                ADD CONSTRAINT fine_payments_payer_check CHECK (btrim(payer_name) <> ''),
                ADD CONSTRAINT fine_payments_judicial_check CHECK (
                    judicial_origin = (judicial_reference IS NOT NULL)
                    AND (judicial_reference IS NULL OR btrim(judicial_reference) <> '')
                    AND (NOT judicial_origin OR medium IN ('bank_transfer', 'cheque'))
                ),
                ADD CONSTRAINT fine_payments_presentation_check CHECK (
                    presented_precision IN ('instant', 'day', 'unknown')
                    AND (presented_precision = 'unknown') = (presented_at IS NULL)
                    AND (presented_at IS NULL OR presented_at <= reported_at)
                    -- Presentado en el momento de cargarlo, o con respaldo.
                    AND ((presented_precision = 'instant' AND presented_at = reported_at)
                         OR btrim(coalesce(presentation_evidence, '')) <> '')
                ),
                ADD CONSTRAINT fine_payments_declared_paid_check
                    CHECK (declared_paid_on IS NULL OR medium NOT IN ('cash', 'cheque')),
                ADD CONSTRAINT fine_payments_appeal_check CHECK (
                    num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount,
                        external_receipt_reference, external_receipt_amount) IN (0, 5)
                    AND (appeal_kind IS NULL OR (
                        appeal_kind IN ('hierarchical', 'appeal')
                        AND medium IN ('cash', 'direct_deposit')
                        AND NOT judicial_origin
                        AND btrim(external_receipt_reference) <> ''
                        AND external_receipt_amount > 0
                        -- Pesos enteros, a favor del deudor (M07).
                        AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                        AND amount = appeal_required_amount
                    ))
                ),
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    -- Solo un recurso con diferencia queda en revisión, y no sale sin resolver.
                    AND (status <> 'under_review' OR (appeal_kind IS NOT NULL AND external_receipt_amount <> amount))
                    AND (appeal_kind IS NULL OR external_receipt_amount = amount
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                ),
                ADD CONSTRAINT fine_payments_discard_check CHECK (
                    num_nonnulls(discarded_by, discarded_at, discard_reason) IN (0, 3)
                    AND (status = 'discarded') = (discard_reason IS NOT NULL)
                    AND (discard_reason IS NULL OR btrim(discard_reason) <> '')
                );

            CREATE OR REPLACE FUNCTION multas_cash_box_id() RETURNS bigint AS $$
                SELECT id FROM cash_boxes WHERE code = 'multas'
            $$ LANGUAGE sql STABLE;

            /*
             * Un pago nace aplicado si se cobró en la caja, o informado si
             * espera un crédito del banco. Después solo avanza: lo aplicado y
             * lo descartado ya no cambian (P13).
             */
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status = 'discarded' THEN
                        RAISE EXCEPTION 'Un pago no nace descartado.' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'applied' AND NEW.medium NOT IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Un pago por el banco se aplica al verificar el crédito: nace informado.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'reported' AND NEW.medium IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Lo que se cobra en la caja se aplica en el acto.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.appeal_kind IS NOT NULL THEN
                        PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                        IF NEW.appeal_base_amount <> fine_obligation(NEW.fine_id) THEN
                            RAISE EXCEPTION 'La base del 20 %% es el importe vigente de la multa al registrar el recurso: % y no %.',
                                fine_obligation(NEW.fine_id), NEW.appeal_base_amount
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payments_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_payments
                FOR EACH ROW EXECUTE FUNCTION fine_payments_guard();

            CREATE OR REPLACE FUNCTION fine_payment_rows_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Esta fila documenta un cobro: no se edita ni se borra.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    private function applications(): void
    {
        Schema::create('fine_payment_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
            $table->unsignedBigInteger('fine_id');
            $table->foreignId('fund_receipt_id')->unique()->constrained('fund_receipts')->restrictOnDelete();
            $table->foreignId('application_event_id')->unique()->constrained('financial_events')->restrictOnDelete();

            /** La recepción entera: lo que cubre la deuda y lo que la excede. */
            $table->decimal('amount', 19, 2);
            $table->decimal('applied_amount', 19, 2);
            $table->decimal('excess_amount', 19, 2);

            /*
             * El término, juzgado una vez al aplicar contra el cierre mensual
             * del mes de registro de la multa (M08, DER §6.5). Cita la versión
             * de la evidencia del cierre: una reapertura posterior no lo cambia.
             */
            $table->string('timeliness', 20);
            $table->foreignId('origin_closing_evidence_id')->nullable()
                ->constrained('period_closing_evidence')->restrictOnDelete();
            $table->timestampTz('origin_closed_at')->nullable();

            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->useCurrent();

            $table->foreign(['fine_payment_id', 'fine_id'])->references(['id', 'fine_id'])->on('fine_payments');
            $table->index('fine_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_applications
                ADD CONSTRAINT fine_payment_applications_amounts_check CHECK (
                    amount > 0 AND applied_amount >= 0 AND excess_amount >= 0
                    AND applied_amount + excess_amount = amount
                ),
                ADD CONSTRAINT fine_payment_applications_timeliness_check CHECK (
                    timeliness IN ('on_time', 'late', 'undetermined')
                    AND (origin_closing_evidence_id IS NULL) = (origin_closed_at IS NULL)
                    AND (timeliness <> 'late' OR origin_closing_evidence_id IS NOT NULL)
                );

            /*
             * El pago, la recepción y el asiento cuentan lo mismo: la recepción
             * entera, de la caja de Multas, en la moneda de la multa; y el
             * asiento la pasa de sin identificar a aplicado y excedente.
             */
            CREATE OR REPLACE FUNCTION fine_payment_application_coherent() RETURNS trigger AS $$
            DECLARE
                v_pago RECORD;
                v_recepcion RECORD;
                v_evento RECORD;
                v_moneda char(3);
                v_medio text;
                v_lineas RECORD;
            BEGIN
                SELECT status, amount, medium INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;
                IF v_pago.status <> 'applied' THEN
                    RAISE EXCEPTION 'Solo se aplica un pago marcado como aplicado.' USING ERRCODE = 'check_violation';
                END IF;
                IF v_pago.amount <> NEW.amount THEN
                    RAISE EXCEPTION 'La aplicación es por el pago entero: % y no %.', v_pago.amount, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT currency INTO v_moneda FROM fines WHERE id = NEW.fine_id;
                SELECT amount, origin, currency, cash_box_id, medium INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                v_medio := CASE WHEN v_pago.medium IN ('cash', 'cheque') THEN v_pago.medium ELSE 'bank' END;

                IF v_recepcion.cash_box_id IS DISTINCT FROM multas_cash_box_id()
                    OR v_recepcion.origin <> 'received'
                    OR v_recepcion.currency <> v_moneda
                    OR v_recepcion.medium <> v_medio
                    OR v_recepcion.amount <> NEW.amount THEN
                    RAISE EXCEPTION 'La recepción no es la de este pago: tiene que ser de la caja de Multas, en la moneda de la multa, por el mismo medio y el importe entero.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT event_type, status, cash_box_id INTO v_evento FROM financial_events WHERE id = NEW.application_event_id;
                IF v_evento.event_type <> 'funds_applied' OR v_evento.status <> 'posted'
                    OR v_evento.cash_box_id IS DISTINCT FROM multas_cash_box_id() THEN
                    RAISE EXCEPTION 'La aplicación se asienta como fondos aplicados de la caja de Multas.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(debit) FILTER (WHERE account_code = 'UNASSIGNED_FUNDS'), 0) AS sin_identificar,
                       COALESCE(SUM(credit) FILTER (WHERE account_code = 'COLLECTED_FUNDS'), 0) AS aplicado,
                       COALESCE(SUM(credit) FILTER (WHERE account_code = 'PENDING_EXCESS'), 0) AS excedente,
                       COUNT(*) FILTER (WHERE currency <> v_moneda
                           OR account_code NOT IN ('UNASSIGNED_FUNDS', 'COLLECTED_FUNDS', 'PENDING_EXCESS')
                           OR (account_code = 'UNASSIGNED_FUNDS' AND credit > 0)
                           OR (account_code <> 'UNASSIGNED_FUNDS' AND debit > 0)) AS ajenas
                  INTO v_lineas
                  FROM journal_lines WHERE financial_event_id = NEW.application_event_id;

                IF v_lineas.ajenas > 0 OR v_lineas.sin_identificar <> NEW.amount
                    OR v_lineas.aplicado <> NEW.applied_amount OR v_lineas.excedente <> NEW.excess_amount THEN
                    RAISE EXCEPTION 'El asiento no coincide con la aplicación: debita lo sin identificar y acredita lo aplicado y el excedente.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_applications_coherent BEFORE INSERT ON fine_payment_applications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_application_coherent();
            CREATE TRIGGER fine_payment_applications_append_only BEFORE UPDATE OR DELETE ON fine_payment_applications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);
    }

    private function receipts(): void
    {
        Schema::create('fine_payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
            $table->foreignId('receipt_id')->unique()->constrained('receipts')->restrictOnDelete();
            /** El del pago que dejó la deuda en cero (M09). */
            $table->boolean('is_cancellation');
            /** Lo que el recibo dice de la multa, congelado al emitirlo. */
            $table->jsonb('snapshot');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_receipts ADD CONSTRAINT fine_payment_receipts_snapshot_check
                CHECK (jsonb_typeof(snapshot) = 'object');

            CREATE OR REPLACE FUNCTION fine_payment_receipt_coherent() RETURNS trigger AS $$
            DECLARE
                v_pago RECORD;
                v_recibo RECORD;
            BEGIN
                SELECT status, amount INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;
                SELECT cash_box_id, receipt_type, amount, status INTO v_recibo FROM receipts WHERE id = NEW.receipt_id;

                IF v_pago.status <> 'applied' THEN
                    RAISE EXCEPTION 'Solo un pago aplicado lleva recibo.' USING ERRCODE = 'check_violation';
                END IF;
                IF v_recibo.cash_box_id IS DISTINCT FROM multas_cash_box_id() OR v_recibo.receipt_type <> 'income'
                    OR v_recibo.status <> 'issued' OR v_recibo.amount <> v_pago.amount THEN
                    RAISE EXCEPTION 'El recibo de un pago es un recibo de ingreso de Multas, vigente y por el importe del pago.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_receipts_coherent BEFORE INSERT ON fine_payment_receipts
                FOR EACH ROW EXECUTE FUNCTION fine_payment_receipt_coherent();
            CREATE TRIGGER fine_payment_receipts_append_only BEFORE UPDATE OR DELETE ON fine_payment_receipts
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);
    }

    private function reclassifications(): void
    {
        Schema::create('fine_excess_reclassifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->foreignId('event_id')->unique()->constrained('financial_events')->restrictOnDelete();
            /** Positivo: lo aplicado pasa a excedente. Negativo: al revés. */
            $table->decimal('amount', 19, 2);
            $table->string('reason', 300);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('fine_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_excess_reclassifications
                ADD CONSTRAINT fine_excess_reclassifications_amount_check CHECK (amount <> 0),
                ADD CONSTRAINT fine_excess_reclassifications_reason_check CHECK (btrim(reason) <> '');

            CREATE OR REPLACE FUNCTION fine_excess_reclassification_coherent() RETURNS trigger AS $$
            DECLARE
                v_evento RECORD;
                v_moneda char(3);
                v_desde text;
                v_hacia text;
                v_lineas RECORD;
            BEGIN
                SELECT currency INTO v_moneda FROM fines WHERE id = NEW.fine_id;
                SELECT event_type, status, cash_box_id INTO v_evento FROM financial_events WHERE id = NEW.event_id;

                IF v_evento.event_type <> 'excess_reclassified' OR v_evento.status <> 'posted'
                    OR v_evento.cash_box_id IS DISTINCT FROM multas_cash_box_id() THEN
                    RAISE EXCEPTION 'La reclasificación se asienta como excedente reclasificado de la caja de Multas.'
                        USING ERRCODE = 'check_violation';
                END IF;

                v_desde := CASE WHEN NEW.amount > 0 THEN 'COLLECTED_FUNDS' ELSE 'PENDING_EXCESS' END;
                v_hacia := CASE WHEN NEW.amount > 0 THEN 'PENDING_EXCESS' ELSE 'COLLECTED_FUNDS' END;

                SELECT COALESCE(SUM(debit) FILTER (WHERE account_code = v_desde), 0) AS debito,
                       COALESCE(SUM(credit) FILTER (WHERE account_code = v_hacia), 0) AS credito,
                       COUNT(*) FILTER (WHERE currency <> v_moneda
                           OR NOT ((account_code = v_desde AND debit > 0) OR (account_code = v_hacia AND credit > 0))) AS ajenas
                  INTO v_lineas
                  FROM journal_lines WHERE financial_event_id = NEW.event_id;

                IF v_lineas.ajenas > 0 OR v_lineas.debito <> abs(NEW.amount) OR v_lineas.credito <> abs(NEW.amount) THEN
                    RAISE EXCEPTION 'El asiento no coincide con la reclasificación: pasa % de % a %.', abs(NEW.amount), v_desde, v_hacia
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_excess_reclassifications_coherent BEFORE INSERT ON fine_excess_reclassifications
                FOR EACH ROW EXECUTE FUNCTION fine_excess_reclassification_coherent();
            CREATE TRIGGER fine_excess_reclassifications_append_only BEFORE UPDATE OR DELETE ON fine_excess_reclassifications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);
    }

    /** Lo pagado en el sistema es lo aplicado: cada recepción entera. */
    private function balancesView(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess
              FROM base;
        SQL);
    }

    /**
     * Lo que se verifica al confirmar, cuando ya está todo escrito.
     */
    private function settlement(): void
    {
        DB::unprepared(<<<'SQL'
            /*
             * Un pago aplicado tiene su aplicación y su recibo; uno que no lo
             * está no tiene ninguno de los dos.
             */
            CREATE OR REPLACE FUNCTION fine_payment_settled() RETURNS trigger AS $$
            DECLARE
                v_aplicado boolean;
                v_aplicacion boolean;
                v_recibo boolean;
            BEGIN
                SELECT status = 'applied' INTO v_aplicado FROM fine_payments WHERE id = NEW.id;
                v_aplicacion := EXISTS (SELECT 1 FROM fine_payment_applications WHERE fine_payment_id = NEW.id);
                v_recibo := EXISTS (SELECT 1 FROM fine_payment_receipts WHERE fine_payment_id = NEW.id);

                IF v_aplicado AND NOT (v_aplicacion AND v_recibo) THEN
                    RAISE EXCEPTION 'El pago % quedó aplicado sin su aplicación o sin su recibo.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NOT v_aplicado AND (v_aplicacion OR v_recibo) THEN
                    RAISE EXCEPTION 'El pago % tiene aplicación o recibo sin estar aplicado.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_payments_settled AFTER INSERT OR UPDATE ON fine_payments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_payment_settled();

            /*
             * El excedente asentado es el que dice el saldo (M05).
             *
             * Asentado: lo que cada aplicación mandó a excedente más lo que las
             * reclasificaciones movieron. Calculado: el `operational_excess` de
             * `fine_balances`. Cualquier hecho que cambie la obligación, lo
             * pagado o los antecedentes tiene que llegar al cierre de la
             * transacción con los dos iguales.
             */
            CREATE OR REPLACE FUNCTION fine_excess_settled() RETURNS trigger AS $$
            DECLARE
                v_asentado numeric(19,2);
                v_calculado numeric(19,2);
            BEGIN
                v_asentado := COALESCE((SELECT SUM(excess_amount) FROM fine_payment_applications WHERE fine_id = NEW.fine_id), 0)
                    + COALESCE((SELECT SUM(amount) FROM fine_excess_reclassifications WHERE fine_id = NEW.fine_id), 0);
                SELECT operational_excess INTO v_calculado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF v_asentado <> v_calculado THEN
                    RAISE EXCEPTION 'El excedente asentado de la multa % es % y su saldo dice %: falta reclasificar.',
                        NEW.fine_id, v_asentado, v_calculado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_payment_applications_excess_settled AFTER INSERT ON fine_payment_applications
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();
            CREATE CONSTRAINT TRIGGER fine_excess_reclassifications_excess_settled AFTER INSERT ON fine_excess_reclassifications
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();
            CREATE CONSTRAINT TRIGGER fine_debt_entries_excess_settled AFTER INSERT ON fine_debt_entries
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();
            CREATE CONSTRAINT TRIGGER fine_historical_movements_excess_settled AFTER INSERT OR UPDATE ON fine_historical_movements
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();

            /*
             * Los dos hechos nuevos del libro son de Multas: ninguno queda
             * asentado sin la fila que dice a qué multa corresponde.
             */
            CREATE OR REPLACE FUNCTION financial_events_fine_events_linked() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'funds_applied' AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE application_event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Los fondos aplicados del evento % no corresponden a ningún pago.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.event_type = 'excess_reclassified' AND NOT EXISTS (
                    SELECT 1 FROM fine_excess_reclassifications WHERE event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La reclasificación del evento % no corresponde a ninguna multa.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER financial_events_fine_events_linked AFTER INSERT ON financial_events
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                WHEN (NEW.event_type IN ('funds_applied', 'excess_reclassified'))
                EXECUTE FUNCTION financial_events_fine_events_linked();

            /* Un recibo de la caja de Multas documenta un pago de una multa. */
            CREATE OR REPLACE FUNCTION receipts_multas_belong_to_a_payment() RETURNS trigger AS $$
            BEGIN
                IF NEW.cash_box_id = multas_cash_box_id() AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_receipts WHERE receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'El recibo % de Multas no documenta ningún pago.', NEW.formatted_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER receipts_multas_belong_to_a_payment AFTER INSERT ON receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                EXECUTE FUNCTION receipts_multas_belong_to_a_payment();

            /*
             * Lo cobrado para una multa no financia una cuota de Haberes: es
             * otra caja y otra obligación.
             */
            CREATE OR REPLACE FUNCTION funding_allocations_not_from_multas() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts
                     WHERE id = NEW.fund_receipt_id AND cash_box_id = multas_cash_box_id()
                ) THEN
                    RAISE EXCEPTION 'Una recepción de la caja de Multas no se asigna a una cuota de Haberes.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER funding_allocations_not_from_multas BEFORE INSERT ON funding_allocations
                FOR EACH ROW EXECUTE FUNCTION funding_allocations_not_from_multas();

            /*
             * Un cobro ya aplicado a una multa no se revierte por su cuenta:
             * dejaría la aplicación y el recibo sin dinero detrás. Anular un
             * pago es un circuito todavía sin definir (P13).
             */
            CREATE OR REPLACE FUNCTION fund_receipts_keep_fine_applications() RETURNS trigger AS $$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE fund_receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La recepción % está aplicada al pago de una multa: no se revierte.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_keep_fine_applications BEFORE UPDATE OF reversal_event_id ON fund_receipts
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_keep_fine_applications();
        SQL);
    }
};
