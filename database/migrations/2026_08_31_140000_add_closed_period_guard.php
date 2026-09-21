<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un período cerrado no admite operaciones retroactivas.
 *
 * Regla 3 del §9.9 e invariante 30. Es la regla que le da sentido al
 * cierre: si después de cerrar el 02/06 alguien puede asentar un cobro con
 * fecha 02/06, el snapshot que el área archivó deja de describir lo que la
 * base contiene, y la planilla firmada pasa a ser un papel que dice otra
 * cosa que el sistema.
 *
 * **Va en la base y no en un Action**, por la razón de siempre: un Action
 * lo verifica cuando alguien se acuerda de llamarlo, y este sistema tiene
 * jobs en cola, importaciones de extractos y seeders. El único lugar donde
 * la regla no se puede saltear es acá.
 *
 * Se guarda `financial_events` y no `journal_lines` porque las líneas
 * heredan la fecha del evento: cerrar la puerta de arriba cierra las dos.
 *
 * **Un evento sin caja no se frena**, porque no hay forma de saber a qué
 * cierre pertenece. Hoy todo el circuito de Haberes lleva `cash_box_id`;
 * si algún día aparece un evento sin caja que deba respetar un cierre, la
 * columna tiene que dejar de ser nullable antes que endurecerse esto.
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

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER financial_events_period_open
            BEFORE INSERT OR UPDATE ON financial_events
            FOR EACH ROW EXECUTE FUNCTION financial_events_period_open()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS financial_events_period_open ON financial_events');
        DB::statement('DROP FUNCTION IF EXISTS financial_events_period_open()');
    }
};
