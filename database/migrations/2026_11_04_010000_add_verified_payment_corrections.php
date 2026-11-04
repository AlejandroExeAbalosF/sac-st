<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corregir un pago bancario verificado y anular un cheque rechazado
 * (decisiones del usuario, 04/11/2026; P13). Las reglas no salen del
 * relevamiento: están para confirmar con el área.
 *
 * **Un pago bancario se anula** y su crédito vuelve a quedar libre. La
 * corrección es esa anulación más un pago nuevo, en la misma transacción, y
 * `fine_payment_corrections` los une: lo anterior queda a la vista.
 *
 * **Un cheque rechazado se anula sin revertir su recepción.** El papel ya salió
 * de custodia o de tránsito con el rechazo: revertir la recepción dejaría esa
 * cuenta en negativo y `REJECTED_CHEQUES` con saldo. Lo que sale es lo que la
 * empresa tenía que regularizar (D `UNASSIGNED_FUNDS` / C `REJECTED_CHEQUES`), y
 * el depósito y el rechazo quedan como hechos que ocurrieron. Por eso la columna
 * pasa a llamarse `funds_exit_event_id`: no siempre es una reversión.
 *
 * Anular no afirma que el banco nunca acreditó el cheque: lo que ya figura
 * acreditado queda bloqueado, y lo que el extracto traiga después se registra
 * sobre el rechazo, sin reactivar el pago.
 */
