<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El asiento sellado al postear, y siempre de una caja.
 *
 * Hasta acá la base frenaba editar o borrar una línea, pero no agregar una
 * a un asiento ya posteado: un par balanceado nuevo cambiaba un saldo
 * cerrado sin dejar reversión. El estado del evento también se esquivaba
 * (`posted → reversed → draft`), y un evento podía no tener caja —o tener
 * líneas de otra—, con lo que se salteaba la apertura y el cierre.
 *
 * - un evento nace en borrador, y solo pasa de `draft` a `posted` y de
 *   `posted` a `reversed`;
 * - las líneas se insertan solo con el evento en borrador. El trigger lee el
 *   evento con `FOR SHARE`: la FK solo toma `FOR KEY SHARE`, que no choca con
 *   el `UPDATE` de `status`, y sin esto otra transacción podía postear entre
 *   la lectura y el insert;
 * - fuera de borrador, el autor, el posteo, la descripción y el motivo de
 *   reversión quedan congelados, salvo el `SET NULL` de las FK a usuarios;
 * - la caja es obligatoria en el evento y en la línea, y la de la línea es la
 *   del evento (FK compuesta).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
                sin_caja int;
                lineas_sin_caja int;
                otra_caja int;
            BEGIN
                SELECT count(*) INTO sin_caja FROM financial_events WHERE cash_box_id IS NULL;
                SELECT count(*) INTO lineas_sin_caja FROM journal_lines WHERE cash_box_id IS NULL;
                SELECT count(*) INTO otra_caja
                  FROM journal_lines l JOIN financial_events e ON e.id = l.financial_event_id
                 WHERE l.cash_box_id IS DISTINCT FROM e.cash_box_id;

                IF sin_caja + lineas_sin_caja + otra_caja > 0 THEN
                    RAISE EXCEPTION 'No se puede exigir la caja del asiento: hay % eventos sin caja, % líneas sin caja y % líneas de otra caja que la de su evento. Hay que corregirlos antes.',
                        sin_caja, lineas_sin_caja, otra_caja;
                END IF;
            END;
            $$;

            ALTER TABLE financial_events ALTER COLUMN cash_box_id SET NOT NULL;
            ALTER TABLE journal_lines ALTER COLUMN cash_box_id SET NOT NULL;
            ALTER TABLE financial_events
                ADD CONSTRAINT financial_events_id_cash_box_unique UNIQUE (id, cash_box_id);
            ALTER TABLE journal_lines
                ADD CONSTRAINT journal_lines_event_cash_box_foreign
                FOREIGN KEY (financial_event_id, cash_box_id)
                REFERENCES financial_events (id, cash_box_id);

            CREATE OR REPLACE FUNCTION financial_events_born_draft() RETURNS trigger AS $$
            BEGIN
                IF NEW.status <> 'draft' THEN
                    RAISE EXCEPTION 'Un evento nace en borrador: se postea después de cargar su asiento.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER financial_events_born_draft
                BEFORE INSERT ON financial_events
                FOR EACH ROW EXECUTE FUNCTION financial_events_born_draft();

            CREATE OR REPLACE FUNCTION journal_lines_sealed() RETURNS trigger AS $$
            DECLARE
                estado text;
            BEGIN
                SELECT status INTO estado
                  FROM financial_events
                 WHERE id = NEW.financial_event_id
                   FOR SHARE;

                IF estado IS NOT NULL AND estado <> 'draft' THEN
                    RAISE EXCEPTION 'El asiento del evento % ya está %: no admite líneas nuevas, se revierte.',
                        NEW.financial_event_id, estado
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_sealed
                BEFORE INSERT ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_lines_sealed();
        SQL);

        DB::unprepared(self::APPEND_ONLY_WITH_TRANSITIONS);
    }

    public function down(): void
    {
        DB::unprepared(self::APPEND_ONLY_PREVIOUS);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_sealed ON journal_lines;
            DROP FUNCTION IF EXISTS journal_lines_sealed();
            DROP TRIGGER IF EXISTS financial_events_born_draft ON financial_events;
            DROP FUNCTION IF EXISTS financial_events_born_draft();

            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_event_cash_box_foreign;
            ALTER TABLE financial_events DROP CONSTRAINT financial_events_id_cash_box_unique;
            ALTER TABLE journal_lines ALTER COLUMN cash_box_id DROP NOT NULL;
            ALTER TABLE financial_events ALTER COLUMN cash_box_id DROP NOT NULL;
        SQL);
    }

    private const APPEND_ONLY_WITH_TRANSITIONS = <<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un hecho monetario no se borra: se revierte con otro evento.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.event_type IS DISTINCT FROM OLD.event_type
                    OR NEW.event_date IS DISTINCT FROM OLD.event_date
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id
                THEN
                    RAISE EXCEPTION 'Los datos de un hecho monetario no se editan.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Solo hacia adelante: se postea un borrador y se revierte un
                -- posteado. Volver a borrador reabriría el asiento sellado.
                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT ((OLD.status = 'draft' AND NEW.status = 'posted')
                          OR (OLD.status = 'posted' AND NEW.status = 'reversed'))
                THEN
                    RAISE EXCEPTION 'Un evento % no pasa a %: se postea un borrador y se revierte un posteado.',
                        OLD.status, NEW.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Posteado, el evento queda como se posteó. De sus autores solo
                -- se admite el SET NULL de la FK cuando se borra el usuario, que
                -- llega desde el trigger de la FK (profundidad > 1).
                IF OLD.status <> 'draft' AND (
                    NEW.posted_at IS DISTINCT FROM OLD.posted_at
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.reversal_reason IS DISTINCT FROM OLD.reversal_reason
                    OR (
                        (NEW.created_by IS DISTINCT FROM OLD.created_by
                            OR NEW.posted_by IS DISTINCT FROM OLD.posted_by)
                        AND NOT (
                            pg_trigger_depth() > 1
                            AND (NEW.created_by IS NULL OR NEW.created_by IS NOT DISTINCT FROM OLD.created_by)
                            AND (NEW.posted_by IS NULL OR NEW.posted_by IS NOT DISTINCT FROM OLD.posted_by)
                        )
                    )
                )
                THEN
                    RAISE EXCEPTION 'Un evento % no se edita: se revierte.', OLD.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** La de `2026_08_16_110000_create_financial_events_table`, para el `down()`. */
    private const APPEND_ONLY_PREVIOUS = <<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un hecho monetario no se borra: se revierte con otro evento.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.event_type IS DISTINCT FROM OLD.event_type
                    OR NEW.event_date IS DISTINCT FROM OLD.event_date
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id
                THEN
                    RAISE EXCEPTION 'Los datos de un hecho monetario no se editan.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Un evento posteado no vuelve a borrador: ese camino es la
                -- reversion, que deja rastro.
                IF OLD.status = 'posted' AND NEW.status = 'draft' THEN
                    RAISE EXCEPTION 'Un evento posteado no vuelve a borrador: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;
};
