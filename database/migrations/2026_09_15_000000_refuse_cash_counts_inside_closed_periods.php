<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No se arquea un día que ya está cerrado.
 *
 * El cierre congela la jornada, y el arqueo es parte de ella. Un conteo
 * cargado después dejaba la planilla ya emitida diciendo una cosa y el
 * último arqueo del día diciendo otra; y como el cierre lee el último, al
 * reabrir y recerrar tomaría como respaldo un conteo que nadie revisó
 * contra ese snapshot.
 *
 * Es el tercer lado de la misma regla que ya imponen los otros dos
 * triggers: no se cierra sin arqueo, no se cierra con una diferencia viva,
 * y no se arquea lo ya cerrado.
 *
 * **Solo en `INSERT`.** El propio cierre marca como `closed` los arqueos
 * del período que cierra, y eso es un `UPDATE` que ocurre cuando la fila
 * de `period_closings` ya existe: un trigger que también mirara los
 * `UPDATE` haría que el cierre se rechazara a sí mismo. Un borrador
 * tampoco puede quedar adentro de un período cerrado, porque cerrar con
 * un arqueo en borrador ya está prohibido.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_counts_period_open() RETURNS trigger AS $$
            DECLARE
                desde DATE;
                hasta DATE;
            BEGIN
                SELECT period_from, period_to
                  INTO desde, hasta
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'closed'
                   AND NEW.counted_on BETWEEN period_from AND period_to
                 ORDER BY period_to DESC
                 LIMIT 1;

                IF desde IS NOT NULL THEN
                    RAISE EXCEPTION
                        'El % pertenece a un periodo cerrado (% al %): hay que reabrirlo para volver a contar.',
                        NEW.counted_on, desde, hasta
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER cash_counts_period_open
            BEFORE INSERT ON cash_counts
            FOR EACH ROW EXECUTE FUNCTION cash_counts_period_open()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_counts_period_open ON cash_counts');
        DB::statement('DROP FUNCTION IF EXISTS cash_counts_period_open()');
    }
};
