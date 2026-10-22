<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cheque rechazado y su reemplazo — Multas M03, DER físico §6.6.
 *
 * Un rechazo conserva la aplicación y el recibo del pago: lo que cambia es
 * dónde está la plata, que ya no está. El cheque pasa a `REJECTED_CHEQUES`
 * —lo que la empresa tiene que regularizar— y deja de contar como fondos en
 * custodia, en tránsito o en el banco. La empresa lo regulariza con otro
 * cheque del mismo importe, que vuelve a custodia sin recibo nuevo ni nueva
 * recaudación; si también se rechaza, se repite, conservando la cadena.
 *
 * El banco se sigue aparte, con un asiento por hecho real y sin débitos
 * ficticios (`bank_status`):
 * - `not_applicable`: el cheque nunca salió de la caja, o se confirmó que el
 *   banco nunca lo acreditó;
 * - `unverified`: se depositó, el sistema no había registrado la
 *   acreditación, y el extracto todavía no dijo nada. No conciliado no es
 *   lo mismo que nunca acreditado;
 * - `debit_pending`: el banco acreditó y avisó el rechazo; el débito no
 *   está en el extracto (`PENDING_BANK_DEBITS`);
 * - `debit_recorded`: el débito está en el extracto y se imputó.
 *
 * Solo para cajas que depositan cobros enteros: en Haberes un cheque se
 * reparte entre cuotas y su rechazo tendría otra semántica.
 */
