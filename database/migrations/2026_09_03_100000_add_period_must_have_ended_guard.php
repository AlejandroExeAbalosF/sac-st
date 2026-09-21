<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un período que todavía no terminó no se cierra.
 *
 * ─── El agujero que tapa ─────────────────────────────────────────────────
 *
 * El controlador solo exigía que la **fecha** no fuera futura, y para el
 * cierre diario alcanza. Para el mensual no: cerrar con la fecha de hoy
 * —3 de septiembre— produce un período que va del 1 al 30, y a partir de
 * ahí `financial_events_period_open` rechaza **cada operación de los días
 * que faltan**. La caja queda sin poder emitir un recibo por el resto del
 * mes, con un error de base de datos en la cara.
 *
 * Es la diferencia entre un cierre equivocado —que se reabre— y una caja
 * que no admite trabajar.
 *
 * ─── Por qué el margen de un día ─────────────────────────────────────────
 *
 * La comprobación exacta vive en `ClosePeriod`, que usa el reloj de la
 * aplicación. Acá se compara contra `CURRENT_DATE`, que es el del motor, y
 * **los dos no siempre coinciden**: la aplicación corre en UTC y la sesión
 * de Postgres en `America/Buenos_Aires`, tres horas atrás. Entre las nueve
 * de la noche y la medianoche local, PHP ya está en el día siguiente y la
 * base no.
 *
 * Sin margen, esa franja rechazaría el cierre diario del propio día —que es
 * exactamente cuando el área lo hace—. Con un día de tolerancia el caso
 * legítimo nunca se traba y el desastre sigue siendo imposible: un mensual
 * en curso se pasa de la tolerancia por semanas.
 *
 * Es el reparto de siempre: el Action da el mensaje exacto y legible, la
 * base impide el desastre.
 *
 * ─── Por qué la función va entera dos veces ──────────────────────────────
 *
 * `CREATE OR REPLACE` no admite parches, así que cada versión se escribe
 * completa. Armarla concatenando un fragmento sería además una cadena
 * construida para `unprepared()`, que exige `literal-string` justamente
 * para que nadie tome la costumbre.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger AS $$
            DECLARE
                pendientes INT;
            BEGIN
                IF NEW.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                /*
                 * Reabrir y volver a cerrar pasa por acá igual, y tiene que
                 * pasar: entre un cierre y el siguiente pudo aparecer un
                 * borrador que antes no estaba.
                 */

                -- 0 · El periodo tiene que haber terminado
                IF NEW.period_to > CURRENT_DATE + 1 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo que termina el %: esa fecha todavia no llego.', NEW.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 1 · Eventos en borrador
                SELECT count(*) INTO pendientes
                  FROM financial_events
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'draft'
                   AND event_date BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % asiento(s) en borrador adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 2 · Arqueos sin resolver
                SELECT count(*) INTO pendientes
                  FROM cash_counts
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'draft'
                   AND counted_on BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % arqueo(s) sin resolver adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 3 · Extractos a medio importar que tocan el periodo
                SELECT count(*) INTO pendientes
                  FROM bank_statement_imports
                 WHERE status IN ('uploaded', 'parsing')
                   AND (
                        period_from IS NULL
                        OR (period_from <= NEW.period_to AND period_to >= NEW.period_from)
                   );

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo mientras se importa un extracto con movimientos suyos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    /** La versión anterior, sin la guarda 0. */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger AS $$
            DECLARE
                pendientes INT;
            BEGIN
                IF NEW.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                -- 1 · Eventos en borrador
                SELECT count(*) INTO pendientes
                  FROM financial_events
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'draft'
                   AND event_date BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % asiento(s) en borrador adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 2 · Arqueos sin resolver
                SELECT count(*) INTO pendientes
                  FROM cash_counts
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'draft'
                   AND counted_on BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % arqueo(s) sin resolver adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 3 · Extractos a medio importar que tocan el periodo
                SELECT count(*) INTO pendientes
                  FROM bank_statement_imports
                 WHERE status IN ('uploaded', 'parsing')
                   AND (
                        period_from IS NULL
                        OR (period_from <= NEW.period_to AND period_to >= NEW.period_from)
                   );

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo mientras se importa un extracto con movimientos suyos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
