<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo cobrado y aplicado a una obligación, y lo que la excede — DER físico de
 * Multas §6.4.
 *
 * Dos cuentas de atribución, no de ubicación: el dinero sigue donde estaba
 * —caja, custodia, banco— y lo que cambia es de quién se sabe que es. Por
 * eso ningún cierre las mira para cuadrar la caja.
 *
 * - `COLLECTED_FUNDS`: recaudado y aplicado a una obligación.
 * - `PENDING_EXCESS`: recibido de más; no se devuelve ni se acredita solo
 *   (M05).
 *
 * Y dos hechos: `funds_applied` (lo sin identificar pasa a una obligación) y
 * `excess_reclassified` (un ajuste de la obligación cambia cuánto de lo ya
 * cobrado es excedente). `funds_applied` y no `funds_allocated`: varios
 * triggers de Haberes leen ese tipo suponiendo una cuota detrás.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_account_check;
            ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
                CHECK (account_code IN (
                    'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                    'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE',
                    'COLLECTED_FUNDS', 'PENDING_EXCESS'
                ));

            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN (
                    'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated',
                    'funds_applied', 'excess_reclassified'
                ));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN (
                    'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated'
                ));

            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_account_check;
            ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
                CHECK (account_code IN (
                    'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                    'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE'
                ));
        SQL);
    }
};
