<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una cuota no puede esperar menos de lo que ya tiene asignado.
 *
 * El invariante 2 del §11 estaba impuesto **por un solo lado**:
 * `allocation_within_installment` compara la suma de asignaciones contra
 * `expected_amount` cada vez que se asigna. Pero nada miraba el otro
 * movimiento — bajarle el importe a una cuota **después** de financiada
 * dejaba asignados $602.250 sobre una cuota que decía esperar $500.000, y
 * el trigger que debía impedirlo no se enteraba porque no se tocó ninguna
 * asignación.
 *
 * El agujero no era teórico: la pantalla de edición de la cuota permite
 * corregir el importe y no preguntaba nada sobre la financiación.
 *
 * Va en la base y no solo en el Action por la razón de siempre: el Action
 * da el mensaje legible, la base impide el desastre. Cualquier camino
 * nuevo hacia esta columna —una corrección masiva, un script— queda
 * cubierto sin que nadie tenga que acordarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION installment_amount_covers_allocations() RETURNS trigger AS $$
            DECLARE
                total_asignado NUMERIC(19,2);
            BEGIN
                IF NEW.expected_amount >= OLD.expected_amount THEN
                    RETURN NEW;
                END IF;

                /*
                 * La misma definicion de «asignado» que usa el trigger de
                 * las asignaciones: las reversiones restan y el excedente
                 * de redondeo no cuenta, porque por diseño excede.
                 */
                SELECT COALESCE(SUM(
                    CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END
                ), 0)
                INTO total_asignado
                FROM funding_allocations
                WHERE beneficiary_installment_id = NEW.id
                  AND allocation_kind <> 'cash_rounding_surplus';

                IF NEW.expected_amount < total_asignado THEN
                    RAISE EXCEPTION
                        'La cuota ya tiene % asignados: no puede pasar a esperar %.',
                        total_asignado, NEW.expected_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER installment_amount_covers_allocations
            BEFORE UPDATE OF expected_amount ON beneficiary_installments
            FOR EACH ROW EXECUTE FUNCTION installment_amount_covers_allocations()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS installment_amount_covers_allocations ON beneficiary_installments');
        DB::statement('DROP FUNCTION IF EXISTS installment_amount_covers_allocations()');
    }
};
