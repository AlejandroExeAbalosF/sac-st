<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada moneda corta los papeles históricos en su propia apertura.
 *
 * Pesos y dólares son cajas separadas, y cada una se abre cuando le toca:
 * los dólares, recién cuando llegan dólares. El corte de la carga
 * histórica era la primera apertura de cualquier moneda, así que la de
 * pesos cortaba también los papeles de un haber en dólares: un recibo
 * manual en dólares de un día en que los dólares todavía se llevaban a
 * mano no se podía cargar.
 *
 * Ahora el papel se compara contra la apertura de la moneda de su haber,
 * y abrir una moneda solo tiene que ser posterior a los papeles de esa
 * misma moneda.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date(p_currency char(3)) RETURNS date AS $$
                SELECT o.opened_on
                  FROM cash_book_openings o
                  JOIN cash_boxes cb ON cb.id = o.cash_box_id
                 WHERE cb.code = 'haberes'
                   AND o.currency = p_currency
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_paper_before_opening() RETURNS trigger AS $$
            DECLARE
                v_apertura DATE;
                v_fecha DATE;
                v_moneda CHAR(3);
            BEGIN
                IF TG_TABLE_NAME = 'legacy_documents' THEN
                    v_fecha := NEW.issued_on;
                ELSE
                    v_fecha := NEW.paid_on;
                END IF;

                IF v_fecha IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT currency INTO v_moneda FROM haberes WHERE id = NEW.haber_id;
                v_apertura := haberes_opening_date(v_moneda);

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'La caja de Haberes todavía no tiene apertura en %: sin ella no se puede saber si un papel es anterior al sistema.', v_moneda
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_fecha >= v_apertura THEN
                    RAISE EXCEPTION 'La fecha % no es anterior a la apertura de la caja en % (%).', v_fecha, v_moneda, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_book_opening_after_legacy_papers() RETURNS trigger AS $$
            DECLARE
                v_papel DATE;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cash_boxes WHERE id = NEW.cash_box_id AND code = 'haberes') THEN
                    RETURN NEW;
                END IF;

                SELECT MAX(fecha) INTO v_papel FROM (
                    SELECT d.issued_on AS fecha
                      FROM legacy_documents d
                      JOIN haberes h ON h.id = d.haber_id
                     WHERE d.voided_at IS NULL AND h.currency = NEW.currency
                    UNION ALL
                    SELECT s.paid_on
                      FROM legacy_settlements s
                      JOIN haberes h ON h.id = s.haber_id
                     WHERE s.voided_at IS NULL AND s.paid_on IS NOT NULL AND h.currency = NEW.currency
                ) AS papeles;

                IF v_papel IS NOT NULL AND NEW.opened_on <= v_papel THEN
                    RAISE EXCEPTION 'Hay papeles del sistema anterior en % fechados hasta el %: la apertura tiene que ser posterior.', NEW.currency, v_papel
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS haberes_opening_date();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION haberes_opening_date() RETURNS date AS $$
                SELECT MIN(o.opened_on)
                  FROM cash_book_openings o
                  JOIN cash_boxes cb ON cb.id = o.cash_box_id
                 WHERE cb.code = 'haberes'
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_paper_before_opening() RETURNS trigger AS $$
            DECLARE
                v_apertura DATE;
                v_fecha DATE;
            BEGIN
                IF TG_TABLE_NAME = 'legacy_documents' THEN
                    v_fecha := NEW.issued_on;
                ELSE
                    v_fecha := NEW.paid_on;
                END IF;

                IF v_fecha IS NULL THEN
                    RETURN NEW;
                END IF;

                v_apertura := haberes_opening_date();

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'La caja de Haberes todavía no tiene apertura: sin ella no se puede saber si un papel es anterior al sistema.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_fecha >= v_apertura THEN
                    RAISE EXCEPTION 'La fecha % no es anterior a la apertura de la caja (%).', v_fecha, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

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

            DROP FUNCTION IF EXISTS haberes_opening_date(char);
        SQL);
    }
};
