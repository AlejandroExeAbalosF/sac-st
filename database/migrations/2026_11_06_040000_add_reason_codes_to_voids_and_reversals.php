<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El código del motivo, en su propia columna, para poder filtrar y contar.
 *
 * El texto sigue guardando la lectura completa: `receipts` y `fund_receipts` las
 * usan varios módulos, y Shared y Ledger no conocen las etiquetas de cada uno.
 * Por eso esas dos validan solo la forma del código; la lista la valida cada
 * módulo. `funding_allocations` es solo de Haberes y lleva la suya.
 *
 * Lo ya anulado o revertido queda en `other`: con ese código la lectura es el
 * detalle, así que el texto guardado sigue diciendo exactamente lo mismo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE receipts ADD COLUMN void_reason_code varchar(60);
            UPDATE receipts SET void_reason_code = 'other' WHERE status IN ('voided', 'replaced');
            ALTER TABLE receipts ADD CONSTRAINT receipts_void_reason_code_check
                CHECK (
                    (status IN ('voided', 'replaced')) = (void_reason_code IS NOT NULL)
                    AND (void_reason_code IS NULL OR void_reason_code ~ '^[a-z0-9_]+$')
                );

            ALTER TABLE fund_receipts ADD COLUMN reversal_reason_code varchar(60);
            UPDATE fund_receipts SET reversal_reason_code = 'other' WHERE reversal_event_id IS NOT NULL;
            ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_reversal_reason_code_check
                CHECK (
                    (reversal_event_id IS NOT NULL) = (reversal_reason_code IS NOT NULL)
                    AND (reversal_reason_code IS NULL OR reversal_reason_code ~ '^[a-z0-9_]+$')
                );

            ALTER TABLE funding_allocations ADD COLUMN reversal_reason_code varchar(60);
            UPDATE funding_allocations SET reversal_reason_code = 'other' WHERE allocation_kind = 'reversal';
            ALTER TABLE funding_allocations ADD CONSTRAINT funding_allocations_reversal_reason_code_check
                CHECK (
                    (allocation_kind = 'reversal') = (reversal_reason_code IS NOT NULL)
                    AND (reversal_reason_code IS NULL OR reversal_reason_code IN (
                        'wrong_amount', 'wrong_installment', 'wrong_expediente',
                        'duplicate', 'data_entry_error', 'other'
                    ))
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE funding_allocations DROP COLUMN reversal_reason_code;
            ALTER TABLE fund_receipts DROP COLUMN reversal_reason_code;
            ALTER TABLE receipts DROP COLUMN void_reason_code;
        SQL);
    }
};
