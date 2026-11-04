<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El catálogo de aranceles y sus precios con vigencia (reglas A10 a A12).
 *
 * El tipo es un maestro que se edita y se audita. El precio es un hecho: no se
 * edita ni se borra, se agrega otro con su fecha. Uno mal cargado se anula con
 * su motivo, y queda a la vista.
 *
 * Todo tipo tiene al menos un precio vigente en el historial: un tipo sin
 * precio no se podría cobrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE fee_types (
                id bigserial PRIMARY KEY,
                code smallint NOT NULL UNIQUE CHECK (code > 0),
                decree_letter varchar(2) CHECK (decree_letter ~ '^[a-z]{1,2}$'),
                name varchar(300) NOT NULL CHECK (btrim(name) <> ''),
                calculation_mode varchar(20) NOT NULL CHECK (calculation_mode IN ('unit', 'percentage')),
                unit_label varchar(40) CHECK (unit_label IS NULL OR btrim(unit_label) <> ''),
                is_active boolean NOT NULL DEFAULT true,
                created_at timestamptz,
                updated_at timestamptz,
                CONSTRAINT fee_types_unit_label_only_on_unit CHECK (calculation_mode = 'unit' OR unit_label IS NULL)
            );

            CREATE UNIQUE INDEX fee_types_decree_letter_unique ON fee_types (decree_letter)
                WHERE decree_letter IS NOT NULL;

            CREATE TABLE fee_type_prices (
                id bigserial PRIMARY KEY,
                fee_type_id bigint NOT NULL REFERENCES fee_types (id) ON DELETE RESTRICT,
                valid_from date NOT NULL,
                unit_price numeric(19, 2) CHECK (unit_price > 0),
                rate numeric(7, 4) CHECK (rate > 0 AND rate <= 100),
                legal_reference varchar(200) CHECK (legal_reference IS NULL OR btrim(legal_reference) <> ''),
                created_by bigint REFERENCES users (id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                voided_at timestamptz,
                voided_by bigint REFERENCES users (id) ON DELETE SET NULL,
                void_reason varchar(300),
                CONSTRAINT fee_type_prices_one_value CHECK ((unit_price IS NULL) <> (rate IS NULL)),
                CONSTRAINT fee_type_prices_void_complete CHECK (
                    (voided_at IS NULL AND void_reason IS NULL)
                    OR (voided_at IS NOT NULL AND btrim(coalesce(void_reason, '')) <> '')
                )
            );

            -- Un precio vigente por tipo y fecha: el anulado no ocupa su lugar.
            CREATE UNIQUE INDEX fee_type_prices_type_date_unique ON fee_type_prices (fee_type_id, valid_from)
                WHERE voided_at IS NULL;

            -- El modo decide qué valor lleva el precio: cambiarlo reinterpretaría los ya cargados.
            CREATE OR REPLACE FUNCTION fee_types_mode_frozen() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM fee_type_prices WHERE fee_type_id = NEW.id) THEN
                    RAISE EXCEPTION 'El tipo de arancel % ya tiene precios: su modo de cálculo no cambia.', NEW.code
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_types_mode_frozen BEFORE UPDATE OF calculation_mode ON fee_types
                FOR EACH ROW WHEN (NEW.calculation_mode IS DISTINCT FROM OLD.calculation_mode)
                EXECUTE FUNCTION fee_types_mode_frozen();

            CREATE OR REPLACE FUNCTION fee_type_price_matches_mode() RETURNS trigger AS $$
            DECLARE
                v_modo text;
            BEGIN
                -- Bloqueado: dos altas o una anulación a la vez se ordenan.
                SELECT calculation_mode INTO v_modo FROM fee_types WHERE id = NEW.fee_type_id FOR UPDATE;

                IF (v_modo = 'unit' AND NEW.unit_price IS NULL) OR (v_modo = 'percentage' AND NEW.rate IS NULL) THEN
                    RAISE EXCEPTION 'El precio no corresponde al modo de cálculo del tipo de arancel.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_type_price_matches_mode BEFORE INSERT ON fee_type_prices
                FOR EACH ROW EXECUTE FUNCTION fee_type_price_matches_mode();

            -- Un precio no se edita ni se borra: solo se anula, una vez.
            CREATE OR REPLACE FUNCTION fee_type_prices_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un precio de arancel no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL
                    OR NEW.voided_at IS NULL
                    OR NEW.fee_type_id IS DISTINCT FROM OLD.fee_type_id
                    OR NEW.valid_from IS DISTINCT FROM OLD.valid_from
                    OR NEW.unit_price IS DISTINCT FROM OLD.unit_price
                    OR NEW.rate IS DISTINCT FROM OLD.rate
                    OR NEW.legal_reference IS DISTINCT FROM OLD.legal_reference
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                THEN
                    RAISE EXCEPTION 'Un precio de arancel no se edita: se agrega otro, o se anula el mal cargado.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                PERFORM 1 FROM fee_types WHERE id = OLD.fee_type_id FOR UPDATE;

                IF NOT EXISTS (
                    SELECT 1 FROM fee_type_prices
                     WHERE fee_type_id = OLD.fee_type_id AND id <> OLD.id AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'Es el único precio del tipo de arancel: primero cargá el correcto.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_type_prices_append_only BEFORE UPDATE OR DELETE ON fee_type_prices
                FOR EACH ROW EXECUTE FUNCTION fee_type_prices_append_only();

            -- Diferido: el tipo y su primer precio se escriben en dos sentencias.
            CREATE OR REPLACE FUNCTION fee_types_have_a_price() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM fee_type_prices WHERE fee_type_id = NEW.id AND voided_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'El tipo de arancel % no tiene precio.', NEW.code
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fee_types_have_a_price AFTER INSERT ON fee_types
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                EXECUTE FUNCTION fee_types_have_a_price();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE fee_type_prices;
            DROP TABLE fee_types;
            DROP FUNCTION fee_types_have_a_price();
            DROP FUNCTION fee_type_prices_append_only();
            DROP FUNCTION fee_type_price_matches_mode();
            DROP FUNCTION fee_types_mode_frozen();
        SQL);
    }
};
