<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidación cronológica del proceso. Cada bloque conserva la migración
 * original para mantener visibles sus reglas y permitir el rollback inverso.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->migrations() as $migration) {
            $this->run($migration, 'up');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->migrations()) as $migration) {
            $this->run($migration, 'down');
        }
    }

    private function run(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];

        if (! is_callable($callback)) {
            throw new LogicException("La migración consolidada no tiene {$method}().");
        }

        $callback();
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        return [
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
            // Migración original: 2026_11_04_020000_create_fee_types_tables.php
            new class extends Migration
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
            },

            /**
             * Los cobros de Aranceles: renglones, ajustes, el dinero que los respalda, sus
             * recibos y sus correcciones (DER físico §3 y §6).
             *
             * Un cobro no tiene saldo propio: el total es la suma de los renglones, cada
             * uno con su último importe. Lo vinculado a una recepción, pendiente o pagado,
             * no puede superar su importe: dos cobros no reservan el mismo dinero.
             *
             * Un cobro con recibo no se edita: se anula, se reemplaza su recibo o se corrige
             * con otro cobro, y el anterior queda a la vista.
             */
            // Migración original: 2026_11_04_030000_create_fee_charges_tables.php
            new class extends Migration
            {
                private const string MOTIVOS_DE_IMPORTE = "'rounding', 'less_accepted', 'more_accepted', 'exception', 'other'";

                public function up(): void
                {
                    $motivos = self::MOTIVOS_DE_IMPORTE;

                    DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION aranceles_cash_box_id() RETURNS bigint AS \$\$
                SELECT id FROM cash_boxes WHERE code = 'aranceles'
            \$\$ LANGUAGE sql STABLE;

            ALTER TABLE fee_type_prices ADD CONSTRAINT fee_type_prices_id_type_unique UNIQUE (id, fee_type_id);

            CREATE TABLE fee_charges (
                id bigserial PRIMARY KEY,
                payer_name varchar(200) NOT NULL CHECK (btrim(payer_name) <> ''),
                payer_cuit char(11) CHECK (payer_cuit ~ '^[0-9]{11}$'),
                medium varchar(10) NOT NULL CHECK (medium IN ('cash', 'bank')),
                status varchar(10) NOT NULL CHECK (status IN ('pending', 'paid', 'voided')),
                requested_on date NOT NULL,
                pricing_date date NOT NULL,
                paid_on date,
                idempotency_key varchar(120) NOT NULL UNIQUE,
                created_by bigint REFERENCES users (id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                voided_at timestamptz,
                voided_by bigint REFERENCES users (id) ON DELETE SET NULL,
                void_reason_code varchar(30),
                void_reason varchar(300),
                CONSTRAINT fee_charges_paid_has_date CHECK (status <> 'paid' OR paid_on IS NOT NULL),
                CONSTRAINT fee_charges_void_complete CHECK (
                    (status = 'voided') = (voided_at IS NOT NULL)
                    AND (voided_at IS NULL) = (void_reason_code IS NULL)
                    AND (void_reason_code IS DISTINCT FROM 'other' OR btrim(coalesce(void_reason, '')) <> '')
                )
            );

            CREATE INDEX fee_charges_status_index ON fee_charges (status, paid_on);

            CREATE TABLE fee_charge_items (
                id bigserial PRIMARY KEY,
                fee_charge_id bigint NOT NULL REFERENCES fee_charges (id) ON DELETE RESTRICT,
                line_number smallint NOT NULL CHECK (line_number > 0),
                fee_type_id bigint NOT NULL,
                fee_type_price_id bigint NOT NULL,
                code_snapshot smallint NOT NULL,
                name_snapshot varchar(300) NOT NULL,
                calculation_mode_snapshot varchar(20) NOT NULL CHECK (calculation_mode_snapshot IN ('unit', 'percentage')),
                unit_label_snapshot varchar(40),
                quantity integer CHECK (quantity > 0),
                unit_price numeric(19, 2) CHECK (unit_price > 0),
                rate numeric(7, 4) CHECK (rate > 0),
                base_amount numeric(19, 2) CHECK (base_amount > 0),
                calculated_amount numeric(19, 2) NOT NULL CHECK (calculated_amount >= 0),
                original_amount numeric(19, 2) NOT NULL CHECK (original_amount > 0),
                change_reason_code varchar(30) CHECK (change_reason_code IN ({$motivos})),
                change_reason varchar(300),
                UNIQUE (fee_charge_id, line_number),
                FOREIGN KEY (fee_type_price_id, fee_type_id) REFERENCES fee_type_prices (id, fee_type_id) ON DELETE RESTRICT,
                CONSTRAINT fee_charge_items_values_of_mode CHECK (
                    (calculation_mode_snapshot = 'unit' AND quantity IS NOT NULL AND unit_price IS NOT NULL AND rate IS NULL AND base_amount IS NULL)
                    OR (calculation_mode_snapshot = 'percentage' AND quantity IS NULL AND unit_price IS NULL AND rate IS NOT NULL AND base_amount IS NOT NULL)
                ),
                CONSTRAINT fee_charge_items_change_has_reason CHECK (
                    original_amount = calculated_amount OR change_reason_code IS NOT NULL
                ),
                CONSTRAINT fee_charge_items_rounding_is_small CHECK (
                    change_reason_code IS DISTINCT FROM 'rounding' OR abs(original_amount - calculated_amount) < 1
                ),
                CONSTRAINT fee_charge_items_reason_detail CHECK (
                    change_reason_code IS NULL OR change_reason_code NOT IN ('exception', 'other')
                    OR btrim(coalesce(change_reason, '')) <> ''
                )
            );

            CREATE TABLE fee_charge_item_adjustments (
                id bigserial PRIMARY KEY,
                fee_charge_item_id bigint NOT NULL REFERENCES fee_charge_items (id) ON DELETE RESTRICT,
                previous_amount numeric(19, 2) NOT NULL,
                new_amount numeric(19, 2) NOT NULL CHECK (new_amount > 0),
                change_reason_code varchar(30) NOT NULL CHECK (change_reason_code IN ({$motivos})),
                change_reason varchar(300),
                adjusted_by bigint REFERENCES users (id) ON DELETE SET NULL,
                adjusted_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fee_charge_item_adjustments_rounding_is_small CHECK (
                    change_reason_code <> 'rounding' OR abs(new_amount - previous_amount) < 1
                ),
                CONSTRAINT fee_charge_item_adjustments_reason_detail CHECK (
                    change_reason_code NOT IN ('exception', 'other') OR btrim(coalesce(change_reason, '')) <> ''
                )
            );

            CREATE INDEX fee_charge_item_adjustments_item_index ON fee_charge_item_adjustments (fee_charge_item_id, id);

            CREATE TABLE fee_charge_fundings (
                id bigserial PRIMARY KEY,
                fee_charge_id bigint NOT NULL REFERENCES fee_charges (id) ON DELETE RESTRICT,
                fund_receipt_id bigint NOT NULL REFERENCES fund_receipts (id) ON DELETE RESTRICT,
                amount numeric(19, 2) NOT NULL CHECK (amount > 0),
                application_event_id bigint UNIQUE REFERENCES financial_events (id) ON DELETE RESTRICT,
                application_reversal_event_id bigint UNIQUE REFERENCES financial_events (id) ON DELETE RESTRICT,
                released_at timestamptz,
                released_by bigint REFERENCES users (id) ON DELETE SET NULL,
                created_by bigint REFERENCES users (id) ON DELETE SET NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fee_charge_fundings_reversal_needs_application CHECK (
                    application_reversal_event_id IS NULL OR application_event_id IS NOT NULL
                ),
                CONSTRAINT fee_charge_fundings_released_is_reversed CHECK (
                    released_at IS NULL OR application_event_id IS NULL OR application_reversal_event_id IS NOT NULL
                )
            );

            CREATE INDEX fee_charge_fundings_charge_index ON fee_charge_fundings (fee_charge_id);
            CREATE INDEX fee_charge_fundings_receipt_index ON fee_charge_fundings (fund_receipt_id);

            CREATE TABLE fee_charge_receipts (
                id bigserial PRIMARY KEY,
                fee_charge_id bigint NOT NULL REFERENCES fee_charges (id) ON DELETE RESTRICT,
                receipt_id bigint NOT NULL UNIQUE REFERENCES receipts (id) ON DELETE RESTRICT,
                snapshot jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX fee_charge_receipts_charge_index ON fee_charge_receipts (fee_charge_id);

            CREATE TABLE fee_charge_corrections (
                id bigserial PRIMARY KEY,
                corrected_charge_id bigint NOT NULL UNIQUE REFERENCES fee_charges (id) ON DELETE RESTRICT,
                new_charge_id bigint NOT NULL UNIQUE REFERENCES fee_charges (id) ON DELETE RESTRICT,
                reason_code varchar(30) NOT NULL,
                reason varchar(300),
                corrected_by bigint REFERENCES users (id) ON DELETE SET NULL,
                corrected_at timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT fee_charge_corrections_distinct CHECK (corrected_charge_id <> new_charge_id),
                CONSTRAINT fee_charge_corrections_other_detail CHECK (
                    reason_code <> 'other' OR btrim(coalesce(reason, '')) <> ''
                )
            );

            -- El importe vigente de cada renglón: el último ajuste o el original.
            CREATE OR REPLACE VIEW fee_charge_item_amounts AS
                SELECT i.id AS fee_charge_item_id, i.fee_charge_id,
                       coalesce((
                           SELECT a.new_amount FROM fee_charge_item_adjustments a
                            WHERE a.fee_charge_item_id = i.id
                            ORDER BY a.id DESC LIMIT 1
                       ), i.original_amount) AS amount
                  FROM fee_charge_items i;

            CREATE OR REPLACE VIEW fee_charge_balances AS
                SELECT c.id,
                       coalesce((SELECT sum(v.amount) FROM fee_charge_item_amounts v WHERE v.fee_charge_id = c.id), 0)::numeric(19, 2) AS total,
                       coalesce((SELECT sum(f.amount) FROM fee_charge_fundings f WHERE f.fee_charge_id = c.id AND f.released_at IS NULL), 0)::numeric(19, 2) AS funded
                  FROM fee_charges c;

            -- Un cobro no se borra, y de sus datos solo avanza el estado.
            CREATE OR REPLACE FUNCTION fee_charges_guard() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un cobro de arancel no se borra: se anula.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.payer_name IS DISTINCT FROM OLD.payer_name
                    OR NEW.payer_cuit IS DISTINCT FROM OLD.payer_cuit
                    OR NEW.medium IS DISTINCT FROM OLD.medium
                    OR NEW.requested_on IS DISTINCT FROM OLD.requested_on
                    OR NEW.pricing_date IS DISTINCT FROM OLD.pricing_date
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.paid_on IS NOT NULL AND NEW.paid_on IS DISTINCT FROM OLD.paid_on)
                THEN
                    RAISE EXCEPTION 'Los datos de un cobro de arancel no se editan: se corrige con otro cobro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'pending' AND NEW.status IN ('paid', 'voided'))
                    AND NOT (OLD.status = 'paid' AND NEW.status = 'voided')
                THEN
                    RAISE EXCEPTION 'Un cobro de arancel % no pasa a %.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charges_guard BEFORE UPDATE OR DELETE ON fee_charges
                FOR EACH ROW EXECUTE FUNCTION fee_charges_guard();

            -- Lo que se copió al renglón es la versión del precio vigente ese día.
            CREATE OR REPLACE FUNCTION fee_charge_item_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_precio RECORD;
                v_fecha date;
            BEGIN
                SELECT pricing_date INTO v_fecha FROM fee_charges WHERE id = NEW.fee_charge_id;
                SELECT p.valid_from, p.unit_price, p.rate, p.voided_at, t.calculation_mode INTO v_precio
                  FROM fee_type_prices p
                  JOIN fee_types t ON t.id = p.fee_type_id
                 WHERE p.id = NEW.fee_type_price_id;

                IF v_precio.voided_at IS NOT NULL OR v_precio.valid_from > v_fecha OR EXISTS (
                    SELECT 1 FROM fee_type_prices o
                     WHERE o.fee_type_id = NEW.fee_type_id AND o.voided_at IS NULL
                       AND o.valid_from <= v_fecha AND o.valid_from > v_precio.valid_from
                ) THEN
                    RAISE EXCEPTION 'El renglón no usa el precio vigente el %.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.calculation_mode_snapshot IS DISTINCT FROM v_precio.calculation_mode
                    OR NEW.unit_price IS DISTINCT FROM v_precio.unit_price
                    OR NEW.rate IS DISTINCT FROM v_precio.rate
                THEN
                    RAISE EXCEPTION 'El renglón no coincide con la versión del precio que cita.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_item_coherent BEFORE INSERT ON fee_charge_items
                FOR EACH ROW EXECUTE FUNCTION fee_charge_item_coherent();

            CREATE OR REPLACE FUNCTION fee_rows_append_only() RETURNS trigger AS \$\$
            BEGIN
                RAISE EXCEPTION 'Una fila de % no se edita ni se borra.', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_items_append_only BEFORE UPDATE OR DELETE ON fee_charge_items
                FOR EACH ROW EXECUTE FUNCTION fee_rows_append_only();
            CREATE TRIGGER fee_charge_item_adjustments_append_only BEFORE UPDATE OR DELETE ON fee_charge_item_adjustments
                FOR EACH ROW EXECUTE FUNCTION fee_rows_append_only();
            CREATE TRIGGER fee_charge_receipts_append_only BEFORE UPDATE OR DELETE ON fee_charge_receipts
                FOR EACH ROW EXECUTE FUNCTION fee_rows_append_only();
            CREATE TRIGGER fee_charge_corrections_append_only BEFORE UPDATE OR DELETE ON fee_charge_corrections
                FOR EACH ROW EXECUTE FUNCTION fee_rows_append_only();

            -- Se ajusta un cobro pendiente, sobre el importe vigente.
            CREATE OR REPLACE FUNCTION fee_charge_item_adjustment_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_estado text;
                v_vigente numeric(19, 2);
            BEGIN
                SELECT c.status INTO v_estado
                  FROM fee_charge_items i JOIN fee_charges c ON c.id = i.fee_charge_id
                 WHERE i.id = NEW.fee_charge_item_id
                   FOR UPDATE OF c;

                IF v_estado <> 'pending' THEN
                    RAISE EXCEPTION 'Solo se ajusta un cobro pendiente: uno con recibo se corrige.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT amount INTO v_vigente FROM fee_charge_item_amounts WHERE fee_charge_item_id = NEW.fee_charge_item_id;

                IF NEW.previous_amount <> v_vigente THEN
                    RAISE EXCEPTION 'El importe vigente del renglón es %, no %.', v_vigente, NEW.previous_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_item_adjustment_coherent BEFORE INSERT ON fee_charge_item_adjustments
                FOR EACH ROW EXECUTE FUNCTION fee_charge_item_adjustment_coherent();

            -- El dinero que respalda un cobro: de esta caja, por su medio y sin usar dos veces.
            CREATE OR REPLACE FUNCTION fee_charge_funding_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_recepcion RECORD;
                v_medio text;
                v_vinculado numeric(19, 2);
            BEGIN
                -- Bloqueada antes de sumar: dos vínculos a la vez se ordenan y el segundo ve al primero.
                SELECT amount, origin, currency, cash_box_id, medium, reversal_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id FOR UPDATE;
                SELECT medium INTO v_medio FROM fee_charges WHERE id = NEW.fee_charge_id;

                IF v_recepcion.cash_box_id IS DISTINCT FROM aranceles_cash_box_id()
                    OR v_recepcion.origin <> 'received'
                    OR v_recepcion.currency <> 'ARS'
                    OR v_recepcion.medium <> v_medio
                THEN
                    RAISE EXCEPTION 'La recepción no respalda este cobro: tiene que ser de la caja de Aranceles, en pesos, por el mismo medio y recibida por el circuito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_recepcion.reversal_event_id IS NOT NULL THEN
                    RAISE EXCEPTION 'La recepción % está revertida: no respalda un cobro.', NEW.fund_receipt_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT coalesce(sum(amount), 0) INTO v_vinculado
                  FROM fee_charge_fundings
                 WHERE fund_receipt_id = NEW.fund_receipt_id AND released_at IS NULL;

                IF v_vinculado + NEW.amount > v_recepcion.amount THEN
                    RAISE EXCEPTION 'La recepción es de % y ya tiene % vinculados a otros cobros.', v_recepcion.amount, v_vinculado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_funding_coherent BEFORE INSERT ON fee_charge_fundings
                FOR EACH ROW EXECUTE FUNCTION fee_charge_funding_coherent();

            /*
             * El vínculo solo avanza, una vez cada paso. Completar el cobro agrega
             * el evento a la misma fila: no se cuenta dos veces.
             */
            CREATE OR REPLACE FUNCTION fee_charge_fundings_guard() RETURNS trigger AS \$\$
            DECLARE
                v_lineas RECORD;
                v_evento RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un vínculo de un cobro con su dinero no se borra: se libera.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.fee_charge_id IS DISTINCT FROM OLD.fee_charge_id
                    OR NEW.fund_receipt_id IS DISTINCT FROM OLD.fund_receipt_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.application_event_id IS NOT NULL AND NEW.application_event_id IS DISTINCT FROM OLD.application_event_id)
                    OR (OLD.application_reversal_event_id IS NOT NULL AND NEW.application_reversal_event_id IS DISTINCT FROM OLD.application_reversal_event_id)
                    OR (OLD.released_at IS NOT NULL AND NEW.released_at IS DISTINCT FROM OLD.released_at)
                    OR (OLD.released_at IS NOT NULL AND NEW.application_event_id IS DISTINCT FROM OLD.application_event_id)
                THEN
                    RAISE EXCEPTION 'Un vínculo de un cobro con su dinero solo avanza: se aplica y se libera.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.application_event_id IS NULL AND NEW.application_event_id IS NOT NULL THEN
                    SELECT event_type, status, cash_box_id INTO v_evento FROM financial_events WHERE id = NEW.application_event_id;

                    IF v_evento.event_type <> 'funds_applied' OR v_evento.status <> 'posted'
                        OR v_evento.cash_box_id IS DISTINCT FROM aranceles_cash_box_id()
                    THEN
                        RAISE EXCEPTION 'La aplicación se asienta como fondos aplicados de la caja de Aranceles.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT coalesce(sum(debit) FILTER (WHERE account_code = 'UNASSIGNED_FUNDS'), 0) AS sin_identificar,
                           coalesce(sum(credit) FILTER (WHERE account_code = 'COLLECTED_FUNDS'), 0) AS aplicado,
                           count(*) FILTER (WHERE currency <> 'ARS'
                               OR account_code NOT IN ('UNASSIGNED_FUNDS', 'COLLECTED_FUNDS')
                               OR (account_code = 'UNASSIGNED_FUNDS' AND credit > 0)
                               OR (account_code = 'COLLECTED_FUNDS' AND debit > 0)) AS ajenas
                      INTO v_lineas
                      FROM journal_lines WHERE financial_event_id = NEW.application_event_id;

                    IF v_lineas.ajenas > 0 OR v_lineas.sin_identificar <> NEW.amount OR v_lineas.aplicado <> NEW.amount THEN
                        RAISE EXCEPTION 'El asiento no coincide con la aplicación: debita lo sin identificar y acredita lo recaudado por el mismo importe.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_fundings_guard BEFORE UPDATE OR DELETE ON fee_charge_fundings
                FOR EACH ROW EXECUTE FUNCTION fee_charge_fundings_guard();

            -- Una recepción con vínculos vigentes no se revierte: dejaría el cobro sin dinero.
            CREATE OR REPLACE FUNCTION fund_receipts_keep_fee_fundings() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fee_charge_fundings WHERE fund_receipt_id = NEW.id AND released_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'La recepción % respalda un cobro de arancel: primero se libera.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_keep_fee_fundings BEFORE UPDATE OF reversal_event_id ON fund_receipts
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_keep_fee_fundings();

            -- Lo cobrado por un arancel no financia una cuota de Haberes.
            CREATE OR REPLACE FUNCTION funding_allocations_not_from_aranceles() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts
                     WHERE id = NEW.fund_receipt_id AND cash_box_id = aranceles_cash_box_id()
                ) THEN
                    RAISE EXCEPTION 'Una recepción de la caja de Aranceles no se asigna a una cuota de Haberes.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER funding_allocations_not_from_aranceles BEFORE INSERT ON funding_allocations
                FOR EACH ROW EXECUTE FUNCTION funding_allocations_not_from_aranceles();

            -- El recibo es de esta caja y por el total del cobro: un reemplazo no cambia el importe.
            CREATE OR REPLACE FUNCTION fee_charge_receipt_coherent() RETURNS trigger AS \$\$
            DECLARE
                v_recibo RECORD;
                v_total numeric(19, 2);
            BEGIN
                SELECT cash_box_id, amount INTO v_recibo FROM receipts WHERE id = NEW.receipt_id;
                SELECT total INTO v_total FROM fee_charge_balances WHERE id = NEW.fee_charge_id;

                IF v_recibo.cash_box_id IS DISTINCT FROM aranceles_cash_box_id() THEN
                    RAISE EXCEPTION 'El recibo no es de la caja de Aranceles.' USING ERRCODE = 'check_violation';
                END IF;

                IF v_recibo.amount <> v_total THEN
                    RAISE EXCEPTION 'El recibo es por % y el cobro por %.', v_recibo.amount, v_total
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_receipt_coherent BEFORE INSERT ON fee_charge_receipts
                FOR EACH ROW EXECUTE FUNCTION fee_charge_receipt_coherent();

            -- Un recibo de la caja de Aranceles documenta un cobro.
            CREATE OR REPLACE FUNCTION receipts_aranceles_belong_to_a_charge() RETURNS trigger AS \$\$
            BEGIN
                IF NEW.cash_box_id = aranceles_cash_box_id() AND NOT EXISTS (
                    SELECT 1 FROM fee_charge_receipts WHERE receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'El recibo % de Aranceles no documenta ningún cobro.', NEW.formatted_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER receipts_aranceles_belong_to_a_charge AFTER INSERT ON receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                EXECUTE FUNCTION receipts_aranceles_belong_to_a_charge();

            -- Cómo tiene que quedar un cobro al terminar la transacción.
            CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS \$\$
            DECLARE
                v_cobro RECORD;
                v_saldo RECORD;
                v_recibos integer;
            BEGIN
                SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
                SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
                SELECT count(*) INTO v_recibos
                  FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
                 WHERE l.fee_charge_id = p_charge AND r.status = 'issued';

                IF v_saldo.total <= 0 THEN
                    RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
                END IF;

                IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                    RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
                END IF;

                IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0) THEN
                    RAISE EXCEPTION 'Un cobro pendiente no tiene recibo ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
                END IF;

                IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1) THEN
                    RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente y su total vinculado: % de %, con % recibos.',
                        v_saldo.funded, v_saldo.total, v_recibos USING ERRCODE = 'check_violation';
                END IF;

                IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                    RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
                END IF;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fee_charge_settled_trigger() RETURNS trigger AS \$\$
            BEGIN
                IF TG_TABLE_NAME = 'fee_charges' THEN
                    PERFORM fee_charge_settled(NEW.id);
                ELSIF TG_TABLE_NAME = 'receipts' THEN
                    PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_receipts WHERE receipt_id = NEW.id;
                ELSE
                    PERFORM fee_charge_settled(NEW.fee_charge_id);
                END IF;

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fee_charges_settled AFTER INSERT OR UPDATE ON fee_charges
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();
            CREATE CONSTRAINT TRIGGER fee_charge_fundings_settled AFTER INSERT OR UPDATE ON fee_charge_fundings
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();
            CREATE CONSTRAINT TRIGGER fee_charge_receipts_settled AFTER INSERT ON fee_charge_receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();
            CREATE CONSTRAINT TRIGGER receipts_fee_charge_settled AFTER UPDATE OF status ON receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();

            -- Un precio usado en un cobro ya no se anula, y un código usado no cambia.
            CREATE OR REPLACE FUNCTION fee_type_price_in_use() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.voided_at IS NULL AND NEW.voided_at IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fee_charge_items WHERE fee_type_price_id = OLD.id
                ) THEN
                    RAISE EXCEPTION 'Ese precio ya se usó en un cobro: no se anula. Cargá el correcto desde otra fecha.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_type_price_in_use BEFORE UPDATE ON fee_type_prices
                FOR EACH ROW EXECUTE FUNCTION fee_type_price_in_use();

            CREATE OR REPLACE FUNCTION fee_type_code_in_use() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM fee_charge_items WHERE fee_type_id = NEW.id) THEN
                    RAISE EXCEPTION 'El tipo de arancel % ya se usó en un cobro: su código no cambia.', OLD.code
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_type_code_in_use BEFORE UPDATE OF code ON fee_types
                FOR EACH ROW WHEN (NEW.code IS DISTINCT FROM OLD.code)
                EXECUTE FUNCTION fee_type_code_in_use();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER fee_type_code_in_use ON fee_types;
            DROP FUNCTION fee_type_code_in_use();
            DROP TRIGGER fee_type_price_in_use ON fee_type_prices;
            DROP FUNCTION fee_type_price_in_use();
            DROP TRIGGER receipts_fee_charge_settled ON receipts;
            DROP TRIGGER receipts_aranceles_belong_to_a_charge ON receipts;
            DROP TRIGGER funding_allocations_not_from_aranceles ON funding_allocations;
            DROP TRIGGER fund_receipts_keep_fee_fundings ON fund_receipts;
            DROP VIEW fee_charge_balances;
            DROP VIEW fee_charge_item_amounts;
            DROP TABLE fee_charge_corrections;
            DROP TABLE fee_charge_receipts;
            DROP TABLE fee_charge_fundings;
            DROP TABLE fee_charge_item_adjustments;
            DROP TABLE fee_charge_items;
            DROP TABLE fee_charges;
            DROP FUNCTION fee_charge_settled_trigger();
            DROP FUNCTION fee_charge_settled(bigint);
            DROP FUNCTION receipts_aranceles_belong_to_a_charge();
            DROP FUNCTION fee_charge_receipt_coherent();
            DROP FUNCTION funding_allocations_not_from_aranceles();
            DROP FUNCTION fund_receipts_keep_fee_fundings();
            DROP FUNCTION fee_charge_fundings_guard();
            DROP FUNCTION fee_charge_funding_coherent();
            DROP FUNCTION fee_charge_item_adjustment_coherent();
            DROP FUNCTION fee_rows_append_only();
            DROP FUNCTION fee_charge_item_coherent();
            DROP FUNCTION fee_charges_guard();
            DROP FUNCTION aranceles_cash_box_id();
            ALTER TABLE fee_type_prices DROP CONSTRAINT fee_type_prices_id_type_unique;
        SQL);
                }
            },

            /**
             * Los fondos aplicados también pueden ser de un cobro de Aranceles.
             *
             * La guarda nació con Multas y exigía un pago de una multa en todo
             * `funds_applied`: la base rechazaba cualquier cobro de otra caja. Sigue sin
             * admitir un evento huérfano.
             */
            // Migración original: 2026_11_04_040000_link_funds_applied_to_fee_charges.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_fine_events_linked() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'funds_applied' AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE application_event_id = NEW.id
                ) AND NOT EXISTS (
                    SELECT 1 FROM fee_charge_fundings WHERE application_event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Los fondos aplicados del evento % no corresponden a ningún pago.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.event_type = 'excess_reclassified' AND NOT EXISTS (
                    SELECT 1 FROM fine_excess_reclassifications WHERE event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La reclasificación del evento % no corresponde a ninguna multa.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_fine_events_linked() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'funds_applied' AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE application_event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Los fondos aplicados del evento % no corresponden a ningún pago.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.event_type = 'excess_reclassified' AND NOT EXISTS (
                    SELECT 1 FROM fine_excess_reclassifications WHERE event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La reclasificación del evento % no corresponde a ninguna multa.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }
            },

            /**
             * Una caja que no recibe cheques también cierra.
             *
             * La guarda exigía al menos una columna en el reverso, y la caja de Aranceles no
             * tiene ninguna: la base rechazaba su evidencia y el cierre se revertía entero.
             * Ahora la lista puede estar vacía solo si la evidencia no trae cheques: un
             * cheque sin columnas seguiría sin poder dibujarse.
             */
            // Migración original: 2026_11_04_050000_allow_cash_sheet_evidence_without_cheques.php
            new class extends Migration
            {
                private const string FUNCION = <<<'SQL'
        CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
        DECLARE
            v_columnas jsonb;
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                AND status = 'closed' AND evidence_version = NEW.version) THEN
                RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
            END IF;
            IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object'
                OR jsonb_typeof(NEW.payload->'schema') IS DISTINCT FROM 'number'
                -- Como texto: `2.0` también es un número, y el lector espera el entero.
                OR NEW.payload->>'schema' NOT IN ('1', '2') THEN
                RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
            END IF;
            IF NEW.payload->>'schema' = '2' THEN
                v_columnas := NEW.payload->'chequeColumns';
                IF jsonb_typeof(v_columnas) IS DISTINCT FROM 'array'
                    OR (v_columnas = '[]'::jsonb AND NEW.payload->'cheques' IS DISTINCT FROM '[]'::jsonb)
                    OR NOT (NEW.payload ? 'bankColumnNote')
                    OR jsonb_typeof(NEW.payload->'bankColumnNote') NOT IN ('string', 'null')
                    OR EXISTS (
                        SELECT 1 FROM jsonb_array_elements(v_columnas) AS c(col)
                        WHERE jsonb_typeof(col) IS DISTINCT FROM 'object'
                           OR jsonb_typeof(col->'key') IS DISTINCT FROM 'string'
                           OR jsonb_typeof(col->'header') IS DISTINCT FROM 'string'
                           OR btrim(col->>'key') = ''
                           OR btrim(col->>'header') = ''
                    )
                    OR (SELECT count(DISTINCT col->>'key') FROM jsonb_array_elements(v_columnas) AS c(col))
                        <> jsonb_array_length(v_columnas)
                THEN
                    RAISE EXCEPTION 'La evidencia del cierre tiene que congelar las columnas del reverso y la aclaracion de la columna de banco.' USING ERRCODE = 'check_violation';
                END IF;
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

                private const string ANTERIOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
        DECLARE
            v_columnas jsonb;
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                AND status = 'closed' AND evidence_version = NEW.version) THEN
                RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
            END IF;
            IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object'
                OR jsonb_typeof(NEW.payload->'schema') IS DISTINCT FROM 'number'
                OR NEW.payload->>'schema' NOT IN ('1', '2') THEN
                RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
            END IF;
            IF NEW.payload->>'schema' = '2' THEN
                v_columnas := NEW.payload->'chequeColumns';
                IF jsonb_typeof(v_columnas) IS DISTINCT FROM 'array'
                    OR jsonb_array_length(v_columnas) = 0
                    OR NOT (NEW.payload ? 'bankColumnNote')
                    OR jsonb_typeof(NEW.payload->'bankColumnNote') NOT IN ('string', 'null')
                    OR EXISTS (
                        SELECT 1 FROM jsonb_array_elements(v_columnas) AS c(col)
                        WHERE jsonb_typeof(col) IS DISTINCT FROM 'object'
                           OR jsonb_typeof(col->'key') IS DISTINCT FROM 'string'
                           OR jsonb_typeof(col->'header') IS DISTINCT FROM 'string'
                           OR btrim(col->>'key') = ''
                           OR btrim(col->>'header') = ''
                    )
                    OR (SELECT count(DISTINCT col->>'key') FROM jsonb_array_elements(v_columnas) AS c(col))
                        <> jsonb_array_length(v_columnas)
                THEN
                    RAISE EXCEPTION 'La evidencia del cierre tiene que congelar las columnas del reverso y la aclaracion de la columna de banco.' USING ERRCODE = 'check_violation';
                END IF;
            END IF;
            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

                public function up(): void
                {
                    DB::unprepared(self::FUNCION);
                }

                public function down(): void
                {
                    DB::unprepared(self::ANTERIOR);
                }
            },

            /**
             * Lo que cambia el total de un cobro también se comprueba al terminar la transacción.
             *
             * Los renglones y sus ajustes no se editan ni se borran, pero insertar uno más
             * cambiaba el total sin revalidar nada: un cobro pagado de $ 220 pasaba a $ 340 con
             * su dinero y su recibo de $ 220. Ahora esas dos tablas disparan la misma
             * comprobación, el recibo vigente tiene que documentar el total y un cobro anulado
             * no recibe renglones. El alta inicial sigue siendo atómica: la guarda es diferida.
             */
            // Migración original: 2026_11_04_060000_guard_fee_charge_lines_when_settled.php
            new class extends Migration
            {
                private const string COMPROBACION = <<<'SQL'
        CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS $$
        DECLARE
            v_cobro RECORD;
            v_saldo RECORD;
            v_recibos integer;
            v_documentado numeric(19, 2);
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*), COALESCE(sum(r.amount), 0) INTO v_recibos, v_documentado
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1 OR v_documentado <> v_saldo.total) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente por su total y su total vinculado: % de %, con % recibos por %.',
                    v_saldo.funded, v_saldo.total, v_recibos, v_documentado USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;

        CREATE OR REPLACE FUNCTION fee_charge_settled_trigger() RETURNS trigger AS $$
        BEGIN
            IF TG_TABLE_NAME = 'fee_charges' THEN
                PERFORM fee_charge_settled(NEW.id);
            ELSIF TG_TABLE_NAME = 'receipts' THEN
                PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_receipts WHERE receipt_id = NEW.id;
            ELSIF TG_TABLE_NAME = 'fee_charge_item_adjustments' THEN
                PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_items WHERE id = NEW.fee_charge_item_id;
            ELSE
                PERFORM fee_charge_settled(NEW.fee_charge_id);
            END IF;

            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

                private const string COMPROBACION_ANTERIOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS $$
        DECLARE
            v_cobro RECORD;
            v_saldo RECORD;
            v_recibos integer;
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*) INTO v_recibos
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente y su total vinculado: % de %, con % recibos.',
                    v_saldo.funded, v_saldo.total, v_recibos USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;

        CREATE OR REPLACE FUNCTION fee_charge_settled_trigger() RETURNS trigger AS $$
        BEGIN
            IF TG_TABLE_NAME = 'fee_charges' THEN
                PERFORM fee_charge_settled(NEW.id);
            ELSIF TG_TABLE_NAME = 'receipts' THEN
                PERFORM fee_charge_settled(fee_charge_id) FROM fee_charge_receipts WHERE receipt_id = NEW.id;
            ELSE
                PERFORM fee_charge_settled(NEW.fee_charge_id);
            END IF;

            RETURN NULL;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

                public function up(): void
                {
                    DB::unprepared(self::COMPROBACION);

                    DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER fee_charge_items_settled AFTER INSERT ON fee_charge_items
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();
            CREATE CONSTRAINT TRIGGER fee_charge_item_adjustments_settled AFTER INSERT ON fee_charge_item_adjustments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_charge_settled_trigger();

            -- Lo anulado queda como estaba: su total es parte de lo que se anuló.
            CREATE OR REPLACE FUNCTION fee_charge_items_not_on_voided() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM fee_charges WHERE id = NEW.fee_charge_id AND status = 'voided') THEN
                    RAISE EXCEPTION 'Un cobro anulado no recibe renglones.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_items_not_on_voided BEFORE INSERT ON fee_charge_items
                FOR EACH ROW EXECUTE FUNCTION fee_charge_items_not_on_voided();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER fee_charge_items_not_on_voided ON fee_charge_items;
            DROP FUNCTION fee_charge_items_not_on_voided();
            DROP TRIGGER fee_charge_item_adjustments_settled ON fee_charge_item_adjustments;
            DROP TRIGGER fee_charge_items_settled ON fee_charge_items;
        SQL);

                    DB::unprepared(self::COMPROBACION_ANTERIOR);
                }
            },

            /**
             * El mismo efectivo no se deposita dos veces, aunque viaje repartido (Aranceles,
             * DER físico §4.2 y §4.3).
             *
             * `cash_deposited_once` solo controla los ítems con asignación a una cuota. Un
             * ítem sin asignación quedaba sin guarda salvo en las cajas de cobros enteros.
             *
             * - Un traslado solo lleva recepciones de su propia caja, en todas las cajas.
             * - Lo vigente de una recepción de efectivo, sumado, no supera su importe. Los
             *   cheques y las cajas de cobros enteros ya lo tienen con su propia guarda.
             * - En la caja de Aranceles solo viaja efectivo vigente.
             * - Una recepción de Aranceles con un traslado vigente no se revierte.
             * - Los ítems de un traslado suman su importe, en todas las cajas.
             */
            // Migración original: 2026_11_04_070000_keep_deposited_cash_within_receipt.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_transfer_item_within_receipt() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_caja bigint;
                v_caja_recepcion bigint;
                v_vigente numeric(19, 2);
            BEGIN
                SELECT cash_box_id INTO v_caja FROM cash_to_bank_transfers WHERE id = NEW.cash_to_bank_transfer_id;
                SELECT cash_box_id INTO v_caja_recepcion FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                -- Va antes que cualquier salida: ningún ítem queda sin esta comprobación.
                IF v_caja IS DISTINCT FROM v_caja_recepcion THEN
                    RAISE EXCEPTION 'Un traslado solo lleva dinero de su propia caja.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.funding_allocation_id IS NOT NULL AND v_caja <> aranceles_cash_box_id() THEN
                    RETURN NEW;
                END IF;

                -- Dos depósitos del mismo efectivo se serializan por la recepción.
                SELECT r.amount, r.origin, r.medium, r.reversal_event_id, b.deposits_whole_receipts
                  INTO v_recepcion
                  FROM fund_receipts r
                  JOIN cash_boxes b ON b.id = r.cash_box_id
                 WHERE r.id = NEW.fund_receipt_id
                   FOR NO KEY UPDATE OF r;

                IF v_caja = aranceles_cash_box_id()
                    AND (v_recepcion.medium <> 'cash'
                        OR v_recepcion.origin NOT IN ('received', 'opening')
                        OR v_recepcion.reversal_event_id IS NOT NULL) THEN
                    RAISE EXCEPTION 'De la caja de Aranceles solo viaja su efectivo vigente.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Los cheques y las cajas de cobros enteros ya tienen su guarda, más precisa.
                IF v_recepcion.medium = 'cheque' OR v_recepcion.deposits_whole_receipts THEN
                    RETURN NEW;
                END IF;

                SELECT coalesce(sum(i.amount), 0) INTO v_vigente
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = NEW.fund_receipt_id AND t.status <> 'cancelled';

                IF v_vigente + NEW.amount > v_recepcion.amount THEN
                    RAISE EXCEPTION 'Ese efectivo ya se depositó: la recepción tiene % y viajarían %.',
                        v_recepcion.amount, v_vigente + NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfer_item_within_receipt BEFORE INSERT ON cash_to_bank_transfer_items
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_item_within_receipt();

            -- Lo que salió de la caja no se puede dar por nunca recibido.
            CREATE OR REPLACE FUNCTION fund_receipts_keep_fee_transfers() RETURNS trigger AS $$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL
                    AND NEW.cash_box_id = aranceles_cash_box_id()
                    AND EXISTS (
                        SELECT 1 FROM cash_to_bank_transfer_items i
                          JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                         WHERE i.fund_receipt_id = NEW.id AND t.status <> 'cancelled'
                    ) THEN
                    RAISE EXCEPTION 'La recepción % viajó en un traslado vigente: primero se cancela.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_keep_fee_transfers BEFORE UPDATE OF reversal_event_id ON fund_receipts
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_keep_fee_transfers();

            CREATE OR REPLACE FUNCTION cash_transfer_items_match_amount(p_transfer bigint) RETURNS void AS $$
            DECLARE
                v_importe numeric(19, 2);
                v_items numeric(19, 2);
            BEGIN
                SELECT amount INTO v_importe FROM cash_to_bank_transfers WHERE id = p_transfer;
                SELECT coalesce(sum(amount), 0) INTO v_items
                  FROM cash_to_bank_transfer_items WHERE cash_to_bank_transfer_id = p_transfer;

                IF v_importe IS DISTINCT FROM v_items THEN
                    RAISE EXCEPTION 'El traslado % es por % y sus ítems suman %.', p_transfer, v_importe, v_items
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_transfer_items_match_amount_transfer() RETURNS trigger AS $$
            BEGIN
                PERFORM cash_transfer_items_match_amount(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cash_transfer_items_match_amount_item() RETURNS trigger AS $$
            BEGIN
                PERFORM cash_transfer_items_match_amount(NEW.cash_to_bank_transfer_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Diferidos: el traslado y sus ítems se insertan en varias sentencias.
            CREATE CONSTRAINT TRIGGER cash_transfer_items_match_amount_transfer
                AFTER INSERT ON cash_to_bank_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_items_match_amount_transfer();
            CREATE CONSTRAINT TRIGGER cash_transfer_items_match_amount_item
                AFTER INSERT ON cash_to_bank_transfer_items DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_items_match_amount_item();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER cash_transfer_items_match_amount_item ON cash_to_bank_transfer_items;
            DROP TRIGGER cash_transfer_items_match_amount_transfer ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_items_match_amount_item();
            DROP FUNCTION cash_transfer_items_match_amount_transfer();
            DROP FUNCTION cash_transfer_items_match_amount(bigint);
            DROP TRIGGER fund_receipts_keep_fee_transfers ON fund_receipts;
            DROP FUNCTION fund_receipts_keep_fee_transfers();
            DROP TRIGGER cash_transfer_item_within_receipt ON cash_to_bank_transfer_items;
            DROP FUNCTION cash_transfer_item_within_receipt();
        SQL);
                }
            },

            /**
             * Toda apertura dice con qué cuenta opera su libro, en cualquier moneda.
             *
             * Sin cuenta, el renglón de depósitos directos quedaba apagado y el saldo del
             * banco afuera de una apertura que no se rehace; y sin vínculo, la caja
             * aceptaba depósitos a cualquier cuenta activa.
             *
             * `NOT VALID` a propósito: la regla rige para toda fila nueva sin tocar una
             * apertura de prueba ya hecha sin cuenta. Esas filas son append-only, no se
             * pueden completar, y no deben trabar la migración.
             */
            // Migración original: 2026_11_04_070000_require_bank_account_on_cash_book_openings.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE cash_book_openings ADD CONSTRAINT cash_book_openings_bank_account_required
            CHECK (bank_account_id IS NOT NULL) NOT VALID');
                }

                public function down(): void
                {
                    DB::statement('ALTER TABLE cash_book_openings DROP CONSTRAINT cash_book_openings_bank_account_required');
                }
            },

            /**
             * El traspaso y su comprobante, en dos pasos (Aranceles, DER físico §4.3 bis).
             *
             * Es una política de la caja (`cash_boxes.deposits_in_two_steps`). El efectivo
             * sale de la caja al registrar el traspaso, que nace `awaiting_ticket`. El
             * comprobante llega después, sin asiento, y lo pasa a `deposited`. Recién ahí se
             * puede acreditar: la transición ya existente solo sale de `deposited`.
             *
             * `deposit_date` es la fecha del traspaso y `ticket_date`, la del papel.
             */
            // Migración original: 2026_11_04_080000_add_two_step_cash_transfers.php
            new class extends Migration
            {
                private const GUARDA_CON_COMPROBANTE = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_to_bank_transfers_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.deposit_event_id IS DISTINCT FROM OLD.deposit_event_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.deposit_date IS DISTINCT FROM OLD.deposit_date
                THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.credit_event_id IS NOT NULL
                    AND NEW.credit_event_id IS DISTINCT FROM OLD.credit_event_id
                THEN
                    RAISE EXCEPTION 'La acreditacion de un traslado no se reasigna.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'deposited' AND NEW.status IN ('bank_confirmed', 'cancelled', 'bounced'))
                    AND NOT (OLD.status = 'awaiting_ticket' AND NEW.status IN ('deposited', 'cancelled'))
                THEN
                    RAISE EXCEPTION 'Un traslado % no pasa a %: solo avanza el que espera comprobante o acreditación.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                -- El comprobante entra una sola vez, y es lo que saca al traslado de la espera.
                IF (OLD.status = 'awaiting_ticket' AND NEW.status = 'deposited') IS DISTINCT FROM
                    (OLD.ticket_loaded_at IS NULL AND NEW.ticket_loaded_at IS NOT NULL)
                THEN
                    RAISE EXCEPTION 'El comprobante se carga una sola vez, sobre un traslado que lo espera.'
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Un comprobante cargado no se edita: se cancela y se registra otro traspaso.
                IF OLD.ticket_loaded_at IS NOT NULL AND (
                    NEW.ticket_date IS DISTINCT FROM OLD.ticket_date
                    OR NEW.ticket_loaded_at IS DISTINCT FROM OLD.ticket_loaded_at
                    OR NEW.ticket_loaded_by IS DISTINCT FROM OLD.ticket_loaded_by
                    OR NEW.ticket_idempotency_key IS DISTINCT FROM OLD.ticket_idempotency_key
                    OR NEW.deposit_time IS DISTINCT FROM OLD.deposit_time
                    OR NEW.deposit_operation_number IS DISTINCT FROM OLD.deposit_operation_number
                    OR NEW.deposit_terminal IS DISTINCT FROM OLD.deposit_terminal
                ) THEN
                    RAISE EXCEPTION 'Un comprobante cargado no se edita: se cancela el traspaso.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

                private const GUARDA_ANTERIOR = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_to_bank_transfers_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.deposit_event_id IS DISTINCT FROM OLD.deposit_event_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.deposit_date IS DISTINCT FROM OLD.deposit_date
                THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.credit_event_id IS NOT NULL
                    AND NEW.credit_event_id IS DISTINCT FROM OLD.credit_event_id
                THEN
                    RAISE EXCEPTION 'La acreditacion de un traslado no se reasigna.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'deposited' AND NEW.status IN ('bank_confirmed', 'cancelled', 'bounced'))
                THEN
                    RAISE EXCEPTION 'Un traslado % no pasa a %: solo avanza el que espera acreditación.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE cash_boxes ADD COLUMN deposits_in_two_steps boolean NOT NULL DEFAULT false;
            UPDATE cash_boxes SET deposits_in_two_steps = true WHERE code = 'aranceles';

            ALTER TABLE cash_to_bank_transfers
                ADD COLUMN ticket_date date,
                ADD COLUMN ticket_loaded_at timestamptz,
                ADD COLUMN ticket_loaded_by bigint REFERENCES users (id) ON DELETE RESTRICT,
                ADD COLUMN ticket_idempotency_key varchar(120);

            CREATE UNIQUE INDEX cash_to_bank_transfers_ticket_idempotency_unique
                ON cash_to_bank_transfers (ticket_idempotency_key)
                WHERE ticket_idempotency_key IS NOT NULL;

            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_status_check;
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
                CHECK (status IN ('awaiting_ticket', 'deposited', 'bank_confirmed', 'cancelled', 'bounced'));

            -- Los datos del comprobante van juntos.
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_ticket_together
                CHECK (
                    (ticket_date IS NULL) = (ticket_loaded_at IS NULL)
                    AND (ticket_date IS NULL) = (ticket_loaded_by IS NULL)
                    AND (ticket_date IS NULL) = (ticket_idempotency_key IS NULL)
                );

            /*
             * El banco no recibe la plata antes de que la caja la cobre. Sí puede
             * ser anterior al traspaso: un traspaso cancelado por error se vuelve
             * a registrar con la fecha del día, y el papel conserva la suya.
             */
            CREATE OR REPLACE FUNCTION cash_transfer_ticket_after_cash() RETURNS trigger AS $$
            BEGIN
                IF NEW.ticket_date IS NOT NULL AND EXISTS (
                    SELECT 1 FROM cash_to_bank_transfer_items i
                      JOIN fund_receipts r ON r.id = i.fund_receipt_id
                     WHERE i.cash_to_bank_transfer_id = NEW.id AND r.received_date > NEW.ticket_date
                ) THEN
                    RAISE EXCEPTION 'El comprobante es anterior al efectivo que lleva el traslado %.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfer_ticket_after_cash BEFORE UPDATE OF ticket_date ON cash_to_bank_transfers
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_ticket_after_cash();

            -- Lo que espera el comprobante todavía no dice nada del papel.
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_awaiting_has_no_ticket
                CHECK (
                    status <> 'awaiting_ticket'
                    OR (ticket_date IS NULL AND deposit_time IS NULL
                        AND deposit_operation_number IS NULL AND deposit_terminal IS NULL)
                );

            -- La política de la caja dice cómo nace cada traslado.
            CREATE OR REPLACE FUNCTION cash_transfer_follows_two_steps() RETURNS trigger AS $$
            DECLARE
                v_dos_pasos boolean;
            BEGIN
                SELECT deposits_in_two_steps INTO v_dos_pasos FROM cash_boxes WHERE id = NEW.cash_box_id;

                IF coalesce(v_dos_pasos, false) IS DISTINCT FROM (NEW.status = 'awaiting_ticket') THEN
                    RAISE EXCEPTION 'Un traslado de esta caja no nace %.', NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfer_follows_two_steps BEFORE INSERT ON cash_to_bank_transfers
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_follows_two_steps();

            -- La foto es la prueba del comprobante. Diferido: se guarda después de la fila.
            CREATE OR REPLACE FUNCTION cash_transfer_ticket_has_photo() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM attachments
                     WHERE subject_type = 'cash_transfer' AND subject_id = NEW.id
                       AND document_type = 'cash_transfer_ticket'
                ) THEN
                    RAISE EXCEPTION 'El comprobante del traslado % no tiene su foto.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cash_transfer_ticket_has_photo
                AFTER UPDATE OF status ON cash_to_bank_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (OLD.status = 'awaiting_ticket' AND NEW.status = 'deposited')
                EXECUTE FUNCTION cash_transfer_ticket_has_photo();
        SQL);

                    DB::unprepared(self::GUARDA_CON_COMPROBANTE);
                }

                public function down(): void
                {
                    DB::unprepared(self::GUARDA_ANTERIOR);

                    DB::unprepared(<<<'SQL'
            DROP TRIGGER cash_transfer_ticket_has_photo ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_ticket_has_photo();
            DROP TRIGGER cash_transfer_follows_two_steps ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_follows_two_steps();

            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_awaiting_has_no_ticket;
            DROP TRIGGER cash_transfer_ticket_after_cash ON cash_to_bank_transfers;
            DROP FUNCTION cash_transfer_ticket_after_cash();
            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_ticket_together;
            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_status_check;
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
                CHECK (status IN ('deposited', 'bank_confirmed', 'cancelled', 'bounced'));

            DROP INDEX cash_to_bank_transfers_ticket_idempotency_unique;
            ALTER TABLE cash_to_bank_transfers
                DROP COLUMN ticket_idempotency_key,
                DROP COLUMN ticket_loaded_by,
                DROP COLUMN ticket_loaded_at,
                DROP COLUMN ticket_date;

            ALTER TABLE cash_boxes DROP COLUMN deposits_in_two_steps;
        SQL);
                }
            },

            /**
             * `f_unaccent` nombra su extensión con el esquema.
             *
             * `pg_restore` trabaja con `search_path` vacío, y las columnas generadas de
             * `people` y `expedientes` llaman a esta función al crearse. Sin el esquema,
             * `unaccent` no se encontraba y esas dos tablas no se restauraban: un backup
             * de `pg_dump` parecía bueno hasta el día de usarlo.
             *
             * Se califican la función y también el diccionario, que se resuelve por
             * `search_path` igual que ella. Se prefiere esto a `SET search_path` en la
             * función porque esa cláusula impide que PostgreSQL la expanda en línea.
             *
             * El resultado no cambia, así que no hay columnas ni índices que recalcular.
             */
            // Migración original: 2026_11_04_080000_qualify_f_unaccent_for_restores.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$;
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT unaccent('unaccent', $1) $$;
        SQL);
                }
            },

            /**
             * Lo que la base exige a los cobros de Aranceles por el banco (Etapa 3, DER físico §6).
             *
             * - Un cobro pendiente no tiene fecha de pago ni dinero aplicado: lo vinculado
             *   espera sin identificar hasta completar el total.
             * - Un cobro pagado tiene aplicado todo lo que lo respalda.
             * - Un vínculo nace sin aplicar: la aplicación entra por la actualización, que
             *   comprueba que el asiento sea el correcto (`fee_charge_fundings_guard`).
             * - Una recepción por banco de esta caja está vinculada por todo su importe o
             *   revertida: en una cuenta compartida no queda dinero reconocido como de Aranceles
             *   y sin dueño. Lo que sobra de un crédito queda libre en el extracto.
             */
            // Migración original: 2026_11_05_010000_guard_bank_fee_charges.php
            new class extends Migration
            {
                private const string COMPROBACION = <<<'SQL'
        CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS $$
        DECLARE
            v_cobro RECORD;
            v_saldo RECORD;
            v_recibos integer;
            v_documentado numeric(19, 2);
            v_aplicados integer;
            v_vigentes integer;
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*), COALESCE(sum(r.amount), 0) INTO v_recibos, v_documentado
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';
            SELECT count(*), count(application_event_id) INTO v_vigentes, v_aplicados
              FROM fee_charge_fundings WHERE fee_charge_id = p_charge AND released_at IS NULL;

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0 OR v_aplicados > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo, dinero aplicado ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1 OR v_documentado <> v_saldo.total OR v_aplicados <> v_vigentes) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente por su total y su total vinculado y aplicado: % de %, con % recibos por %.',
                    v_saldo.funded, v_saldo.total, v_recibos, v_documentado USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

                private const string COMPROBACION_ANTERIOR = <<<'SQL'
        CREATE OR REPLACE FUNCTION fee_charge_settled(p_charge bigint) RETURNS void AS $$
        DECLARE
            v_cobro RECORD;
            v_saldo RECORD;
            v_recibos integer;
            v_documentado numeric(19, 2);
        BEGIN
            SELECT status, medium INTO v_cobro FROM fee_charges WHERE id = p_charge;
            SELECT total, funded INTO v_saldo FROM fee_charge_balances WHERE id = p_charge;
            SELECT count(*), COALESCE(sum(r.amount), 0) INTO v_recibos, v_documentado
              FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
             WHERE l.fee_charge_id = p_charge AND r.status = 'issued';

            IF v_saldo.total <= 0 THEN
                RAISE EXCEPTION 'El cobro de arancel % no tiene renglones.', p_charge USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND v_cobro.medium = 'cash' THEN
                RAISE EXCEPTION 'Un cobro en efectivo se paga en el acto: no queda pendiente.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'pending' AND (v_saldo.funded > v_saldo.total OR v_recibos > 0) THEN
                RAISE EXCEPTION 'Un cobro pendiente no tiene recibo ni más dinero vinculado que su total.' USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'paid' AND (v_saldo.funded <> v_saldo.total OR v_recibos <> 1 OR v_documentado <> v_saldo.total) THEN
                RAISE EXCEPTION 'Un cobro pagado tiene un recibo vigente por su total y su total vinculado: % de %, con % recibos por %.',
                    v_saldo.funded, v_saldo.total, v_recibos, v_documentado USING ERRCODE = 'check_violation';
            END IF;

            IF v_cobro.status = 'voided' AND (v_saldo.funded <> 0 OR v_recibos <> 0) THEN
                RAISE EXCEPTION 'Un cobro anulado no conserva dinero vinculado ni recibo vigente.' USING ERRCODE = 'check_violation';
            END IF;
        END;
        $$ LANGUAGE plpgsql;
        SQL;

                public function up(): void
                {
                    DB::unprepared(self::COMPROBACION);

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_pending_has_no_payment_date
                CHECK (status <> 'pending' OR paid_on IS NULL);

            CREATE OR REPLACE FUNCTION fee_charge_funding_born_unapplied() RETURNS trigger AS $$
            BEGIN
                IF NEW.application_event_id IS NOT NULL OR NEW.application_reversal_event_id IS NOT NULL THEN
                    RAISE EXCEPTION 'Un vínculo nace sin aplicar: la aplicación se registra después, con su asiento.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_funding_born_unapplied BEFORE INSERT ON fee_charge_fundings
                FOR EACH ROW EXECUTE FUNCTION fee_charge_funding_born_unapplied();

            CREATE OR REPLACE FUNCTION fee_bank_receipt_has_a_charge(p_receipt bigint) RETURNS void AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts r
                     WHERE r.id = p_receipt
                       AND r.cash_box_id = aranceles_cash_box_id()
                       AND r.medium = 'bank'
                       AND r.reversal_event_id IS NULL
                       AND r.amount <> (
                           SELECT coalesce(sum(f.amount), 0) FROM fee_charge_fundings f
                            WHERE f.fund_receipt_id = r.id AND f.released_at IS NULL
                       )
                ) THEN
                    RAISE EXCEPTION 'La recepción % por banco de Aranceles no respalda ningún cobro por todo su importe: se vincula o se revierte.', p_receipt
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fee_bank_receipt_has_a_charge_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_TABLE_NAME = 'fund_receipts' THEN
                    PERFORM fee_bank_receipt_has_a_charge(NEW.id);
                ELSE
                    PERFORM fee_bank_receipt_has_a_charge(NEW.fund_receipt_id);
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Diferidas: la recepción, su vínculo y su reversión van en varias sentencias.
            CREATE CONSTRAINT TRIGGER fund_receipts_fee_bank_has_a_charge AFTER INSERT OR UPDATE ON fund_receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_bank_receipt_has_a_charge_trigger();
            CREATE CONSTRAINT TRIGGER fee_charge_fundings_keep_bank_receipt AFTER INSERT OR UPDATE ON fee_charge_fundings
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fee_bank_receipt_has_a_charge_trigger();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER fee_charge_fundings_keep_bank_receipt ON fee_charge_fundings;
            DROP TRIGGER fund_receipts_fee_bank_has_a_charge ON fund_receipts;
            DROP FUNCTION fee_bank_receipt_has_a_charge_trigger();
            DROP FUNCTION fee_bank_receipt_has_a_charge(bigint);
            DROP TRIGGER fee_charge_funding_born_unapplied ON fee_charge_fundings;
            DROP FUNCTION fee_charge_funding_born_unapplied();
            ALTER TABLE fee_charges DROP CONSTRAINT fee_charges_pending_has_no_payment_date;
        SQL);

                    DB::unprepared(self::COMPROBACION_ANTERIOR);
                }
            },

            /**
             * Los motivos de un cambio de importe en Aranceles (reglas A25 y A26).
             *
             * - El sistema ya sabe si se cobró de más o de menos, comparando con lo calculado:
             *   desaparecen `less_accepted` y `more_accepted`.
             * - El redondeo no tiene tope: no circulan billetes menores de $ 50, y el de $ 1 no
             *   servía para nada real. Decisión del usuario, sin relevar con el área.
             *
             * Si hubiera renglones con un motivo que desaparece, la migración falla: no se les
             * inventa otro motivo. Se resuelven a mano antes de volver a correrla.
             */
            // Migración original: 2026_11_05_020000_simplify_fee_amount_reasons.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charge_items
                DROP CONSTRAINT fee_charge_items_rounding_is_small,
                DROP CONSTRAINT fee_charge_items_change_reason_code_check,
                ADD CONSTRAINT fee_charge_items_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'exception', 'other'));

            ALTER TABLE fee_charge_item_adjustments
                DROP CONSTRAINT fee_charge_item_adjustments_rounding_is_small,
                DROP CONSTRAINT fee_charge_item_adjustments_change_reason_code_check,
                ADD CONSTRAINT fee_charge_item_adjustments_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'exception', 'other'));
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charge_items
                DROP CONSTRAINT fee_charge_items_change_reason_code_check,
                ADD CONSTRAINT fee_charge_items_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'less_accepted', 'more_accepted', 'exception', 'other')),
                ADD CONSTRAINT fee_charge_items_rounding_is_small
                    CHECK (change_reason_code IS DISTINCT FROM 'rounding' OR abs(original_amount - calculated_amount) < 1);

            ALTER TABLE fee_charge_item_adjustments
                DROP CONSTRAINT fee_charge_item_adjustments_change_reason_code_check,
                ADD CONSTRAINT fee_charge_item_adjustments_change_reason_code_check
                    CHECK (change_reason_code IN ('rounding', 'less_accepted', 'more_accepted', 'exception', 'other')),
                ADD CONSTRAINT fee_charge_item_adjustments_rounding_is_small
                    CHECK (change_reason_code <> 'rounding' OR abs(new_amount - previous_amount) < 1);
        SQL);
                }
            },

            /**
             * Quien paga un arancel se identifica con CUIT o con DNI (regla A01): puede ser una
             * persona o una empresa, y no está relevado cuál. El DNI va de 6 a 8 dígitos, como en
             * el resto del sistema, y el CUIT tiene 11.
             *
             * La columna deja de llamarse `payer_cuit`, porque ya no guarda solo eso, y deja de ser
             * `char(11)`, que rellenaría un DNI con espacios. La guarda de `fee_charges` nombra la
             * columna, así que se vuelve a escribir.
             */
            // Migración original: 2026_11_05_030000_accept_cuit_or_dni_on_fee_charges.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charges DROP CONSTRAINT fee_charges_payer_cuit_check;
            ALTER TABLE fee_charges RENAME COLUMN payer_cuit TO payer_document;
            ALTER TABLE fee_charges ALTER COLUMN payer_document TYPE varchar(11);
            ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_payer_document_format
                CHECK (payer_document ~ '^([0-9]{6,8}|[0-9]{11})$');
        SQL);

                    DB::unprepared($this->guarda('payer_document'));
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charges DROP CONSTRAINT fee_charges_payer_document_format;
            ALTER TABLE fee_charges ALTER COLUMN payer_document TYPE char(11);
            ALTER TABLE fee_charges RENAME COLUMN payer_document TO payer_cuit;
            ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_payer_cuit_check
                CHECK (payer_cuit ~ '^[0-9]{11}$');
        SQL);

                    DB::unprepared($this->guarda('payer_cuit'));
                }

                /**
                 * @param  literal-string  $columna
                 * @return literal-string
                 */
                private function guarda(string $columna): string
                {
                    return <<<SQL
            CREATE OR REPLACE FUNCTION fee_charges_guard() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un cobro de arancel no se borra: se anula.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.payer_name IS DISTINCT FROM OLD.payer_name
                    OR NEW.{$columna} IS DISTINCT FROM OLD.{$columna}
                    OR NEW.medium IS DISTINCT FROM OLD.medium
                    OR NEW.requested_on IS DISTINCT FROM OLD.requested_on
                    OR NEW.pricing_date IS DISTINCT FROM OLD.pricing_date
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.paid_on IS NOT NULL AND NEW.paid_on IS DISTINCT FROM OLD.paid_on)
                THEN
                    RAISE EXCEPTION 'Los datos de un cobro de arancel no se editan: se corrige con otro cobro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'pending' AND NEW.status IN ('paid', 'voided'))
                    AND NOT (OLD.status = 'paid' AND NEW.status = 'voided')
                THEN
                    RAISE EXCEPTION 'Un cobro de arancel % no pasa a %.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL;
                }
            },

            /**
             * El comprobante del depósito con que se paga un arancel: lo que dice el papel y su
             * foto. Sin él, un pendiente no se identifica contra el extracto.
             *
             * Es lo que dijo quien pagó, no el dinero: el importe no se ata al cobro, porque un
             * depósito puede pagar en parte o de más, y el dinero se verifica contra el extracto.
             * Solo se agrega: cambiarlo suma otra fila, y vale la última.
             */
            // Migración original: 2026_11_06_010000_create_fee_charge_proofs_table.php
            new class extends Migration
            {
                private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'fine_payment', 'cheque_rejection',
                'fine_document'";

                public function up(): void
                {
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'fee_charge'))");

                    Schema::create('fee_charge_proofs', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('fee_charge_id')->constrained('fee_charges')->restrictOnDelete();
                        $table->foreignId('attachment_id')->unique()->constrained('attachments')->restrictOnDelete();
                        $table->decimal('amount', 19, 2);
                        $table->date('deposit_date');
                        $table->time('deposit_time')->nullable();
                        $table->string('operation_number', 40)->nullable();
                        $table->string('terminal', 40)->nullable();
                        $table->string('notes', 500)->nullable();
                        $table->string('idempotency_key', 120)->unique();
                        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('created_at')->useCurrent();

                        $table->index(['fee_charge_id', 'id']);
                    });

                    DB::statement('ALTER TABLE fee_charge_proofs ADD CONSTRAINT fee_charge_proofs_amount_positive CHECK (amount > 0)');

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fee_charge_proofs_guard() RETURNS trigger AS $$
            DECLARE
                v_charge fee_charges%ROWTYPE;
                v_attachment attachments%ROWTYPE;
            BEGIN
                -- Con la fila tomada: no se cruza con quien lo está anulando.
                SELECT * INTO v_charge FROM fee_charges WHERE id = NEW.fee_charge_id FOR UPDATE;

                IF v_charge.medium <> 'bank' THEN
                    RAISE EXCEPTION 'Solo un cobro de arancel por banco lleva comprobante de depósito.';
                END IF;

                IF v_charge.status = 'voided' THEN
                    RAISE EXCEPTION 'El cobro de arancel % está anulado: su comprobante ya no se cambia.', NEW.fee_charge_id;
                END IF;

                SELECT * INTO v_attachment FROM attachments WHERE id = NEW.attachment_id;

                IF v_attachment.subject_type <> 'fee_charge' OR v_attachment.subject_id <> NEW.fee_charge_id THEN
                    RAISE EXCEPTION 'El archivo no es de ese cobro de arancel.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_proofs_guard BEFORE INSERT ON fee_charge_proofs
                FOR EACH ROW EXECUTE FUNCTION fee_charge_proofs_guard();
            CREATE TRIGGER fee_charge_proofs_append_only BEFORE UPDATE OR DELETE ON fee_charge_proofs
                FOR EACH ROW EXECUTE FUNCTION fee_rows_append_only();
            SQL);
                }

                public function down(): void
                {
                    if (DB::table('attachments')->where('subject_type', 'fee_charge')->exists()) {
                        throw new RuntimeException(
                            'Hay comprobantes adjuntos a cobros de aranceles: decidí qué hacer con ellos antes de volver atrás.',
                        );
                    }

                    Schema::dropIfExists('fee_charge_proofs');
                    DB::unprepared('DROP FUNCTION fee_charge_proofs_guard();');

                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');
                }
            },
        ];
    }
};