return new class extends Migration
{
    private const NO_DELEGABLES = [
        'roles.gestionar',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.desactivar',
        'usuarios.restablecer-password',
    ];

    private const TIPOS = "'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated',
                    'funds_applied', 'excess_reclassified',
                    'cheque_rejected', 'rejected_cheque_credited', 'cheque_debit_recorded', 'cheque_replaced'";

    private const MOTIVOS = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

    private const MOTIVOS_NUEVOS = "'medium_change', 'wrong_bank_credit', 'credit_of_another_box'";

    public function up(): void
    {
        Schema::create('fine_payment_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corrected_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
            $table->foreignId('new_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
            $table->foreignId('from_fine_id')->constrained('fines')->restrictOnDelete();
            $table->foreignId('to_fine_id')->constrained('fines')->restrictOnDelete();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('corrected_at')->useCurrent();
        });

        $tipos = self::TIPOS;
        $motivos = self::MOTIVOS.', '.self::MOTIVOS_NUEVOS;

        DB::unprepared(<<<SQL
            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN ({$tipos}, 'rejected_cheque_voided'));

            ALTER TABLE fine_payment_voids RENAME COLUMN receipt_reversal_event_id TO funds_exit_event_id;

            ALTER TABLE fine_payment_voids
                DROP CONSTRAINT fine_payment_voids_reason_check,
                ADD CONSTRAINT fine_payment_voids_reason_check CHECK (
                    reason_code IN ({$motivos})
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code <> 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_payment_corrections
                ADD CONSTRAINT fine_payment_corrections_corrected_fk FOREIGN KEY (corrected_payment_id, from_fine_id)
                    REFERENCES fine_payments (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_payment_corrections_new_fk FOREIGN KEY (new_payment_id, to_fine_id)
                    REFERENCES fine_payments (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_payment_corrections_distinct_check CHECK (corrected_payment_id <> new_payment_id);

            /*
             * Anular depende de qué es el pago: un cobro que sigue en la caja, un
             * cheque rechazado o un pago por el banco. Bloquea la multa y la
             * recepción, como un depósito (`transfer_follows_box_policy`). Con un
             * rechazo en la cadena bloquea también el último cheque y sus rechazos,
             * en el orden en que los toma un reemplazo: el segundo espera y ve lo
             * que confirmó el primero.
             */
            CREATE OR REPLACE FUNCTION fine_payment_voids_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_pago RECORD;
                v_aplicacion RECORD;
                v_recepcion RECORD;
                v_ultimo bigint;
                v_salida text;
            BEGIN
                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                SELECT status, medium INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF v_pago.status <> 'applied' THEN
                    RAISE EXCEPTION 'Se anula un pago ya aplicado.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT fund_receipt_id, application_event_id INTO v_aplicacion
                  FROM fine_payment_applications WHERE fine_payment_id = NEW.fine_payment_id;

                SELECT id, financial_event_id, amount INTO v_recepcion
                  FROM fund_receipts WHERE id = v_aplicacion.fund_receipt_id FOR UPDATE;

                IF NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.application_reversal_event_id AND reversal_of_id = v_aplicacion.application_event_id AND status = 'posted'
                ) THEN
                    RAISE EXCEPTION 'La anulación se asienta revirtiendo la aplicación del pago.' USING ERRCODE = 'check_violation';
                END IF;

                -- Un cheque que tuvo un rechazo: lo que sale es lo que había que regularizar.
                IF v_pago.medium = 'cheque' AND EXISTS (
                    SELECT 1 FROM cheque_rejections WHERE fund_receipt_root(fund_receipt_id) = v_recepcion.id
                ) THEN
                    v_ultimo := fund_receipt_tip(v_recepcion.id);
                    PERFORM 1 FROM fund_receipts WHERE id = v_ultimo FOR UPDATE;

                    IF fund_receipt_tip(v_recepcion.id) <> v_ultimo THEN
                        RAISE EXCEPTION 'El cheque tiene un reemplazo nuevo: sigue en curso y no se anula.' USING ERRCODE = 'check_violation';
                    END IF;

                    PERFORM 1 FROM cheque_rejections
                      WHERE fund_receipt_root(fund_receipt_id) = v_recepcion.id ORDER BY id FOR UPDATE;

                    IF NOT EXISTS (
                        SELECT 1 FROM cheque_rejections WHERE fund_receipt_id = v_ultimo AND replacement_fund_receipt_id IS NULL
                    ) THEN
                        RAISE EXCEPTION 'El cheque de reemplazo sigue en curso: se anula un rechazo sin reemplazo.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM cheque_rejections
                         WHERE fund_receipt_root(fund_receipt_id) = v_recepcion.id AND bank_status IN ('debit_pending', 'debit_recorded')
                    ) THEN
                        RAISE EXCEPTION 'El banco acreditó ese cheque: su revisión bancaria está pendiente y no se anula.' USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT event_type INTO v_salida FROM financial_events WHERE id = NEW.funds_exit_event_id AND status = 'posted';

                    IF v_salida IS DISTINCT FROM 'rejected_cheque_voided' OR NOT EXISTS (
                        SELECT 1 FROM journal_lines
                         WHERE financial_event_id = NEW.funds_exit_event_id AND account_code = 'REJECTED_CHEQUES' AND credit = v_recepcion.amount
                    ) THEN
                        RAISE EXCEPTION 'Anular un cheque rechazado saca entero lo que había que regularizar, con su asiento propio.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF v_pago.medium IN ('cash', 'cheque') AND EXISTS (
                    SELECT 1 FROM cash_to_bank_transfer_items i
                      JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                     WHERE i.fund_receipt_id = v_recepcion.id AND t.status <> 'cancelled'
                ) THEN
                    RAISE EXCEPTION 'Ese cobro ya se depositó: el dinero entró y no se anula.' USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.funds_exit_event_id AND reversal_of_id = v_recepcion.financial_event_id AND status = 'posted'
                ) THEN
                    RAISE EXCEPTION 'La anulación se asienta revirtiendo la aplicación y la recepción del pago.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            -- Lo que deja cada anulación, al confirmar.
            CREATE OR REPLACE FUNCTION fine_payment_voids_settled() RETURNS trigger AS \$\$
            DECLARE
                v_recepcion RECORD;
                v_medio text;
                v_salida text;
            BEGIN
                SELECT medium INTO v_medio FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF (SELECT status FROM fine_payments WHERE id = NEW.fine_payment_id) <> 'voided' THEN
                    RAISE EXCEPTION 'La anulación del pago % no lo dejó anulado.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT r.financial_event_id, r.reversal_event_id INTO v_recepcion
                  FROM fine_payment_applications a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                 WHERE a.fine_payment_id = NEW.fine_payment_id;

                SELECT event_type INTO v_salida FROM financial_events WHERE id = NEW.funds_exit_event_id;

                IF v_salida = 'rejected_cheque_voided' THEN
                    IF v_recepcion.reversal_event_id IS NOT NULL THEN
                        RAISE EXCEPTION 'El pago % es de un cheque rechazado: su recepción ocurrió y no se revierte.', NEW.fine_payment_id
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NULL;
                END IF;

                IF v_recepcion.reversal_event_id IS DISTINCT FROM NEW.funds_exit_event_id THEN
                    RAISE EXCEPTION 'La anulación del pago % no revirtió su recepción.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_medio NOT IN ('cash', 'cheque') AND EXISTS (
                    SELECT 1 FROM bank_transaction_allocations b
                     WHERE b.financial_event_id = v_recepcion.financial_event_id
                       AND b.reversal_of_id IS NULL
                       AND NOT EXISTS (SELECT 1 FROM bank_transaction_allocations r WHERE r.reversal_of_id = b.id)
                ) THEN
                    RAISE EXCEPTION 'La anulación del pago % dejó su crédito imputado.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;

            -- Un pago anulado no se regulariza: el cheque ya no tiene reemplazo posible.
            CREATE OR REPLACE FUNCTION fund_receipts_replacement_matches() RETURNS trigger AS \$\$
            DECLARE
                v_anterior RECORD;
            BEGIN
                SELECT medium, amount, currency, cash_box_id, cheque_status INTO v_anterior
                  FROM fund_receipts WHERE id = NEW.replaces_fund_receipt_id FOR UPDATE;

                IF v_anterior.cheque_status IS DISTINCT FROM 'rejected' THEN
                    RAISE EXCEPTION 'Solo se reemplaza un cheque rechazado.' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.medium <> 'cheque' OR NEW.amount <> v_anterior.amount OR NEW.currency <> v_anterior.currency
                    OR NEW.cash_box_id IS DISTINCT FROM v_anterior.cash_box_id
                    OR NEW.cheque_status IS DISTINCT FROM 'in_custody' THEN
                    RAISE EXCEPTION 'Un cheque rechazado se reemplaza por otro cheque del mismo importe, en custodia: % y no %.',
                        v_anterior.amount, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM fine_payment_applications a
                      JOIN fine_payment_voids v ON v.fine_payment_id = a.fine_payment_id
                     WHERE a.fund_receipt_id = fund_receipt_root(NEW.replaces_fund_receipt_id)
                ) THEN
                    RAISE EXCEPTION 'El cobro de ese cheque se anuló: ya no se reemplaza.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            /*
             * Una corrección une dos pagos bancarios: el anulado y el nuevo. Con
             * recurso, reemplaza su propio pago en la misma multa: es lo único
             * que una multa congelada admite, y `fine_appeal_consistent` exige un
             * solo pago vivo al confirmar.
             */
            CREATE OR REPLACE FUNCTION fine_payment_corrections_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_corregido RECORD;
                v_nuevo RECORD;
                v_creditos bigint;
            BEGIN
                SELECT status, medium, fine_appeal_id, presented_at, presented_precision INTO v_corregido
                  FROM fine_payments WHERE id = NEW.corrected_payment_id;
                SELECT status, medium, fine_appeal_id, presented_at, presented_precision INTO v_nuevo
                  FROM fine_payments WHERE id = NEW.new_payment_id;

                IF v_corregido.status <> 'voided' OR v_corregido.medium IN ('cash', 'cheque')
                    OR v_nuevo.status <> 'applied' OR v_nuevo.medium <> v_corregido.medium THEN
                    RAISE EXCEPTION 'Una corrección une un pago bancario anulado con otro aplicado, del mismo medio.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_nuevo.fine_appeal_id IS DISTINCT FROM v_corregido.fine_appeal_id
                    OR (v_corregido.fine_appeal_id IS NOT NULL AND NEW.from_fine_id <> NEW.to_fine_id) THEN
                    RAISE EXCEPTION 'El pago de un recurso se corrige dentro de su recurso y de su multa.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_nuevo.presented_at IS DISTINCT FROM v_corregido.presented_at
                    OR v_nuevo.presented_precision IS DISTINCT FROM v_corregido.presented_precision THEN
                    RAISE EXCEPTION 'Una corrección conserva la presentación del pago que corrige.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- El crédito de cada pago: la imputación original, aunque la del corregido ya tenga su reversa.
                SELECT count(DISTINCT b.bank_transaction_id) INTO v_creditos
                  FROM fine_payment_applications a
                  JOIN fund_receipts r ON r.id = a.fund_receipt_id
                  JOIN bank_transaction_allocations b ON b.financial_event_id = r.financial_event_id AND b.reversal_of_id IS NULL
                 WHERE a.fine_payment_id IN (NEW.corrected_payment_id, NEW.new_payment_id);

                IF NEW.from_fine_id = NEW.to_fine_id AND v_creditos = 1 THEN
                    RAISE EXCEPTION 'Una corrección cambia la multa o el crédito: así no cambia nada.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_corrections_coherent BEFORE INSERT ON fine_payment_corrections
                FOR EACH ROW EXECUTE FUNCTION fine_payment_corrections_coherent();
            CREATE TRIGGER fine_payment_corrections_append_only BEFORE UPDATE OR DELETE ON fine_payment_corrections
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);

        $this->creditoDelPago(anulado: "\n                       AND NOT EXISTS (SELECT 1 FROM bank_transaction_allocations reversa WHERE reversa.reversal_of_id = b.id)");
        $this->guardaDePagos("OLD.status = 'applied' AND NEW.status = 'voided'");
        $this->guardarNoDelegables([...self::NO_DELEGABLES, 'multas.corregir-pagos']);
    }

    public function down(): void
    {
        $sinRastro = DB::table('fine_payment_corrections')->exists()
            || DB::table('fine_payment_voids')->whereIn('reason_code', ['medium_change', 'wrong_bank_credit', 'credit_of_another_box'])->exists()
            || DB::table('fine_payments')->where('status', 'voided')->whereNotIn('medium', ['cash', 'cheque'])->exists()
            || DB::table('financial_events')->where('event_type', 'rejected_cheque_voided')->exists();

        if ($sinRastro) {
            throw new RuntimeException('Hay pagos corregidos o cheques rechazados anulados: sin estas guardas, ese registro no se sostiene.');
        }

        $this->guardarNoDelegables(self::NO_DELEGABLES);
        $this->guardaDePagos("OLD.status = 'applied' AND NEW.status = 'voided' AND NEW.medium IN ('cash', 'cheque')");
        $this->creditoDelPago(anulado: '');

        $tipos = self::TIPOS;
        $motivos = self::MOTIVOS;

        DB::unprepared(<<<SQL
            DROP TRIGGER fine_payment_corrections_append_only ON fine_payment_corrections;
            DROP TRIGGER fine_payment_corrections_coherent ON fine_payment_corrections;
            DROP FUNCTION fine_payment_corrections_coherent();

            CREATE OR REPLACE FUNCTION fund_receipts_replacement_matches() RETURNS trigger AS \$\$
            DECLARE
                v_anterior RECORD;
            BEGIN
                SELECT medium, amount, currency, cash_box_id, cheque_status INTO v_anterior
                  FROM fund_receipts WHERE id = NEW.replaces_fund_receipt_id FOR UPDATE;

                IF v_anterior.cheque_status IS DISTINCT FROM 'rejected' THEN
                    RAISE EXCEPTION 'Solo se reemplaza un cheque rechazado.' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.medium <> 'cheque' OR NEW.amount <> v_anterior.amount OR NEW.currency <> v_anterior.currency
                    OR NEW.cash_box_id IS DISTINCT FROM v_anterior.cash_box_id
                    OR NEW.cheque_status IS DISTINCT FROM 'in_custody' THEN
                    RAISE EXCEPTION 'Un cheque rechazado se reemplaza por otro cheque del mismo importe, en custodia: % y no %.',
                        v_anterior.amount, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_payment_voids_settled() RETURNS trigger AS \$\$
            BEGIN
                IF (SELECT status FROM fine_payments WHERE id = NEW.fine_payment_id) <> 'voided' THEN
                    RAISE EXCEPTION 'La anulación del pago % no lo dejó anulado.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications a
                      JOIN fund_receipts r ON r.id = a.fund_receipt_id
                     WHERE a.fine_payment_id = NEW.fine_payment_id AND r.reversal_event_id = NEW.receipt_reversal_event_id
                ) THEN
                    RAISE EXCEPTION 'La anulación del pago % no revirtió su recepción.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_payment_voids_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_pago RECORD;
                v_aplicacion RECORD;
                v_recepcion RECORD;
            BEGIN
                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                SELECT status, medium INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF v_pago.status <> 'applied' OR v_pago.medium NOT IN ('cash', 'cheque') THEN
                    RAISE EXCEPTION 'Se anula un cobro de la caja ya aplicado.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT fund_receipt_id, application_event_id INTO v_aplicacion
                  FROM fine_payment_applications WHERE fine_payment_id = NEW.fine_payment_id;

                SELECT id, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = v_aplicacion.fund_receipt_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM cash_to_bank_transfer_items i
                      JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                     WHERE i.fund_receipt_id = v_recepcion.id AND t.status <> 'cancelled'
                ) THEN
                    RAISE EXCEPTION 'Ese cobro ya se depositó: el dinero entró y no se anula.' USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM cheque_rejections WHERE fund_receipt_root(fund_receipt_id) = v_recepcion.id) THEN
                    RAISE EXCEPTION 'El cheque de ese cobro se rechazó: se regulariza, no se anula.' USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.application_reversal_event_id AND reversal_of_id = v_aplicacion.application_event_id AND status = 'posted'
                ) OR NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.receipt_reversal_event_id AND reversal_of_id = v_recepcion.financial_event_id AND status = 'posted'
                ) THEN
                    RAISE EXCEPTION 'La anulación se asienta revirtiendo la aplicación y la recepción del cobro.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            ALTER TABLE fine_payment_voids
                DROP CONSTRAINT fine_payment_voids_reason_check,
                ADD CONSTRAINT fine_payment_voids_reason_check CHECK (
                    reason_code IN ({$motivos})
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code <> 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_payment_voids RENAME COLUMN funds_exit_event_id TO receipt_reversal_event_id;

            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN ({$tipos}));
        SQL);

        Schema::drop('fine_payment_corrections');
    }

    /**
     * `fine_payment_application_bank_credit`, de `2026_10_22_030000`: lo revertido ya no respalda nada.
     *
     * @param  literal-string  $anulado
     */
    private function creditoDelPago(string $anulado): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fine_payment_application_bank_credit() RETURNS trigger AS \$\$
            DECLARE
                v_medio text;
                v_evento bigint;
                v_imputaciones int;
                v_credito bigint;
                v_importe numeric(19,2);
            BEGIN
                SELECT medium INTO v_medio FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF v_medio NOT IN ('bank_transfer', 'direct_deposit', 'echeq') THEN
                    RETURN NEW;
                END IF;

                SELECT financial_event_id INTO v_evento FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT count(*), min(bank_transaction_id) INTO v_imputaciones, v_credito
                  FROM bank_transaction_allocations
                 WHERE financial_event_id = v_evento AND reversal_of_id IS NULL;

                IF v_imputaciones <> 1 THEN
                    RAISE EXCEPTION 'Un pago por el banco se aplica con un solo crédito del extracto.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT amount INTO v_importe FROM bank_transactions WHERE id = v_credito FOR UPDATE;

                IF v_importe <> NEW.amount THEN
                    RAISE EXCEPTION 'El pago es por el crédito entero: % y no %.', v_importe, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM fine_payment_applications a
                      JOIN fund_receipts r ON r.id = a.fund_receipt_id
                      JOIN bank_transaction_allocations b
                        ON b.financial_event_id = r.financial_event_id AND b.reversal_of_id IS NULL
                     WHERE b.bank_transaction_id = v_credito{$anulado}
                ) THEN
                    RAISE EXCEPTION 'Ese crédito ya respalda el pago de una multa: una transferencia es de una sola multa.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }

    /**
     * `fine_payments_guard`, de `2026_11_04_000000`, con otra salida para un pago aplicado.
     *
     * @param  literal-string  $anular
     */
    private function guardaDePagos(string $anular): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS \$\$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status IN ('discarded', 'voided') THEN
                        RAISE EXCEPTION 'Un pago no nace descartado ni anulado.' USING ERRCODE = 'check_violation';
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
                    -- Una corrección reemplaza a un pago de la misma multa y del mismo tipo, ya descartado.
                    IF NEW.replaces_fine_payment_id IS NOT NULL THEN
                        SELECT fine_id, status, appeal_kind IS NOT NULL AS es_recurso, presented_at, presented_precision
                          INTO v_anterior
                          FROM fine_payments WHERE id = NEW.replaces_fine_payment_id;
                        IF v_anterior.fine_id <> NEW.fine_id OR v_anterior.status <> 'discarded'
                            OR v_anterior.es_recurso <> (NEW.appeal_kind IS NOT NULL) THEN
                            RAISE EXCEPTION 'Una corrección reemplaza a un pago descartado de la misma multa y del mismo tipo.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                        -- Corrige los datos de un informado: nace informada y conserva su
                        -- presentación, que es lo que decide el término.
                        IF NEW.status <> 'reported'
                            OR NEW.presented_at IS DISTINCT FROM v_anterior.presented_at
                            OR NEW.presented_precision IS DISTINCT FROM v_anterior.presented_precision THEN
                            RAISE EXCEPTION 'Una corrección nace informada y conserva la presentación del pago que reemplaza.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT ((OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                        OR ({$anular})) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason, NEW.discard_reason_code)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason, OLD.discard_reason_code) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }

    /**
     * La guarda de `2026_10_12_010000` con la lista de permisos no delegables.
     *
     * @param  list<string>  $reservados
     */
    private function guardarNoDelegables(array $reservados): void
    {
        $lista = implode(', ', array_map(fn (string $v): string => "'{$v}'", $reservados));

        DB::statement(str_replace('{reservados}', $lista, <<<'SQL'
            CREATE OR REPLACE FUNCTION role_has_permissions_guard_non_delegable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name IN ({reservados}))
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name IN ('administrador', 'super-admin')) THEN
                    RAISE EXCEPTION 'Este permiso no se delega: lo tiene solo el administrador.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL));
    }
};
