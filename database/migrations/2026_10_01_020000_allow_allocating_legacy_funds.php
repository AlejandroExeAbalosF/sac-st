<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
return new class extends Migration
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
};
