<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las cuentas bancarias habilitadas de cada caja, con su vigencia.
 *
 * Multas operaba con una sola cuenta, la de su apertura; Haberes, con
 * cualquier cuenta activa. Ahora cada caja tiene sus cuentas habilitadas
 * (decisión del usuario, 29/10/2026), para poder sumar una segunda cuenta,
 * compartir una con otra caja o reemplazar una por otra sin perder nada:
 *
 * - **Vigencia efectiva**: desde (nulo = inicio histórico desconocido) y
 *   hasta, que incluye ese día. Nunca una fecha futura: así la predeterminada
 *   no puede quedar vencida solo porque pasa el tiempo.
 * - **Una predeterminada por caja y moneda** cuando hay alguna vigente, sin
 *   fecha de baja.
 * - **La vigencia no se achica** sobre lo ya registrado: depósitos,
 *   identificaciones y pagos anteriores de esa cuenta.
 * - **Depositar e identificar** exigen una cuenta vinculada y vigente en la
 *   fecha. Lo que nace de un depósito ya hecho —su acreditación, reversiones
 *   y rechazos— se sigue validando contra la cuenta del depósito, aunque esté
 *   retirada.
 *
 * Se migran sin fecha de inicio: Multas, la cuenta de su apertura; Haberes,
 * las que ya usó. `cash_book_openings.bank_account_id` queda como dato
 * histórico de la apertura. Cada cuenta suma cómo se cita en los documentos,
 * y la rendición mensual de Multas guarda las cuentas de sus filas.
 */
