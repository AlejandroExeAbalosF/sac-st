<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un asiento anterior no puede dejar desactualizado un cierre posterior.
 *
 * La apertura de cada cierre es el saldo del libro a su víspera, así que
 * un movimiento con fecha anterior cambia ese saldo y deja al cierre
 * guardado diciendo otra cosa que el libro.
 *
 * Cerrar el 15 dejando el 14 abierto es legítimo y el sistema lo permite:
 * los días son independientes. Lo que no puede pasar es cargar después un
 * recibo en el 14 —que sigue abierto, así que la guarda de período
 * cerrado no lo alcanzaba— y que el cierre del 15 quede mintiendo por ese
 * importe, en silencio y con su planilla posiblemente ya impresa.
 *
 * Se comprobó que pasaba: cerrando el 15 en 1.150.000 y cargando 777.000
 * en el 14, el cierre seguía diciendo 1.150.000 y el libro 1.927.000.
 *
 * La salida es reabrir el cierre posterior, cargar el movimiento y volver
 * a cerrar: ahí el snapshot se recalcula.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_period_open() RETURNS trigger AS $$
            DECLARE
                cierre RECORD;
            BEGIN
                IF NEW.cash_box_id IS NULL THEN
                    RETURN NEW;
                END IF;

                -- Reasentar la misma fecha sobre un evento que ya existia no
                -- agrega nada al periodo: lo que se persigue es la operacion
                -- nueva y el cambio de fecha hacia adentro de un cierre.
                IF TG_OP = 'UPDATE'
                    AND NEW.event_date = OLD.event_date
                    AND NEW.cash_box_id IS NOT DISTINCT FROM OLD.cash_box_id
                THEN
                    RETURN NEW;
                END IF;

                SELECT period_type, period_from, period_to INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND NEW.event_date BETWEEN period_from AND period_to
                 ORDER BY period_to DESC
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'El periodo % del % al % ya esta cerrado: no admite movimientos con fecha %.',
                        cierre.period_type, cierre.period_from, cierre.period_to, NEW.event_date
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 * Y tampoco uno que invalide un cierre posterior. Se nombra
                 * el mas antiguo: es el que hay que reabrir, y reabriendolo
                 * caen los que le siguen.
                 */
                SELECT period_type, period_from, period_to INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND period_from > NEW.event_date
                 ORDER BY period_from
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'Un movimiento del % cambiaria el saldo inicial del cierre % del % al %, que ya esta cerrado.',
                        NEW.event_date, cierre.period_type, cierre.period_from, cierre.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_period_open() RETURNS trigger AS $$
            DECLARE
                cierre RECORD;
            BEGIN
                IF NEW.cash_box_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE'
                    AND NEW.event_date = OLD.event_date
                    AND NEW.cash_box_id IS NOT DISTINCT FROM OLD.cash_box_id
                THEN
                    RETURN NEW;
                END IF;

                SELECT period_type, period_from, period_to INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND NEW.event_date BETWEEN period_from AND period_to
                 ORDER BY period_to DESC
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'El periodo % del % al % ya esta cerrado: no admite movimientos con fecha %.',
                        cierre.period_type, cierre.period_from, cierre.period_to, NEW.event_date
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