return new class extends Migration
{
    private const TRASLADO_CON_DEVUELTO = <<<'SQL'
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

    private const TRASLADO_ORIGINAL = <<<'SQL'
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
                    AND NOT (OLD.status = 'deposited' AND NEW.status IN ('bank_confirmed', 'cancelled'))
                THEN
                    RAISE EXCEPTION 'Un traslado % no pasa a %: solo avanza el que espera acreditación.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    private const CHEQUE_SIGUE_AL_TRASLADO = <<<'SQL'
            CREATE OR REPLACE FUNCTION cheque_follows_transfer(p_receipt_id bigint) RETURNS void AS $$
            DECLARE
                v_cheque RECORD;
                v_esperado TEXT;
            BEGIN
                SELECT medium, cheque_status, cheque_number INTO v_cheque
                  FROM fund_receipts WHERE id = p_receipt_id;

                IF v_cheque.medium IS DISTINCT FROM 'cheque' THEN
                    RETURN;
                END IF;

                /*
                 * Devuelto sin acreditar, o acreditado y rechazado después: en
                 * los dos casos el cheque figura rechazado.
                 */
                SELECT CASE
                         WHEN t.status = 'deposited' THEN 'deposited'
                         WHEN t.status = 'bounced' THEN 'rejected'
                         WHEN EXISTS (SELECT 1 FROM cheque_rejections c WHERE c.fund_receipt_id = p_receipt_id) THEN 'rejected'
                         ELSE 'cleared'
                       END
                  INTO v_esperado
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = p_receipt_id
                   AND t.status <> 'cancelled'
                 LIMIT 1;

                IF v_esperado IS NOT NULL AND v_cheque.cheque_status IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'El cheque % viajó en un traslado vigente: tiene que figurar %, no %.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_esperado, v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Rechazado es tener un rechazo registrado, y al revés.
                IF (v_cheque.cheque_status = 'rejected')
                    <> EXISTS (SELECT 1 FROM cheque_rejections c WHERE c.fund_receipt_id = p_receipt_id) THEN
                    RAISE EXCEPTION 'El cheque % figura % y su rechazo dice otra cosa: rechazado es tener el rechazo registrado.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_esperado IS NULL AND v_cheque.cheque_status IN ('deposited', 'cleared') THEN
                    RAISE EXCEPTION 'El cheque % figura % sin ningún traslado vigente que lo haya llevado al banco.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_rejection
                AFTER INSERT ON cheque_rejections
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_item();
        SQL;

    private const CHEQUE_SIGUE_AL_TRASLADO_ORIGINAL = <<<'SQL'
            CREATE OR REPLACE FUNCTION cheque_follows_transfer(p_receipt_id bigint) RETURNS void AS $$
            DECLARE
                v_cheque RECORD;
                v_esperado TEXT;
            BEGIN
                SELECT medium, cheque_status, cheque_number INTO v_cheque
                  FROM fund_receipts WHERE id = p_receipt_id;

                IF v_cheque.medium IS DISTINCT FROM 'cheque' THEN
                    RETURN;
                END IF;

                SELECT CASE t.status WHEN 'deposited' THEN 'deposited' ELSE 'cleared' END
                  INTO v_esperado
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = p_receipt_id
                   AND t.status <> 'cancelled'
                 LIMIT 1;

                IF v_esperado IS NOT NULL AND v_cheque.cheque_status IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'El cheque % viajó en un traslado vigente: tiene que figurar %, no %.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_esperado, v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_esperado IS NULL AND v_cheque.cheque_status IN ('deposited', 'cleared') THEN
                    RAISE EXCEPTION 'El cheque % figura % sin ningún traslado vigente que lo haya llevado al banco.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    private const POLITICA_CON_REEMPLAZOS = <<<'SQL'
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

    private const POLITICA_ORIGINAL = <<<'SQL'
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
                    OR v_item.origin <> 'received'
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

    private const SALDOS_CON_REGULARIZACION = <<<'SQL'
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid,
                       -- El instrumento entero —excedente incluido—, una vez por cadena, mientras su rechazo esté abierto (M03).
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a
                            WHERE a.fine_id = f.id
                              AND EXISTS (
                                  SELECT 1 FROM cheque_rejections c
                                   WHERE c.replacement_fund_receipt_id IS NULL
                                     AND fund_receipt_root(c.fund_receipt_id) = a.fund_receipt_id
                              )
                       ), 0)::numeric(19,2) AS pending_regularization
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess,
                   pending_regularization
              FROM base;
        SQL;

    private const SALDOS_ORIGINAL = <<<'SQL'
            DROP VIEW fine_balances;
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess
              FROM base;
        SQL;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ── Cuentas y hechos ──────────────────────────────────────────────
            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_account_check;
            ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
                CHECK (account_code IN (
                    'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                    'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE',
                    'COLLECTED_FUNDS', 'PENDING_EXCESS', 'REJECTED_CHEQUES', 'PENDING_BANK_DEBITS'
                ));

            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN (
                    'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated',
                    'funds_applied', 'excess_reclassified',
                    'cheque_rejected', 'rejected_cheque_credited', 'cheque_debit_recorded', 'cheque_replaced'
                ));

            ALTER TABLE bank_transaction_allocations DROP CONSTRAINT bank_transaction_allocations_role_check;
            ALTER TABLE bank_transaction_allocations ADD CONSTRAINT bank_transaction_allocations_role_check
                CHECK (allocation_role IN (
                    'funds_received', 'payment_confirmation', 'cash_deposit_confirmation',
                    'cheque_rejection_credit', 'cheque_rejection_debit'
                ));

            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_status_check;
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
                CHECK (status IN ('deposited', 'bank_confirmed', 'cancelled', 'bounced'));

            -- ── La cadena de instrumentos ─────────────────────────────────────
            ALTER TABLE fund_receipts DROP CONSTRAINT fund_receipts_origin_check;
            ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_origin_check
                CHECK (origin IN ('received', 'opening', 'legacy', 'replacement'));
            ALTER TABLE fund_receipts
                ADD COLUMN replaces_fund_receipt_id bigint UNIQUE REFERENCES fund_receipts(id) ON DELETE RESTRICT;
            ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_replacement_check
                CHECK ((origin = 'replacement') = (replaces_fund_receipt_id IS NOT NULL));

            -- El primero de la cadena: donde vive la aplicación al pago.
            CREATE OR REPLACE FUNCTION fund_receipt_root(p_id bigint) RETURNS bigint AS $$
                WITH RECURSIVE cadena(id, anterior) AS (
                    SELECT id, replaces_fund_receipt_id FROM fund_receipts WHERE id = p_id
                    UNION ALL
                    SELECT r.id, r.replaces_fund_receipt_id
                      FROM fund_receipts r JOIN cadena c ON r.id = c.anterior
                )
                SELECT id FROM cadena WHERE anterior IS NULL;
            $$ LANGUAGE sql STABLE;

            -- El último de la cadena: el instrumento vigente.
            CREATE OR REPLACE FUNCTION fund_receipt_tip(p_id bigint) RETURNS bigint AS $$
                WITH RECURSIVE cadena(id) AS (
                    SELECT p_id
                    UNION ALL
                    SELECT r.id FROM fund_receipts r JOIN cadena c ON r.replaces_fund_receipt_id = c.id
                )
                SELECT c.id FROM cadena c
                 WHERE NOT EXISTS (SELECT 1 FROM fund_receipts r WHERE r.replaces_fund_receipt_id = c.id);
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION fund_receipts_origin_matches_event() RETURNS trigger AS $$
            DECLARE
                v_tipo TEXT;
                v_esperado TEXT;
            BEGIN
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.financial_event_id;

                v_esperado := CASE v_tipo
                    WHEN 'opening_balance' THEN 'opening'
                    WHEN 'legacy_funds_allocated' THEN 'legacy'
                    WHEN 'cheque_replaced' THEN 'replacement'
                    ELSE 'received'
                END;

                IF NEW.origin IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'Una recepción de un evento % tiene origen %, no %.', v_tipo, v_esperado, NEW.origin
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            -- El reemplazo: otro cheque del mismo importe, de la misma caja y moneda, en custodia (M03).
            CREATE OR REPLACE FUNCTION fund_receipts_replacement_matches() RETURNS trigger AS $$
            DECLARE
                v_anterior RECORD;
            BEGIN
                SELECT medium, amount, currency, cash_box_id, cheque_status INTO v_anterior
                  FROM fund_receipts WHERE id = NEW.replaces_fund_receipt_id FOR UPDATE;

                IF v_anterior.cheque_status IS DISTINCT FROM 'rejected' THEN
                    RAISE EXCEPTION 'Solo se reemplaza un cheque rechazado.' USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.medium <> 'cheque' OR NEW.amount <> v_anterior.amount OR NEW.currency <> v_anterior.currency
                    OR NEW.cash_box_id IS DISTINCT FROM v_anterior.cash_box_id
                    OR NEW.cheque_status IS DISTINCT FROM 'in_custody' THEN
                    RAISE EXCEPTION 'Un cheque rechazado se reemplaza por otro cheque del mismo importe, en custodia: % y no %.',
                        v_anterior.amount, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_replacement_matches BEFORE INSERT ON fund_receipts
                FOR EACH ROW WHEN (NEW.replaces_fund_receipt_id IS NOT NULL)
                EXECUTE FUNCTION fund_receipts_replacement_matches();

            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.currency IS DISTINCT FROM OLD.currency
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                        OR NEW.replaces_fund_receipt_id IS DISTINCT FROM OLD.replaces_fund_receipt_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    -- Un cheque rechazado no vuelve a ningún otro estado: se reemplaza.
                    IF OLD.cheque_status = 'rejected' AND NEW.cheque_status IS DISTINCT FROM 'rejected' THEN
                        RAISE EXCEPTION 'Un cheque rechazado no vuelve a custodia ni al banco: se reemplaza por otro.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;

            -- Un reemplazo no financia una cuota de Haberes: es el mismo pago, no plata nueva.
            CREATE OR REPLACE FUNCTION funding_allocations_not_from_replacements() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM fund_receipts WHERE id = NEW.fund_receipt_id AND origin = 'replacement') THEN
                    RAISE EXCEPTION 'Un cheque de reemplazo no se asigna: regulariza un pago que ya está aplicado.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER funding_allocations_not_from_replacements BEFORE INSERT ON funding_allocations
                FOR EACH ROW EXECUTE FUNCTION funding_allocations_not_from_replacements();

            -- El respaldo del rechazo, el aviso a la empresa y la disposición del cheque.
            ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check;
            ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
                CHECK (subject_type IN (
                    'expediente', 'import', 'receipt', 'payment_order',
                    'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                    'period_closing', 'legacy_document', 'fine_case', 'fine',
                    'fine_historical_movement', 'bank_account_opening', 'fine_payment', 'cheque_rejection'
                ));

            -- ── El rechazo ────────────────────────────────────────────────────
            CREATE TABLE cheque_rejections (
                id bigserial PRIMARY KEY,
                -- Un instrumento se rechaza una vez: un rechazo posterior es del reemplazo.
                fund_receipt_id bigint NOT NULL UNIQUE REFERENCES fund_receipts(id) ON DELETE RESTRICT,
                cash_to_bank_transfer_id bigint REFERENCES cash_to_bank_transfers(id) ON DELETE RESTRICT,
                rejected_on date NOT NULL,
                reason varchar(500) NOT NULL CHECK (btrim(reason) <> ''),
                rejection_event_id bigint NOT NULL UNIQUE REFERENCES financial_events(id) ON DELETE RESTRICT,
                registered_by bigint REFERENCES users(id) ON DELETE SET NULL,
                registered_at timestamptz NOT NULL DEFAULT now(),

                company_notified_on date,
                company_notice_recorded_by bigint REFERENCES users(id) ON DELETE SET NULL,
                company_notice_recorded_at timestamptz,

                disposition varchar(20) CHECK (disposition IN ('voided_returned', 'act')),
                disposition_recorded_by bigint REFERENCES users(id) ON DELETE SET NULL,
                disposition_recorded_at timestamptz,

                bank_status varchar(20) NOT NULL
                    CHECK (bank_status IN ('not_applicable', 'unverified', 'debit_pending', 'debit_recorded')),
                credit_bank_transaction_id bigint UNIQUE REFERENCES bank_transactions(id) ON DELETE RESTRICT,
                credit_event_id bigint UNIQUE REFERENCES financial_events(id) ON DELETE RESTRICT,
                debit_bank_transaction_id bigint UNIQUE REFERENCES bank_transactions(id) ON DELETE RESTRICT,
                debit_event_id bigint UNIQUE REFERENCES financial_events(id) ON DELETE RESTRICT,
                never_credited_note varchar(500),
                never_credited_by bigint REFERENCES users(id) ON DELETE SET NULL,
                never_credited_at timestamptz,

                replacement_fund_receipt_id bigint UNIQUE REFERENCES fund_receipts(id) ON DELETE RESTRICT,
                replacement_event_id bigint UNIQUE REFERENCES financial_events(id) ON DELETE RESTRICT,
                regularized_at timestamptz,

                created_at timestamptz,
                updated_at timestamptz,

                CONSTRAINT cheque_rejections_notice_check
                    CHECK ((company_notified_on IS NULL) = (company_notice_recorded_at IS NULL)),
                CONSTRAINT cheque_rejections_disposition_recorded_check
                    CHECK ((disposition IS NULL) = (disposition_recorded_at IS NULL)),
                CONSTRAINT cheque_rejections_regularization_check
                    CHECK (num_nonnulls(replacement_fund_receipt_id, replacement_event_id, regularized_at) IN (0, 3)),
                CONSTRAINT cheque_rejections_never_credited_check
                    CHECK ((never_credited_note IS NULL) = (never_credited_at IS NULL)
                        AND (never_credited_note IS NULL OR btrim(never_credited_note) <> '')),
                CONSTRAINT cheque_rejections_bank_check CHECK (
                    (credit_bank_transaction_id IS NULL) = (credit_event_id IS NULL)
                    AND (debit_bank_transaction_id IS NULL) = (debit_event_id IS NULL)
                    AND CASE bank_status
                        WHEN 'unverified' THEN credit_event_id IS NULL AND debit_event_id IS NULL AND never_credited_at IS NULL
                        WHEN 'not_applicable' THEN credit_event_id IS NULL AND debit_event_id IS NULL
                        WHEN 'debit_pending' THEN debit_event_id IS NULL AND never_credited_at IS NULL
                        ELSE debit_event_id IS NOT NULL AND never_credited_at IS NULL
                    END
                )
            );

            CREATE INDEX cheque_rejections_open_index ON cheque_rejections (id)
                WHERE replacement_fund_receipt_id IS NULL OR bank_status IN ('unverified', 'debit_pending');

            CREATE OR REPLACE FUNCTION cheque_rejections_guard() RETURNS trigger AS $$
            DECLARE
                v_recepcion RECORD;
                v_traslado RECORD;
                v_tipo text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un rechazo no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    SELECT r.medium, r.origin, r.cash_box_id, b.deposits_whole_receipts INTO v_recepcion
                      FROM fund_receipts r LEFT JOIN cash_boxes b ON b.id = r.cash_box_id
                     WHERE r.id = NEW.fund_receipt_id FOR UPDATE OF r;

                    IF v_recepcion.medium IS DISTINCT FROM 'cheque' OR NOT coalesce(v_recepcion.deposits_whole_receipts, false)
                        OR v_recepcion.origin NOT IN ('received', 'replacement') THEN
                        RAISE EXCEPTION 'El rechazo se registra para un cheque cobrado por una caja que deposita cobros enteros.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.rejection_event_id;
                    IF v_tipo IS DISTINCT FROM 'cheque_rejected' THEN
                        RAISE EXCEPTION 'El asiento del rechazo es un cheque_rejected, no %.', v_tipo USING ERRCODE = 'check_violation';
                    END IF;

                    SELECT t.id, t.status INTO v_traslado
                      FROM cash_to_bank_transfer_items i
                      JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                     WHERE i.fund_receipt_id = NEW.fund_receipt_id AND t.status <> 'cancelled';

                    IF NOT FOUND THEN
                        IF NEW.cash_to_bank_transfer_id IS NOT NULL OR NEW.bank_status <> 'not_applicable' THEN
                            RAISE EXCEPTION 'Un cheque que no salió de la caja no tiene seguimiento bancario.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    ELSIF NEW.cash_to_bank_transfer_id IS DISTINCT FROM v_traslado.id
                        OR (v_traslado.status = 'bounced' AND NEW.bank_status <> 'unverified')
                        OR (v_traslado.status = 'bank_confirmed' AND NEW.bank_status NOT IN ('debit_pending', 'debit_recorded'))
                        OR v_traslado.status NOT IN ('bounced', 'bank_confirmed') THEN
                        RAISE EXCEPTION 'El rechazo de un cheque depositado sigue a su traslado: devuelto sin verificar, o acreditado con su débito.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                -- Lo que pasó no se edita.
                IF NEW.fund_receipt_id IS DISTINCT FROM OLD.fund_receipt_id
                    OR NEW.cash_to_bank_transfer_id IS DISTINCT FROM OLD.cash_to_bank_transfer_id
                    OR NEW.rejected_on IS DISTINCT FROM OLD.rejected_on
                    OR NEW.reason IS DISTINCT FROM OLD.reason
                    OR NEW.rejection_event_id IS DISTINCT FROM OLD.rejection_event_id
                    OR NEW.registered_by IS DISTINCT FROM OLD.registered_by
                    OR NEW.registered_at IS DISTINCT FROM OLD.registered_at THEN
                    RAISE EXCEPTION 'El rechazo no se edita.' USING ERRCODE = 'restrict_violation';
                END IF;

                -- Lo que se completa después se escribe una vez.
                IF (OLD.company_notice_recorded_at IS NOT NULL
                        AND (NEW.company_notified_on, NEW.company_notice_recorded_by, NEW.company_notice_recorded_at)
                            IS DISTINCT FROM (OLD.company_notified_on, OLD.company_notice_recorded_by, OLD.company_notice_recorded_at))
                    OR (OLD.disposition_recorded_at IS NOT NULL
                        AND (NEW.disposition, NEW.disposition_recorded_by, NEW.disposition_recorded_at)
                            IS DISTINCT FROM (OLD.disposition, OLD.disposition_recorded_by, OLD.disposition_recorded_at))
                    OR (OLD.credit_event_id IS NOT NULL
                        AND (NEW.credit_bank_transaction_id, NEW.credit_event_id) IS DISTINCT FROM (OLD.credit_bank_transaction_id, OLD.credit_event_id))
                    OR (OLD.debit_event_id IS NOT NULL
                        AND (NEW.debit_bank_transaction_id, NEW.debit_event_id) IS DISTINCT FROM (OLD.debit_bank_transaction_id, OLD.debit_event_id))
                    OR (OLD.never_credited_at IS NOT NULL
                        AND (NEW.never_credited_note, NEW.never_credited_by, NEW.never_credited_at)
                            IS DISTINCT FROM (OLD.never_credited_note, OLD.never_credited_by, OLD.never_credited_at))
                    OR (OLD.regularized_at IS NOT NULL
                        AND (NEW.replacement_fund_receipt_id, NEW.replacement_event_id, NEW.regularized_at)
                            IS DISTINCT FROM (OLD.replacement_fund_receipt_id, OLD.replacement_event_id, OLD.regularized_at)) THEN
                    RAISE EXCEPTION 'Lo que se registró del rechazo se escribe una sola vez.' USING ERRCODE = 'restrict_violation';
                END IF;

                -- El seguimiento bancario solo avanza con hechos.
                IF NEW.bank_status IS DISTINCT FROM OLD.bank_status AND NOT (
                    (OLD.bank_status = 'unverified' AND NEW.bank_status IN ('not_applicable', 'debit_pending', 'debit_recorded'))
                    OR (OLD.bank_status = 'debit_pending' AND NEW.bank_status = 'debit_recorded')
                ) THEN
                    RAISE EXCEPTION 'El seguimiento bancario de un rechazo no pasa de % a %.', OLD.bank_status, NEW.bank_status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.credit_event_id IS DISTINCT FROM OLD.credit_event_id THEN
                    SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.credit_event_id;
                    IF v_tipo IS DISTINCT FROM 'rejected_cheque_credited' THEN
                        RAISE EXCEPTION 'El crédito del rechazo se asienta como rejected_cheque_credited.' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.debit_event_id IS DISTINCT FROM OLD.debit_event_id THEN
                    SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.debit_event_id;
                    IF v_tipo IS DISTINCT FROM 'cheque_debit_recorded' THEN
                        RAISE EXCEPTION 'El débito del rechazo se asienta como cheque_debit_recorded.' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                IF NEW.replacement_event_id IS DISTINCT FROM OLD.replacement_event_id THEN
                    SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.replacement_event_id;
                    IF v_tipo IS DISTINCT FROM 'cheque_replaced' THEN
                        RAISE EXCEPTION 'El reemplazo se asienta como cheque_replaced.' USING ERRCODE = 'check_violation';
                    END IF;
                    -- Los dos vínculos de la cadena dicen lo mismo.
                    IF NOT EXISTS (
                        SELECT 1 FROM fund_receipts
                         WHERE id = NEW.replacement_fund_receipt_id AND replaces_fund_receipt_id = NEW.fund_receipt_id
                    ) THEN
                        RAISE EXCEPTION 'El reemplazo del rechazo tiene que reemplazar a ese cheque.' USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cheque_rejections_guard BEFORE INSERT OR UPDATE OR DELETE ON cheque_rejections
                FOR EACH ROW EXECUTE FUNCTION cheque_rejections_guard();

            -- Un reemplazo nace con su rechazo regularizado: los dos vínculos juntos, al confirmar.
            CREATE OR REPLACE FUNCTION fund_receipts_replacement_linked() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM cheque_rejections
                     WHERE fund_receipt_id = NEW.replaces_fund_receipt_id AND replacement_fund_receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Un cheque de reemplazo cierra el rechazo del cheque que reemplaza.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fund_receipts_replacement_linked
                AFTER INSERT ON fund_receipts DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW WHEN (NEW.replaces_fund_receipt_id IS NOT NULL)
                EXECUTE FUNCTION fund_receipts_replacement_linked();

            -- Un traslado devuelto lo explica un rechazo.
            CREATE OR REPLACE FUNCTION cash_to_bank_transfers_bounced_by_rejection() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM cheque_rejections WHERE cash_to_bank_transfer_id = NEW.id) THEN
                    RAISE EXCEPTION 'Un traslado figura devuelto solo por el rechazo de su cheque.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cash_to_bank_transfers_bounced_by_rejection
                AFTER UPDATE OF status ON cash_to_bank_transfers DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW WHEN (NEW.status = 'bounced')
                EXECUTE FUNCTION cash_to_bank_transfers_bounced_by_rejection();

            -- El crédito y el débito del rechazo: movimientos enteros del extracto, sin otro uso.
            CREATE OR REPLACE FUNCTION cheque_rejection_movement_whole() RETURNS trigger AS $$
            DECLARE
                v_movimiento RECORD;
                v_otros numeric(19, 2);
                v_sentido text := CASE NEW.allocation_role WHEN 'cheque_rejection_debit' THEN 'debit' ELSE 'credit' END;
                v_que text := CASE NEW.allocation_role WHEN 'cheque_rejection_debit' THEN 'débito' ELSE 'crédito' END;
            BEGIN
                SELECT abs(amount) AS importe, direction INTO v_movimiento
                  FROM bank_transactions WHERE id = NEW.bank_transaction_id;
                SELECT coalesce(sum(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_otros
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id AND id <> NEW.id;

                IF v_movimiento.direction <> v_sentido OR NEW.amount <> v_movimiento.importe OR v_otros <> 0 THEN
                    RAISE EXCEPTION 'El % de un cheque rechazado es un movimiento entero del extracto y sin otro uso.', v_que
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cheque_rejection_movement_whole
                AFTER INSERT ON bank_transaction_allocations
                FOR EACH ROW
                WHEN (NEW.allocation_role IN ('cheque_rejection_credit', 'cheque_rejection_debit') AND NEW.reversal_of_id IS NULL)
                EXECUTE FUNCTION cheque_rejection_movement_whole();
        SQL);

        DB::unprepared(self::TRASLADO_CON_DEVUELTO);
        DB::unprepared(self::CHEQUE_SIGUE_AL_TRASLADO);
        DB::unprepared(self::POLITICA_CON_REEMPLAZOS);
        DB::unprepared(self::SALDOS_CON_REGULARIZACION);
    }

    public function down(): void
    {
        DB::unprepared(self::SALDOS_ORIGINAL);
        DB::unprepared(self::POLITICA_ORIGINAL);
        DB::unprepared(self::CHEQUE_SIGUE_AL_TRASLADO_ORIGINAL);
        DB::unprepared(self::TRASLADO_ORIGINAL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER cheque_rejection_movement_whole ON bank_transaction_allocations;
            DROP FUNCTION cheque_rejection_movement_whole();
            DROP TRIGGER cash_to_bank_transfers_bounced_by_rejection ON cash_to_bank_transfers;
            DROP FUNCTION cash_to_bank_transfers_bounced_by_rejection();
            DROP TRIGGER fund_receipts_replacement_linked ON fund_receipts;
            DROP FUNCTION fund_receipts_replacement_linked();
            DROP TABLE cheque_rejections;
            ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check;
            ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
                CHECK (subject_type IN (
                    'expediente', 'import', 'receipt', 'payment_order',
                    'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                    'period_closing', 'legacy_document', 'fine_case', 'fine',
                    'fine_historical_movement', 'bank_account_opening', 'fine_payment'
                ));
            DROP FUNCTION cheque_rejections_guard();
            DROP TRIGGER funding_allocations_not_from_replacements ON funding_allocations;
            DROP FUNCTION funding_allocations_not_from_replacements();
            DROP TRIGGER fund_receipts_replacement_matches ON fund_receipts;
            DROP FUNCTION fund_receipts_replacement_matches();
            DROP FUNCTION fund_receipt_tip(bigint);
            DROP FUNCTION fund_receipt_root(bigint);

            CREATE OR REPLACE FUNCTION fund_receipts_origin_matches_event() RETURNS trigger AS $$
            DECLARE
                v_tipo TEXT;
                v_esperado TEXT;
            BEGIN
                SELECT event_type INTO v_tipo FROM financial_events WHERE id = NEW.financial_event_id;

                v_esperado := CASE v_tipo
                    WHEN 'opening_balance' THEN 'opening'
                    WHEN 'legacy_funds_allocated' THEN 'legacy'
                    ELSE 'received'
                END;

                IF NEW.origin IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'Una recepción de un evento % tiene origen %, no %.', v_tipo, v_esperado, NEW.origin
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.currency IS DISTINCT FROM OLD.currency
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;

            ALTER TABLE fund_receipts DROP CONSTRAINT fund_receipts_replacement_check;
            ALTER TABLE fund_receipts DROP COLUMN replaces_fund_receipt_id;
            ALTER TABLE fund_receipts DROP CONSTRAINT fund_receipts_origin_check;
            ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_origin_check
                CHECK (origin IN ('received', 'opening', 'legacy'));

            ALTER TABLE cash_to_bank_transfers DROP CONSTRAINT cash_to_bank_transfers_status_check;
            ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
                CHECK (status IN ('deposited', 'bank_confirmed', 'cancelled'));

            ALTER TABLE bank_transaction_allocations DROP CONSTRAINT bank_transaction_allocations_role_check;
            ALTER TABLE bank_transaction_allocations ADD CONSTRAINT bank_transaction_allocations_role_check
                CHECK (allocation_role IN ('funds_received', 'payment_confirmation', 'cash_deposit_confirmation'));

            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN (
                    'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated',
                    'funds_applied', 'excess_reclassified'
                ));

            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_account_check;
            ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
                CHECK (account_code IN (
                    'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                    'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE',
                    'COLLECTED_FUNDS', 'PENDING_EXCESS'
                ));
        SQL);
    }
};
