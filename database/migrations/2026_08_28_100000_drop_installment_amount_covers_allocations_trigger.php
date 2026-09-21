<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La sobre-asignación deja de ser imposible y pasa a ser visible.
 *
 * El trigger que se retira impedía bajarle el importe a una cuota por
 * debajo de lo que ya tenía imputado. La intención era buena —evitar que
 * quedara plata sin dueño— pero el efecto era un muro: el operador que
 * había cargado mal un importe chocaba con un error y no tenía por dónde
 * salir, porque la operación que faltaba era desasignar y no existía.
 *
 * **El área decidió lo contrario y tiene razón**: la cuota se corrige
 * entera mientras el expediente esté en su poder. Si al corregirla queda
 * plata de más imputada, eso no se prohíbe: se **muestra** —«sobre-asignada
 * en $102.250»— y se ofrece devolverla al pozo de no identificados con
 * `UnallocateFunds`.
 *
 * Lo que se pierde es una barrera; lo que se gana es que el estado sea
 * legible y tenga salida. Esconderlo era lo peligroso: `remaining()`
 * recorta los negativos a cero, así que sin el aviso la cuota decía
 * «financiada por completo» con plata de más adentro.
 *
 * **`allocation_within_installment` no se toca.** Sigue impidiendo asignar
 * más de lo que la cuota espera, que es el invariante 2 del §11 tal como
 * está escrito: se controla al imputar. Lo que se retira es la extensión
 * al otro movimiento, que era mía y no del DER.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS installment_amount_covers_allocations ON beneficiary_installments');
        DB::statement('DROP FUNCTION IF EXISTS installment_amount_covers_allocations()');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION installment_amount_covers_allocations() RETURNS trigger AS $$
            DECLARE
                total_asignado NUMERIC(19,2);
            BEGIN
                IF NEW.expected_amount >= OLD.expected_amount THEN
                    RETURN NEW;
                END IF;

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
};
