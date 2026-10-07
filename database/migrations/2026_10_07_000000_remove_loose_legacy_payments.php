<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
return new class extends Migration
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
};
