<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los motivos de un cambio de importe en Aranceles (reglas A25 y A26).
 *
 * - El sistema ya sabe si se cobró de más o de menos, comparando con lo calculado:
 *   desaparecen `less_accepted` y `more_accepted`.
 * - El redondeo no tiene tope: no circulan billetes menores de $ 50, y el de $ 1 no
 *   servía para nada real. Decisión del usuario, sin relevar con el área.
 *
 * Si hubiera renglones con un motivo que desaparece, la migración falla: no se les
 * inventa otro motivo. Se resuelven a mano antes de volver a correrla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charge_items
                DROP CONSTRAINT fee_charge_items_rounding_is_small,
                DROP CONSTRAINT fee_charge_items_change_reason_code_check,
                ADD CONSTRAINT fee_charge_items_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'exception', 'other'));

            ALTER TABLE fee_charge_item_adjustments
                DROP CONSTRAINT fee_charge_item_adjustments_rounding_is_small,
                DROP CONSTRAINT fee_charge_item_adjustments_change_reason_code_check,
                ADD CONSTRAINT fee_charge_item_adjustments_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'exception', 'other'));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charge_items
                DROP CONSTRAINT fee_charge_items_change_reason_code_check,
                ADD CONSTRAINT fee_charge_items_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'less_accepted', 'more_accepted', 'exception', 'other')),
                ADD CONSTRAINT fee_charge_items_rounding_is_small
                    CHECK (change_reason_code IS DISTINCT FROM 'rounding' OR abs(original_amount - calculated_amount) < 1);

            ALTER TABLE fee_charge_item_adjustments
                DROP CONSTRAINT fee_charge_item_adjustments_change_reason_code_check,
                ADD CONSTRAINT fee_charge_item_adjustments_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'less_accepted', 'more_accepted', 'exception', 'other')),
                ADD CONSTRAINT fee_charge_item_adjustments_rounding_is_small
                    CHECK (change_reason_code <> 'rounding' OR abs(new_amount - previous_amount) < 1);
        SQL);
    }
};
