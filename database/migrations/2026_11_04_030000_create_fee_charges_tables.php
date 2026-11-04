<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
return new class extends Migration
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
};
