<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un cierre bloquea solamente los movimientos de su moneda.
 *
 * `financial_events` no lleva moneda porque un asiento puede contener más
 * de una, cada una balanceada por separado. La guarda anterior corría antes
 * de insertar las líneas y, por lo tanto, solo podía mirar caja y fecha: un
 * cierre ARS terminaba cerrando también USD.
 *
 * La comprobación pasa a ser diferida. Al confirmar la transacción todas las
 * líneas existen y PostgreSQL puede cruzar cada moneda realmente afectada
 * contra su propio cierre. Sigue siendo una restricción de base: escribir
 * por fuera de `PostJournalEntry` no permite saltearla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS financial_events_period_open ON financial_events');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_period_open() RETURNS trigger AS $$
            DECLARE
                cierre RECORD;
            BEGIN
                IF NEW.cash_box_id IS NULL THEN
                    RETURN NULL;
                END IF;

                IF NEW.status = 'draft' THEN
                    RETURN NULL;
                END IF;

                -- Una edición administrativa de un hecho ya posteado no
                -- agrega un movimiento. En cambio, pasar de borrador a
                -- posteado sí debe validar las líneas que ahora impactan.
                IF TG_OP = 'UPDATE'
                    AND NEW.event_date = OLD.event_date
                    AND NEW.cash_box_id IS NOT DISTINCT FROM OLD.cash_box_id
                    AND NOT (OLD.status = 'draft' AND NEW.status = 'posted')
                THEN
                    RETURN NULL;
                END IF;

                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND NEW.event_date BETWEEN period_from AND period_to
                   AND EXISTS (
                        SELECT 1
                          FROM journal_lines
                         WHERE financial_event_id = NEW.id
                           AND journal_lines.currency = period_closings.currency
                   )
                 ORDER BY period_to DESC
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'El periodo % en % del % al % ya esta cerrado: no admite movimientos con fecha %.',
                        cierre.period_type, cierre.currency, cierre.period_from, cierre.period_to, NEW.event_date
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'closed'
                   AND period_from > NEW.event_date
                   AND EXISTS (
                        SELECT 1
                          FROM journal_lines
                         WHERE financial_event_id = NEW.id
                           AND journal_lines.currency = period_closings.currency
                   )
                 ORDER BY period_from
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'Un movimiento del % en % cambiaria el saldo inicial del cierre % del % al %, que ya esta cerrado.',
                        NEW.event_date, cierre.currency, cierre.period_type, cierre.period_from, cierre.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER financial_events_period_open
            AFTER INSERT OR UPDATE ON financial_events
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION financial_events_period_open()');

        /*
         * También se controla la inserción tardía de líneas. Aunque la
         * aplicación construye cada asiento en una sola transacción, esta
         * segunda guarda evita que SQL directo agregue un par balanceado a
         * un evento posteado y eluda el control que disparó su alta.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_period_open() RETURNS trigger AS $$
            DECLARE
                evento RECORD;
                cierre RECORD;
            BEGIN
                SELECT cash_box_id, event_date, status
                  INTO evento
                  FROM financial_events
                 WHERE id = NEW.financial_event_id;

                IF evento.cash_box_id IS NULL OR evento.status = 'draft' THEN
                    RETURN NULL;
                END IF;

                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = evento.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'closed'
                   AND evento.event_date BETWEEN period_from AND period_to
                 ORDER BY period_to DESC
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'El periodo % en % del % al % ya esta cerrado: no admite movimientos con fecha %.',
                        cierre.period_type, cierre.currency, cierre.period_from, cierre.period_to, evento.event_date
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT period_type, period_from, period_to, currency
                  INTO cierre
                  FROM period_closings
                 WHERE cash_box_id = evento.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'closed'
                   AND period_from > evento.event_date
                 ORDER BY period_from
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'Un movimiento del % en % cambiaria el saldo inicial del cierre % del % al %, que ya esta cerrado.',
                        evento.event_date, cierre.currency, cierre.period_type, cierre.period_from, cierre.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_period_open
            AFTER INSERT ON journal_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION journal_lines_period_open()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_period_open ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS journal_lines_period_open()');
        DB::statement('DROP TRIGGER IF EXISTS financial_events_period_open ON financial_events');

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

        DB::statement('CREATE TRIGGER financial_events_period_open
            BEFORE INSERT OR UPDATE ON financial_events
            FOR EACH ROW EXECUTE FUNCTION financial_events_period_open()');
    }
};
