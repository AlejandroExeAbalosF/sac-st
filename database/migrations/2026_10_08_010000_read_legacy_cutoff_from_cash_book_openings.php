<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El corte de los papeles históricos sale de la apertura, no de su asiento.
 *
 * `haberes_opening_date()` buscaba el asiento `opening_balance`, y la guarda
 * que impide abrir después de un papel ya cargado colgaba del mismo
 * asiento. Desde `cash_book_openings` la apertura puede existir sin
 * asiento —se abre declarando que no había nada—, y en ese caso la carga
 * histórica respondía «la caja todavía no tiene apertura» aunque la
 * tuviera, y la guarda no se disparaba.
 *
 * Las dos pasan a mirar la fila de apertura. Para los datos existentes no
 * cambia nada: cada apertura con asiento tiene su fila, con la misma fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(o.opened_on)
                  FROM cash_book_openings o
                  JOIN cash_boxes cb ON cb.id = o.cash_box_id
                 WHERE cb.code = 'haberes'
            $$ LANGUAGE sql STABLE;

            DROP TRIGGER IF EXISTS financial_events_opening_after_legacy_papers ON financial_events;
            DROP FUNCTION IF EXISTS opening_after_legacy_papers();

            CREATE OR REPLACE FUNCTION cash_book_opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                    UNION ALL
                    SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.opened_on <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior fechados hasta el %: la apertura tiene que ser posterior.', v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_book_openings_after_legacy_papers
                BEFORE INSERT ON cash_book_openings
                FOR EACH ROW EXECUTE FUNCTION cash_book_opening_after_legacy_papers();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cash_book_openings_after_legacy_papers ON cash_book_openings;
            DROP FUNCTION IF EXISTS cash_book_opening_after_legacy_papers();

            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(fe.event_date)
                  FROM financial_events fe
                  JOIN cash_boxes cb ON cb.id = fe.cash_box_id
                 WHERE cb.code = 'haberes'
                   AND fe.event_type = 'opening_balance'
                   AND fe.status = 'posted'
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                    UNION ALL
                    SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.event_date <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior fechados hasta el %: la apertura tiene que ser posterior.', v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER financial_events_opening_after_legacy_papers
                BEFORE INSERT OR UPDATE OF status ON financial_events
                FOR EACH ROW
                WHEN (NEW.event_type = 'opening_balance' AND NEW.status = 'posted')
                EXECUTE FUNCTION opening_after_legacy_papers();
        SQL);
    }
};