return new class extends Migration
{
    /** Cómo citaba el área la cuenta de Multas, hasta hoy en `config/multas.php`. */
    private const CITACION_DE_MULTAS = 'Nº 452680-1 del Bco. Macro';

    private const HOY = "(CURRENT_TIMESTAMP AT TIME ZONE 'America/Argentina/Salta')::date";

    /** `fine_historical_bank_credit` como la dejó `link_fine_historical_movements_to_bank`: la cuenta de la apertura. */
    private const HISTORICO_POR_APERTURA = <<<'SQL'
            CREATE OR REPLACE FUNCTION fine_historical_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_tx RECORD;
                v_apertura DATE;
                v_imputado NUMERIC(19,2);
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.bank_transaction_id IS NOT NULL
                       AND (NEW.occurred_on IS DISTINCT FROM OLD.occurred_on
                            OR NEW.occurred_precision IS DISTINCT FROM OLD.occurred_precision) THEN
                        RAISE EXCEPTION 'La fecha de un pago respaldado por un credito del banco es la del banco: no se revisa.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.bank_transaction_id IS NULL THEN RETURN NEW; END IF;

                SELECT t.*, b.currency INTO v_tx
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id
                   FOR UPDATE OF t;

                IF v_tx.direction <> 'credit' OR NEW.kind <> 'payment' THEN
                    RAISE EXCEPTION 'Un movimiento del banco respalda un pago, y tiene que ser un credito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.amount <> v_tx.amount THEN
                    RAISE EXCEPTION 'El pago es por el credito entero (%), no por una parte.', v_tx.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.occurred_precision <> 'day' OR NEW.occurred_on <> v_tx.transaction_date THEN
                    RAISE EXCEPTION 'La fecha del pago es la del credito: %.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT o.opened_on INTO v_apertura
                  FROM cash_book_openings o
                  JOIN cash_boxes c ON c.id = o.cash_box_id
                 WHERE c.code = 'multas'
                   AND o.currency = v_tx.currency
                   AND o.bank_account_id = v_tx.bank_account_id;

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'El credito no es de la cuenta con la que opera la caja de Multas, o la caja todavia no se abrio.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_tx.transaction_date >= v_apertura THEN
                    RAISE EXCEPTION 'El credito del % es posterior a la apertura de la caja de Multas (%): se cobra por el circuito, no como pago anterior.',
                        v_tx.transaction_date, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_imputado
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id;

                IF v_imputado > 0 THEN
                    RAISE EXCEPTION 'El credito ya esta imputado en un libro: no respalda ademas un pago anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** La misma, con una cuenta de la caja vigente en la fecha del crédito. */
    private const HISTORICO_POR_VINCULOS = <<<'SQL'
            CREATE OR REPLACE FUNCTION fine_historical_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_tx RECORD;
                v_apertura DATE;
                v_imputado NUMERIC(19,2);
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.bank_transaction_id IS NOT NULL
                       AND (NEW.occurred_on IS DISTINCT FROM OLD.occurred_on
                            OR NEW.occurred_precision IS DISTINCT FROM OLD.occurred_precision) THEN
                        RAISE EXCEPTION 'La fecha de un pago respaldado por un credito del banco es la del banco: no se revisa.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.bank_transaction_id IS NULL THEN RETURN NEW; END IF;

                SELECT t.*, b.currency INTO v_tx
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id
                   FOR UPDATE OF t;

                IF v_tx.direction <> 'credit' OR NEW.kind <> 'payment' THEN
                    RAISE EXCEPTION 'Un movimiento del banco respalda un pago, y tiene que ser un credito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.amount <> v_tx.amount THEN
                    RAISE EXCEPTION 'El pago es por el credito entero (%), no por una parte.', v_tx.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.occurred_precision <> 'day' OR NEW.occurred_on <> v_tx.transaction_date THEN
                    RAISE EXCEPTION 'La fecha del pago es la del credito: %.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT o.opened_on INTO v_apertura
                  FROM cash_book_openings o
                  JOIN cash_boxes c ON c.id = o.cash_box_id
                 WHERE c.code = 'multas'
                   AND o.currency = v_tx.currency
                   AND cash_box_account_covers(c.id, v_tx.bank_account_id, v_tx.transaction_date);

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'El credito no es de una cuenta de la caja de Multas vigente en su fecha, o la caja todavia no se abrio.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_tx.transaction_date >= v_apertura THEN
                    RAISE EXCEPTION 'El credito del % es posterior a la apertura de la caja de Multas (%): se cobra por el circuito, no como pago anterior.',
                        v_tx.transaction_date, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_imputado
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id;

                IF v_imputado > 0 THEN
                    RAISE EXCEPTION 'El credito ya esta imputado en un libro: no respalda ademas un pago anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** `transfer_follows_box_policy` como la dejó `add_cheque_rejections`: compara con la cuenta de la apertura. */
    private const POLITICA_POR_APERTURA = <<<'SQL'
            CREATE OR REPLACE FUNCTION transfer_follows_box_policy(p_transfer bigint) RETURNS void AS $$
            DECLARE
                v_traslado RECORD;
                v_cuenta bigint;
                v_items integer;
                v_item RECORD;
                v_vigentes integer;
            BEGIN
                SELECT t.id, t.cash_box_id, t.bank_account_id, t.amount, t.status, b.deposits_whole_receipts, a.currency
                  INTO v_traslado
                  FROM cash_to_bank_transfers t
                  JOIN cash_boxes b ON b.id = t.cash_box_id
                  JOIN bank_accounts a ON a.id = t.bank_account_id
                 WHERE t.id = p_transfer;

                IF NOT FOUND OR NOT v_traslado.deposits_whole_receipts THEN
                    RETURN;
                END IF;

                SELECT bank_account_id INTO v_cuenta
                  FROM cash_book_openings
                 WHERE cash_box_id = v_traslado.cash_box_id AND currency = v_traslado.currency;

                IF v_cuenta IS DISTINCT FROM v_traslado.bank_account_id THEN
                    RAISE EXCEPTION 'Esta caja deposita en la cuenta con la que opera su libro (%), no en la %.',
                        v_cuenta, v_traslado.bank_account_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT count(*) INTO v_items FROM cash_to_bank_transfer_items WHERE cash_to_bank_transfer_id = p_transfer;

                IF v_items <> 1 THEN
                    RAISE EXCEPTION 'Un traslado de esta caja lleva un solo cobro, entero: tiene %.', v_items
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT i.fund_receipt_id, i.funding_allocation_id, i.amount, r.amount AS receipt_amount,
                       r.cash_box_id AS receipt_box, r.origin, r.medium
                  INTO v_item
                  FROM cash_to_bank_transfer_items i
                  JOIN fund_receipts r ON r.id = i.fund_receipt_id
                 WHERE i.cash_to_bank_transfer_id = p_transfer;

                IF v_item.funding_allocation_id IS NOT NULL
                    OR v_item.receipt_box IS DISTINCT FROM v_traslado.cash_box_id
                    OR v_item.origin NOT IN ('received', 'replacement')
                    OR v_item.medium NOT IN ('cash', 'cheque') THEN
                    RAISE EXCEPTION 'Esta caja deposita cobros recibidos por su mostrador, sin asignarlos a nadie.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_item.amount <> v_item.receipt_amount OR v_traslado.amount <> v_item.receipt_amount THEN
                    RAISE EXCEPTION 'El cobro viaja entero: % y no %.', v_item.receipt_amount, v_item.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 * Serializa a quienes depositan el mismo cobro: el segundo espera
                 * acá y, al seguir, cuenta ya con el traslado del primero
                 * confirmado. NO KEY UPDATE y no UPDATE: tiene que convivir con
                 * el KEY SHARE que la FK del ítem deja en cada escritor; con
                 * UPDATE, dos escritores se esperarían entre sí.
                 */
                PERFORM 1 FROM fund_receipts WHERE id = v_item.fund_receipt_id FOR NO KEY UPDATE;

                SELECT count(DISTINCT t.id) INTO v_vigentes
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = v_item.fund_receipt_id AND t.status <> 'cancelled';

                IF v_vigentes > 1 THEN
                    RAISE EXCEPTION 'Ese cobro ya tiene un traslado vigente: uno solo por cobro.'
                        USING ERRCODE = 'unique_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** La misma, sin ese control: la cuenta del depósito la mira `cash_transfer_follows_box_accounts`. */
    private const POLITICA_POR_VINCULOS = <<<'SQL'
            CREATE OR REPLACE FUNCTION transfer_follows_box_policy(p_transfer bigint) RETURNS void AS $$
            DECLARE
                v_traslado RECORD;
                v_cuenta bigint;
                v_items integer;
                v_item RECORD;
                v_vigentes integer;
            BEGIN
                SELECT t.id, t.cash_box_id, t.bank_account_id, t.amount, t.status, b.deposits_whole_receipts, a.currency
                  INTO v_traslado
                  FROM cash_to_bank_transfers t
                  JOIN cash_boxes b ON b.id = t.cash_box_id
                  JOIN bank_accounts a ON a.id = t.bank_account_id
                 WHERE t.id = p_transfer;

                IF NOT FOUND OR NOT v_traslado.deposits_whole_receipts THEN
                    RETURN;
                END IF;

                -- La cuenta del depósito la controla cash_transfer_follows_box_accounts.
                v_cuenta := NULL;

                SELECT count(*) INTO v_items FROM cash_to_bank_transfer_items WHERE cash_to_bank_transfer_id = p_transfer;

                IF v_items <> 1 THEN
                    RAISE EXCEPTION 'Un traslado de esta caja lleva un solo cobro, entero: tiene %.', v_items
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT i.fund_receipt_id, i.funding_allocation_id, i.amount, r.amount AS receipt_amount,
                       r.cash_box_id AS receipt_box, r.origin, r.medium
                  INTO v_item
                  FROM cash_to_bank_transfer_items i
                  JOIN fund_receipts r ON r.id = i.fund_receipt_id
                 WHERE i.cash_to_bank_transfer_id = p_transfer;

                IF v_item.funding_allocation_id IS NOT NULL
                    OR v_item.receipt_box IS DISTINCT FROM v_traslado.cash_box_id
                    OR v_item.origin NOT IN ('received', 'replacement')
                    OR v_item.medium NOT IN ('cash', 'cheque') THEN
                    RAISE EXCEPTION 'Esta caja deposita cobros recibidos por su mostrador, sin asignarlos a nadie.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_item.amount <> v_item.receipt_amount OR v_traslado.amount <> v_item.receipt_amount THEN
                    RAISE EXCEPTION 'El cobro viaja entero: % y no %.', v_item.receipt_amount, v_item.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 * Serializa a quienes depositan el mismo cobro: el segundo espera
                 * acá y, al seguir, cuenta ya con el traslado del primero
                 * confirmado. NO KEY UPDATE y no UPDATE: tiene que convivir con
                 * el KEY SHARE que la FK del ítem deja en cada escritor; con
                 * UPDATE, dos escritores se esperarían entre sí.
                 */
                PERFORM 1 FROM fund_receipts WHERE id = v_item.fund_receipt_id FOR NO KEY UPDATE;

                SELECT count(DISTINCT t.id) INTO v_vigentes
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = v_item.fund_receipt_id AND t.status <> 'cancelled';

                IF v_vigentes > 1 THEN
                    RAISE EXCEPTION 'Ese cobro ya tiene un traslado vigente: uno solo por cobro.'
                        USING ERRCODE = 'unique_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        $hoy = self::HOY;

        DB::unprepared(<<<SQL
            ALTER TABLE bank_accounts ADD COLUMN document_citation varchar(120)
                CHECK (document_citation IS NULL OR btrim(document_citation) <> '');

            CREATE TABLE cash_box_bank_accounts (
                id bigserial PRIMARY KEY,
                cash_box_id bigint NOT NULL REFERENCES cash_boxes (id) ON DELETE RESTRICT,
                bank_account_id bigint NOT NULL REFERENCES bank_accounts (id) ON DELETE RESTRICT,
                currency char(3) NOT NULL,
                valid_from date,
                valid_until date,
                is_default boolean NOT NULL DEFAULT false,
                created_by bigint REFERENCES users (id) ON DELETE SET NULL,
                created_at timestamptz(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_by bigint REFERENCES users (id) ON DELETE SET NULL,
                updated_at timestamptz(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT cash_box_bank_accounts_unique UNIQUE (cash_box_id, bank_account_id),
                CONSTRAINT cash_box_bank_accounts_range CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until >= valid_from),
                CONSTRAINT cash_box_bank_accounts_default_open CHECK (NOT (is_default AND valid_until IS NOT NULL))
            );

            CREATE UNIQUE INDEX cash_box_bank_accounts_one_default
                ON cash_box_bank_accounts (cash_box_id, currency) WHERE is_default;
            CREATE INDEX cash_box_bank_accounts_account ON cash_box_bank_accounts (bank_account_id);

            -- La moneda es la de la cuenta, las fechas no son futuras y un vínculo no se borra ni cambia de caja o cuenta.
            CREATE OR REPLACE FUNCTION cash_box_bank_account_row() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una cuenta de la caja no se borra: se le da de baja.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'UPDATE' AND (NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id) THEN
                    RAISE EXCEPTION 'Un vínculo no cambia de caja ni de cuenta.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT currency INTO NEW.currency FROM bank_accounts WHERE id = NEW.bank_account_id;

                IF (NEW.valid_from IS NOT NULL AND NEW.valid_from > {$hoy})
                    OR (NEW.valid_until IS NOT NULL AND NEW.valid_until > {$hoy}) THEN
                    RAISE EXCEPTION 'La vigencia de una cuenta no se carga con fechas futuras.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_box_bank_accounts_row BEFORE INSERT OR UPDATE OR DELETE ON cash_box_bank_accounts
                FOR EACH ROW EXECUTE FUNCTION cash_box_bank_account_row();

            -- Exactamente una predeterminada por caja y moneda mientras haya alguna cuenta vigente.
            CREATE OR REPLACE FUNCTION cash_box_has_one_default() RETURNS trigger AS \$\$
            DECLARE v_vigentes integer; v_predeterminadas integer;
            BEGIN
                SELECT count(*) FILTER (WHERE valid_until IS NULL), count(*) FILTER (WHERE is_default)
                  INTO v_vigentes, v_predeterminadas
                  FROM cash_box_bank_accounts
                 WHERE cash_box_id = NEW.cash_box_id AND currency = NEW.currency;

                IF v_vigentes > 0 AND v_predeterminadas <> 1 THEN
                    RAISE EXCEPTION 'La caja tiene que tener exactamente una cuenta predeterminada en %: tiene %.', NEW.currency, v_predeterminadas
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cash_box_has_one_default AFTER INSERT OR UPDATE ON cash_box_bank_accounts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cash_box_has_one_default();

            /*
             * La vigencia de una cuenta en una caja: depositar e identificar un
             * crédito se juzgan contra ella. Nula si la cuenta no está
             * vinculada a esa caja.
             */
            CREATE OR REPLACE FUNCTION cash_box_account_covers(p_box bigint, p_account bigint, p_date date) RETURNS boolean AS \$\$
                SELECT EXISTS (
                    SELECT 1 FROM cash_box_bank_accounts
                     WHERE cash_box_id = p_box AND bank_account_id = p_account
                       AND (valid_from IS NULL OR valid_from <= p_date)
                       AND (valid_until IS NULL OR valid_until >= p_date)
                );
            \$\$ LANGUAGE sql STABLE;

            -- La vigencia nueva no deja afuera lo que ya se registró con esa cuenta en esa caja.
            CREATE OR REPLACE FUNCTION cash_box_bank_account_window() RETURNS trigger AS \$\$
            DECLARE v_fecha date;
            BEGIN
                IF NEW.valid_from IS NOT DISTINCT FROM OLD.valid_from AND NEW.valid_until IS NOT DISTINCT FROM OLD.valid_until THEN
                    RETURN NEW;
                END IF;

                SELECT deposit_date INTO v_fecha
                  FROM cash_to_bank_transfers
                 WHERE cash_box_id = NEW.cash_box_id AND bank_account_id = NEW.bank_account_id
                   AND ((NEW.valid_from IS NOT NULL AND deposit_date < NEW.valid_from)
                        OR (NEW.valid_until IS NOT NULL AND deposit_date > NEW.valid_until))
                 LIMIT 1;

                IF v_fecha IS NULL THEN
                    SELECT t.transaction_date INTO v_fecha
                      FROM bank_transaction_allocations a
                      JOIN bank_transactions t ON t.id = a.bank_transaction_id
                      JOIN financial_events e ON e.id = a.financial_event_id
                     WHERE e.cash_box_id = NEW.cash_box_id AND e.event_type = 'funds_received'
                       AND a.reversal_of_id IS NULL AND t.bank_account_id = NEW.bank_account_id
                       AND ((NEW.valid_from IS NOT NULL AND t.transaction_date < NEW.valid_from)
                            OR (NEW.valid_until IS NOT NULL AND t.transaction_date > NEW.valid_until))
                     LIMIT 1;
                END IF;

                IF v_fecha IS NULL THEN
                    SELECT t.transaction_date INTO v_fecha
                      FROM fine_historical_movements h
                      JOIN bank_transactions t ON t.id = h.bank_transaction_id
                      JOIN cash_boxes c ON c.id = NEW.cash_box_id AND c.code = 'multas'
                     WHERE h.status <> 'rejected' AND t.bank_account_id = NEW.bank_account_id
                       AND ((NEW.valid_from IS NOT NULL AND t.transaction_date < NEW.valid_from)
                            OR (NEW.valid_until IS NOT NULL AND t.transaction_date > NEW.valid_until))
                     LIMIT 1;
                END IF;

                IF v_fecha IS NOT NULL THEN
                    RAISE EXCEPTION 'La vigencia dejaría afuera lo registrado el % con esta cuenta en esta caja.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_box_bank_accounts_window BEFORE UPDATE ON cash_box_bank_accounts
                FOR EACH ROW EXECUTE FUNCTION cash_box_bank_account_window();

            /*
             * Un depósito nuevo va a una cuenta de la caja vigente en la fecha
             * del depósito. Solo al insertarlo: su acreditación, sus
             * reversiones y sus rechazos siguen contra la cuenta guardada.
             * Una caja sin cuentas vinculadas no se controla.
             */
            CREATE OR REPLACE FUNCTION cash_transfer_follows_box_accounts() RETURNS trigger AS \$\$
            BEGIN
                IF EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE cash_box_id = NEW.cash_box_id)
                    AND NOT cash_box_account_covers(NEW.cash_box_id, NEW.bank_account_id, NEW.deposit_date) THEN
                    RAISE EXCEPTION 'La cuenta del depósito no está habilitada para esta caja el %.', NEW.deposit_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_transfers_follow_box_accounts BEFORE INSERT ON cash_to_bank_transfers
                FOR EACH ROW EXECUTE FUNCTION cash_transfer_follows_box_accounts();

            /*
             * Identificar un crédito —imputarlo a una recepción por el banco—
             * exige que sea de una cuenta de la caja y caiga en su vigencia.
             * Las reversiones, las acreditaciones de depósitos y los débitos de
             * rechazos no son identificaciones: se validan contra su depósito.
             */
            CREATE OR REPLACE FUNCTION bank_allocation_follows_box_accounts() RETURNS trigger AS \$\$
            DECLARE v_evento RECORD; v_tx RECORD;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                SELECT cash_box_id, event_type INTO v_evento FROM financial_events WHERE id = NEW.financial_event_id;

                IF v_evento.event_type IS DISTINCT FROM 'funds_received' OR v_evento.cash_box_id IS NULL
                    OR NOT EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE cash_box_id = v_evento.cash_box_id) THEN
                    RETURN NEW;
                END IF;

                SELECT bank_account_id, transaction_date INTO v_tx FROM bank_transactions WHERE id = NEW.bank_transaction_id;

                IF NOT cash_box_account_covers(v_evento.cash_box_id, v_tx.bank_account_id, v_tx.transaction_date) THEN
                    RAISE EXCEPTION 'El crédito del % no es de una cuenta habilitada para esta caja en esa fecha.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_allocations_follow_box_accounts BEFORE INSERT ON bank_transaction_allocations
                FOR EACH ROW EXECUTE FUNCTION bank_allocation_follows_box_accounts();
        SQL);

        $this->historicalCreditsByLinks();
        $this->backfill();
        $this->depositPolicyWithoutOpeningAccount();
    }

    /**
     * Un pago anterior de Multas lo respalda un crédito anterior a la
     * apertura de la caja y dentro de la vigencia de una de sus cuentas: la
     * migrada, sin inicio, sigue respaldando lo viejo como hasta hoy.
     */
    private function historicalCreditsByLinks(): void
    {
        DB::unprepared(self::HISTORICO_POR_VINCULOS);
    }

    /**
     * Los vínculos de lo que ya existe, sin fechas: el inicio histórico no se
     * conoce y no se inventa. Cualquier caja, las cuentas que su apertura, su
     * libro o sus depósitos ya usaron; Haberes, además, las de sus
     * comprobantes y sus Órdenes. Predeterminada por moneda: la de la
     * apertura o, si no hay, la más usada.
     */
    private function backfill(): void
    {
        DB::unprepared(<<<'SQL'
            WITH usadas AS (
                SELECT cash_box_id, bank_account_id FROM cash_book_openings WHERE bank_account_id IS NOT NULL
                UNION ALL
                SELECT cash_box_id, bank_account_id FROM journal_lines WHERE bank_account_id IS NOT NULL AND cash_box_id IS NOT NULL
                UNION ALL
                SELECT cash_box_id, bank_account_id FROM cash_to_bank_transfers
                UNION ALL
                SELECT c.id, d.bank_account_id FROM deposit_tickets d JOIN cash_boxes c ON c.code = 'haberes'
                UNION ALL
                SELECT c.id, p.organism_bank_account_id FROM payment_orders p JOIN cash_boxes c ON c.code = 'haberes'
                 WHERE p.organism_bank_account_id IS NOT NULL
            ),
            cuentas AS (
                SELECT u.cash_box_id, u.bank_account_id, a.currency, count(*) AS usos
                  FROM usadas u JOIN bank_accounts a ON a.id = u.bank_account_id
                 GROUP BY u.cash_box_id, u.bank_account_id, a.currency
            ),
            elegidas AS (
                SELECT DISTINCT ON (k.cash_box_id, k.currency) k.cash_box_id, k.bank_account_id
                  FROM cuentas k
                  LEFT JOIN cash_book_openings o ON o.cash_box_id = k.cash_box_id AND o.bank_account_id = k.bank_account_id
                 ORDER BY k.cash_box_id, k.currency, (o.id IS NULL), k.usos DESC, k.bank_account_id
            )
            INSERT INTO cash_box_bank_accounts (cash_box_id, bank_account_id, currency, is_default)
            SELECT k.cash_box_id, k.bank_account_id, k.currency,
                   EXISTS (SELECT 1 FROM elegidas e WHERE e.cash_box_id = k.cash_box_id AND e.bank_account_id = k.bank_account_id)
              FROM cuentas k;
        SQL);

        // La cita del área para la cuenta de Multas, y la referencia histórica de lo ya rendido.
        $cuentaDeMultas = DB::table('cash_book_openings')
            ->join('cash_boxes', 'cash_boxes.id', '=', 'cash_book_openings.cash_box_id')
            ->where('cash_boxes.code', 'multas')
            ->whereNotNull('cash_book_openings.bank_account_id')
            ->value('cash_book_openings.bank_account_id');

        if ($cuentaDeMultas !== null) {
            DB::table('bank_accounts')->where('id', $cuentaDeMultas)->update(['document_citation' => self::CITACION_DE_MULTAS]);
        }

        $referencia = (string) json_encode($cuentaDeMultas === null ? [] : [['id' => (int) $cuentaDeMultas, 'citation' => self::CITACION_DE_MULTAS]]);

        DB::statement("ALTER TABLE fine_monthly_reports ADD COLUMN accounts jsonb NOT NULL DEFAULT '[]'::jsonb CHECK (jsonb_typeof(accounts) = 'array')");
        DB::statement('ALTER TABLE fine_monthly_reports DISABLE TRIGGER fine_monthly_reports_frozen');
        DB::update('UPDATE fine_monthly_reports SET accounts = ?::jsonb', [$referencia]);
        DB::statement('ALTER TABLE fine_monthly_reports ENABLE TRIGGER fine_monthly_reports_frozen');
        DB::statement('ALTER TABLE fine_monthly_reports ALTER COLUMN accounts DROP DEFAULT');
    }

    /**
     * La política de cobros enteros ya no compara con la cuenta de la
     * apertura: de eso se encarga `cash_transfer_follows_box_accounts`.
     */
    private function depositPolicyWithoutOpeningAccount(): void
    {
        DB::unprepared(self::POLITICA_POR_VINCULOS);
    }

    public function down(): void
    {
        DB::unprepared(self::POLITICA_POR_APERTURA);
        DB::unprepared(self::HISTORICO_POR_APERTURA);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_allocations_follow_box_accounts ON bank_transaction_allocations;
            DROP FUNCTION IF EXISTS bank_allocation_follows_box_accounts();
            DROP TRIGGER IF EXISTS cash_transfers_follow_box_accounts ON cash_to_bank_transfers;
            DROP FUNCTION IF EXISTS cash_transfer_follows_box_accounts();
            DROP TABLE IF EXISTS cash_box_bank_accounts;
            DROP FUNCTION IF EXISTS cash_box_bank_account_window();
            DROP FUNCTION IF EXISTS cash_box_has_one_default();
            DROP FUNCTION IF EXISTS cash_box_bank_account_row();
            DROP FUNCTION IF EXISTS cash_box_account_covers(bigint, bigint, date);
            ALTER TABLE fine_monthly_reports DROP COLUMN IF EXISTS accounts;
            ALTER TABLE bank_accounts DROP COLUMN IF EXISTS document_citation;
        SQL);
    }
};
