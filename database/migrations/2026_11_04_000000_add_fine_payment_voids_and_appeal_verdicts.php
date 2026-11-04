<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Anular un cobro de la caja y revertir el veredicto de un recurso
 * (decisión del usuario, 03/11/2026; P13 queda parcialmente resuelto).
 *
 * **Anular** afirma que el dinero nunca entró: solo vale mientras siga en la
 * caja. El cobro queda a la vista como `voided`, sus asientos se revierten y
 * el recibo se anula con su número. La fila de `fine_payment_voids` es la que
 * autoriza revertir la recepción, así que se escribe antes.
 *
 * **El veredicto pasa a ser una fila**: revertirlo no borra quién lo dio, y
 * cada reducción por prosperar queda vinculada al suyo. `fine_appeals.status`
 * sigue siendo el estado actual. Un veredicto vigente exige el pago aplicado,
 * y revertirlo vuelve a congelar la multa: desde el veredicto no puede haber
 * otros pagos, antecedentes ni movimientos de importe.
 */
return new class extends Migration
{
    private const AJUSTE = "'appeal_admitted', 'court_ruling', 'resolution_correction', 'other'";

    private const CORRECCION_DE_IMPORTE = "'data_entry_error', 'misread_paper', 'other'";

    private const REVERSION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

    private const ANULACION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

    private const REVERSION_DE_VEREDICTO = "'data_entry_error', 'other'";

    /** Lo que cambia sobre las guardas de `2026_11_03_000000_add_fine_appeals.php`. */
    private const GUARDAS = <<<'SQL'
            -- Si el importe se movió desde un punto de la historia de la multa, sin contar lo que se deshizo después.
            CREATE OR REPLACE FUNCTION fine_amount_moved_since(p_fine_id bigint, p_after bigint) RETURNS boolean AS $$
                SELECT EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = p_fine_id
                       AND e.id > p_after
                       AND e.fine_appeal_verdict_id IS NULL
                       AND NOT (e.kind = 'reversal' AND e.reversal_of_id > p_after)
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                );
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION fine_appeals_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un recurso no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                    IF NEW.status <> 'open' THEN
                        RAISE EXCEPTION 'Un recurso nace en trámite.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF (SELECT debt FROM fine_balances WHERE fine_id = NEW.fine_id) <= 0 THEN
                        RAISE EXCEPTION 'La multa no tiene saldo pendiente: no hay recurso que pagar.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM fine_payments WHERE fine_id = NEW.fine_id AND status NOT IN ('discarded', 'voided'))
                       OR EXISTS (SELECT 1 FROM fine_historical_movements WHERE fine_id = NEW.fine_id AND status <> 'rejected') THEN
                        RAISE EXCEPTION 'La multa ya tiene pagos: el recurso se registra antes de cualquier pago.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'updated_at']) THEN
                    RAISE EXCEPTION 'De un recurso solo cambia su estado.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'open' AND NEW.status IN ('upheld', 'rejected', 'dropped'))
                    OR (OLD.status IN ('upheld', 'rejected') AND NEW.status = 'open')
                ) THEN
                    RAISE EXCEPTION 'Un recurso % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            /*
             * Se da sobre un recurso en trámite y queda como está: de un
             * veredicto solo se completa su reversión, una vez. Guarda hasta
             * qué movimiento de importe llegaba la multa: lo que vino después
             * impide revertirlo.
             */
            CREATE OR REPLACE FUNCTION fine_appeal_verdicts_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un veredicto no se borra: se revierte.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                    IF (SELECT status FROM fine_appeals WHERE id = NEW.fine_appeal_id) IS DISTINCT FROM 'open' THEN
                        RAISE EXCEPTION 'El veredicto se da sobre un recurso en trámite.' USING ERRCODE = 'check_violation';
                    END IF;

                    NEW.debt_entries_until_id := COALESCE((SELECT max(id) FROM fine_debt_entries WHERE fine_id = NEW.fine_id), 0);

                    RETURN NEW;
                END IF;

                IF OLD.reverted_at IS NOT NULL
                   OR (to_jsonb(NEW) - ARRAY['reverted_at', 'reverted_by', 'revert_reason_code', 'revert_reason', 'updated_at'])
                      IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['reverted_at', 'reverted_by', 'revert_reason_code', 'revert_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'De un veredicto solo se completa su reversión, una vez.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_appeal_verdicts_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_appeal_verdicts
                FOR EACH ROW EXECUTE FUNCTION fine_appeal_verdicts_guard();

            /*
             * El importe no se mueve con el recurso en trámite, ni después de
             * que prosperó. Las excepciones son la reducción del veredicto,
             * que lleva la obligación exactamente a lo cobrado, y su reversión,
             * que solo existe si el veredicto se revirtió.
             */
            CREATE OR REPLACE FUNCTION fine_debt_entries_follow_appeal() RETURNS trigger AS $$
            DECLARE
                v_estado varchar;
                v_pagado numeric(19,2);
                v_veredicto RECORD;
            BEGIN
                IF NEW.kind = 'constitution' THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                v_estado := fine_appeal_status(NEW.fine_id);

                IF NEW.fine_appeal_verdict_id IS NULL THEN
                    IF v_estado = 'open' THEN
                        RAISE EXCEPTION 'La multa está en recurso: hasta resolverlo no admite cambios de importe.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_estado = 'upheld' THEN
                        RAISE EXCEPTION 'El recurso de la multa prosperó: su importe ya no se modifica.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.kind = 'reversal' AND EXISTS (
                        SELECT 1 FROM fine_debt_entries WHERE id = NEW.reversal_of_id AND fine_appeal_verdict_id IS NOT NULL
                    ) THEN
                        RAISE EXCEPTION 'La reducción de un veredicto se revierte solo revirtiendo el veredicto.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                SELECT v.verdict, v.reverted_at, a.status AS appeal_status INTO v_veredicto
                  FROM fine_appeal_verdicts v
                  JOIN fine_appeals a ON a.id = v.fine_appeal_id
                 WHERE v.id = NEW.fine_appeal_verdict_id;

                IF NEW.kind = 'reversal' THEN
                    IF v_veredicto.reverted_at IS NULL THEN
                        RAISE EXCEPTION 'La reducción de un veredicto se revierte solo revirtiendo el veredicto.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM fine_debt_entries
                         WHERE id = NEW.reversal_of_id AND fine_appeal_verdict_id = NEW.fine_appeal_verdict_id AND kind = 'reduction'
                    ) THEN
                        RAISE EXCEPTION 'Revertir un veredicto revierte su propia reducción.' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF v_veredicto.verdict <> 'upheld' OR v_veredicto.reverted_at IS NOT NULL OR v_veredicto.appeal_status <> 'upheld' THEN
                    RAISE EXCEPTION 'Solo un recurso que prosperó reduce la multa.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT operational_paid + historical_paid INTO v_pagado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF fine_obligation(NEW.fine_id) - NEW.amount <> v_pagado THEN
                    RAISE EXCEPTION 'El recurso que prospera lleva la multa a lo cobrado: % y no %.',
                        v_pagado, fine_obligation(NEW.fine_id) - NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            /*
             * Lo que el trámite exige al confirmar:
             *
             * - en trámite: exactamente un pago vivo y ningún veredicto vigente;
             *   si viene de revertir uno, sin antecedentes vivos ni movimientos
             *   de importe desde entonces;
             * - cayó: ningún pago vivo;
             * - con veredicto: su pago aplicado y el veredicto vigente del
             *   mismo tipo; si prosperó, la multa sin deuda;
             * - un veredicto revertido deja revertida su reducción.
             */
            CREATE OR REPLACE FUNCTION fine_appeal_consistent() RETURNS trigger AS $$
            DECLARE
                v_recurso record;
                v_vivos integer;
                v_aplicados integer;
                v_vigente varchar;
                v_revertido bigint;
            BEGIN
                FOR v_recurso IN SELECT id, status FROM fine_appeals WHERE fine_id = NEW.fine_id LOOP
                    SELECT count(*) FILTER (WHERE status NOT IN ('discarded', 'voided')), count(*) FILTER (WHERE status = 'applied')
                      INTO v_vivos, v_aplicados
                      FROM fine_payments WHERE fine_appeal_id = v_recurso.id;

                    SELECT verdict INTO v_vigente FROM fine_appeal_verdicts
                     WHERE fine_appeal_id = v_recurso.id AND reverted_at IS NULL;

                    IF v_recurso.status IN ('upheld', 'rejected') THEN
                        IF v_vivos <> 1 OR v_aplicados <> 1 THEN
                            RAISE EXCEPTION 'El recurso se resuelve con su pago cobrado o verificado.' USING ERRCODE = 'check_violation';
                        END IF;

                        IF v_vigente IS DISTINCT FROM v_recurso.status THEN
                            RAISE EXCEPTION 'Un recurso resuelto tiene vigente el veredicto que dice su estado.' USING ERRCODE = 'check_violation';
                        END IF;
                    ELSIF v_vigente IS NOT NULL THEN
                        RAISE EXCEPTION 'Un recurso sin resolver no tiene un veredicto vigente.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'open' THEN
                        IF v_vivos <> 1 THEN
                            RAISE EXCEPTION 'Un recurso en trámite tiene su pago: sin él, el recurso cae.'
                                USING ERRCODE = 'check_violation';
                        END IF;

                        SELECT debt_entries_until_id INTO v_revertido FROM fine_appeal_verdicts
                         WHERE fine_appeal_id = v_recurso.id ORDER BY id DESC LIMIT 1;

                        IF v_revertido IS NOT NULL AND (
                            fine_amount_moved_since(NEW.fine_id, v_revertido)
                            OR EXISTS (SELECT 1 FROM fine_historical_movements WHERE fine_id = NEW.fine_id AND status <> 'rejected')
                            OR EXISTS (SELECT 1 FROM fine_payments
                                        WHERE fine_id = NEW.fine_id AND fine_appeal_id IS NULL AND status NOT IN ('discarded', 'voided'))
                        ) THEN
                            RAISE EXCEPTION 'Desde el veredicto la multa tuvo otras operaciones: no vuelve a congelarse.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;

                    IF v_recurso.status = 'dropped' AND v_vivos <> 0 THEN
                        RAISE EXCEPTION 'Un recurso que cayó no conserva pagos vivos.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'upheld' AND (SELECT debt FROM fine_balances WHERE fine_id = NEW.fine_id) <> 0 THEN
                        RAISE EXCEPTION 'El recurso que prosperó deja la multa cancelada.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF EXISTS (
                        SELECT 1 FROM fine_appeal_verdicts v
                          JOIN fine_debt_entries e ON e.fine_appeal_verdict_id = v.id AND e.kind = 'reduction'
                         WHERE v.fine_appeal_id = v_recurso.id AND v.reverted_at IS NOT NULL
                           AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                    ) THEN
                        RAISE EXCEPTION 'Un veredicto revertido deja revertida su reducción.' USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_appeal_verdicts_consistent AFTER INSERT OR UPDATE ON fine_appeal_verdicts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_appeal_consistent();

            /*
             * Anular exige que el dinero siga en la caja. Bloquea la multa y la
             * recepción, la misma fila que toma un depósito (`transfer_follows_box_policy`):
             * los dos se serializan y el segundo ve lo que confirmó el primero.
             */
            CREATE OR REPLACE FUNCTION fine_payment_voids_coherent() RETURNS trigger AS $$
            DECLARE
                v_pago RECORD;
                v_aplicacion RECORD;
                v_recepcion RECORD;
            BEGIN
                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                SELECT status, medium INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF v_pago.status <> 'applied' OR v_pago.medium NOT IN ('cash', 'cheque') THEN
                    RAISE EXCEPTION 'Se anula un cobro de la caja ya aplicado.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT fund_receipt_id, application_event_id INTO v_aplicacion
                  FROM fine_payment_applications WHERE fine_payment_id = NEW.fine_payment_id;

                SELECT id, financial_event_id INTO v_recepcion
                  FROM fund_receipts WHERE id = v_aplicacion.fund_receipt_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM cash_to_bank_transfer_items i
                      JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                     WHERE i.fund_receipt_id = v_recepcion.id AND t.status <> 'cancelled'
                ) THEN
                    RAISE EXCEPTION 'Ese cobro ya se depositó: el dinero entró y no se anula.' USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM cheque_rejections WHERE fund_receipt_root(fund_receipt_id) = v_recepcion.id) THEN
                    RAISE EXCEPTION 'El cheque de ese cobro se rechazó: se regulariza, no se anula.' USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.application_reversal_event_id AND reversal_of_id = v_aplicacion.application_event_id AND status = 'posted'
                ) OR NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.receipt_reversal_event_id AND reversal_of_id = v_recepcion.financial_event_id AND status = 'posted'
                ) THEN
                    RAISE EXCEPTION 'La anulación se asienta revirtiendo la aplicación y la recepción del cobro.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_voids_coherent BEFORE INSERT ON fine_payment_voids
                FOR EACH ROW EXECUTE FUNCTION fine_payment_voids_coherent();
            CREATE TRIGGER fine_payment_voids_append_only BEFORE UPDATE OR DELETE ON fine_payment_voids
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();

            -- Una recepción anulada no viaja al banco: el dinero nunca estuvo.
            CREATE OR REPLACE FUNCTION fine_transfer_items_not_voided() RETURNS trigger AS $$
            BEGIN
                IF NEW.fund_receipt_id IS NULL THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM fund_receipts WHERE id = NEW.fund_receipt_id FOR NO KEY UPDATE;

                IF EXISTS (
                    SELECT 1 FROM fund_receipts
                     WHERE id = NEW.fund_receipt_id AND cash_box_id = multas_cash_box_id() AND reversal_event_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Ese cobro se anuló: no hay dinero que depositar.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_transfer_items_not_voided BEFORE INSERT ON cash_to_bank_transfer_items
                FOR EACH ROW EXECUTE FUNCTION fine_transfer_items_not_voided();

            -- Una anulación deja el pago anulado, su recibo anulado y su recepción revertida.
            CREATE OR REPLACE FUNCTION fine_payment_voids_settled() RETURNS trigger AS $$
            BEGIN
                IF (SELECT status FROM fine_payments WHERE id = NEW.fine_payment_id) <> 'voided' THEN
                    RAISE EXCEPTION 'La anulación del pago % no lo dejó anulado.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications a
                      JOIN fund_receipts r ON r.id = a.fund_receipt_id
                     WHERE a.fine_payment_id = NEW.fine_payment_id AND r.reversal_event_id = NEW.receipt_reversal_event_id
                ) THEN
                    RAISE EXCEPTION 'La anulación del pago % no revirtió su recepción.', NEW.fine_payment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_payment_voids_settled AFTER INSERT ON fine_payment_voids
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_payment_voids_settled();
            CREATE CONSTRAINT TRIGGER fine_payment_voids_excess_settled AFTER INSERT ON fine_payment_voids
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();

            -- El recibo de un pago vigente no se anula por su cuenta.
            CREATE OR REPLACE FUNCTION receipts_multas_voided_with_payment() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'voided' AND EXISTS (
                    SELECT 1 FROM fine_payment_receipts pr
                      JOIN fine_payments p ON p.id = pr.fine_payment_id
                     WHERE pr.receipt_id = NEW.id AND p.status <> 'voided'
                ) THEN
                    RAISE EXCEPTION 'El recibo % documenta un pago vigente: se anula anulando el cobro.', NEW.formatted_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER receipts_multas_voided_with_payment AFTER UPDATE OF status ON receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION receipts_multas_voided_with_payment();

            -- Un cobro aplicado a una multa se revierte solo anulándolo: la fila de anulación va primero.
            CREATE OR REPLACE FUNCTION fund_receipts_keep_fine_applications() RETURNS trigger AS $$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fine_payment_applications a
                     WHERE a.fund_receipt_id = NEW.id
                       AND NOT EXISTS (SELECT 1 FROM fine_payment_voids v WHERE v.fine_payment_id = a.fine_payment_id)
                ) THEN
                    RAISE EXCEPTION 'La recepción % está aplicada al pago de una multa: no se revierte sin anular el cobro.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            /*
             * Un pago aplicado tiene su aplicación y su recibo vigente; uno
             * anulado los conserva, con el recibo anulado y su anulación; los
             * demás no tienen ninguno.
             */
            CREATE OR REPLACE FUNCTION fine_payment_settled() RETURNS trigger AS $$
            DECLARE
                v_estado varchar;
                v_aplicacion boolean;
                v_recibo varchar;
                v_anulacion boolean;
            BEGIN
                SELECT status INTO v_estado FROM fine_payments WHERE id = NEW.id;
                v_aplicacion := EXISTS (SELECT 1 FROM fine_payment_applications WHERE fine_payment_id = NEW.id);
                SELECT r.status INTO v_recibo FROM fine_payment_receipts pr JOIN receipts r ON r.id = pr.receipt_id
                 WHERE pr.fine_payment_id = NEW.id;
                v_anulacion := EXISTS (SELECT 1 FROM fine_payment_voids WHERE fine_payment_id = NEW.id);

                IF v_estado = 'applied' AND NOT (v_aplicacion AND v_recibo = 'issued' AND NOT v_anulacion) THEN
                    RAISE EXCEPTION 'El pago % quedó aplicado sin su aplicación o sin su recibo vigente.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_estado = 'voided' AND NOT (v_aplicacion AND v_recibo = 'voided' AND v_anulacion) THEN
                    RAISE EXCEPTION 'El pago % quedó anulado sin su anulación o con el recibo vigente.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_estado NOT IN ('applied', 'voided') AND (v_aplicacion OR v_recibo IS NOT NULL OR v_anulacion) THEN
                    RAISE EXCEPTION 'El pago % tiene aplicación o recibo sin estar aplicado.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            -- Lo que un cobro anulado mandó a excedente ya salió del libro con su reversión.
            CREATE OR REPLACE FUNCTION fine_excess_settled() RETURNS trigger AS $$
            DECLARE
                v_asentado numeric(19,2);
                v_calculado numeric(19,2);
            BEGIN
                v_asentado := COALESCE((SELECT SUM(a.excess_amount) FROM fine_payment_applications a
                                         WHERE a.fine_id = NEW.fine_id
                                           AND NOT EXISTS (SELECT 1 FROM fine_payment_voids v WHERE v.fine_payment_id = a.fine_payment_id)), 0)
                    + COALESCE((SELECT SUM(amount) FROM fine_excess_reclassifications WHERE fine_id = NEW.fine_id), 0);
                SELECT operational_excess INTO v_calculado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF v_asentado <> v_calculado THEN
                    RAISE EXCEPTION 'El excedente asentado de la multa % es % y su saldo dice %: falta reclasificar.',
                        NEW.fine_id, v_asentado, v_calculado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    private const QUITAR_GUARDAS = <<<'SQL'
            DROP TRIGGER receipts_multas_voided_with_payment ON receipts;
            DROP FUNCTION receipts_multas_voided_with_payment();
            DROP TRIGGER fine_transfer_items_not_voided ON cash_to_bank_transfer_items;
            DROP FUNCTION fine_transfer_items_not_voided();
            DROP TRIGGER fine_payment_voids_excess_settled ON fine_payment_voids;
            DROP TRIGGER fine_payment_voids_settled ON fine_payment_voids;
            DROP FUNCTION fine_payment_voids_settled();
            DROP TRIGGER fine_payment_voids_append_only ON fine_payment_voids;
            DROP TRIGGER fine_payment_voids_coherent ON fine_payment_voids;
            DROP FUNCTION fine_payment_voids_coherent();
            DROP TRIGGER fine_appeal_verdicts_consistent ON fine_appeal_verdicts;
            DROP TRIGGER fine_appeal_verdicts_guard ON fine_appeal_verdicts;
            DROP FUNCTION fine_appeal_verdicts_guard();

            CREATE OR REPLACE FUNCTION fund_receipts_keep_fine_applications() RETURNS trigger AS $$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE fund_receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La recepción % está aplicada al pago de una multa: no se revierte.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_payment_settled() RETURNS trigger AS $$
            DECLARE
                v_aplicado boolean;
                v_aplicacion boolean;
                v_recibo boolean;
            BEGIN
                SELECT status = 'applied' INTO v_aplicado FROM fine_payments WHERE id = NEW.id;
                v_aplicacion := EXISTS (SELECT 1 FROM fine_payment_applications WHERE fine_payment_id = NEW.id);
                v_recibo := EXISTS (SELECT 1 FROM fine_payment_receipts WHERE fine_payment_id = NEW.id);

                IF v_aplicado AND NOT (v_aplicacion AND v_recibo) THEN
                    RAISE EXCEPTION 'El pago % quedó aplicado sin su aplicación o sin su recibo.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NOT v_aplicado AND (v_aplicacion OR v_recibo) THEN
                    RAISE EXCEPTION 'El pago % tiene aplicación o recibo sin estar aplicado.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_excess_settled() RETURNS trigger AS $$
            DECLARE
                v_asentado numeric(19,2);
                v_calculado numeric(19,2);
            BEGIN
                v_asentado := COALESCE((SELECT SUM(excess_amount) FROM fine_payment_applications WHERE fine_id = NEW.fine_id), 0)
                    + COALESCE((SELECT SUM(amount) FROM fine_excess_reclassifications WHERE fine_id = NEW.fine_id), 0);
                SELECT operational_excess INTO v_calculado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF v_asentado <> v_calculado THEN
                    RAISE EXCEPTION 'El excedente asentado de la multa % es % y su saldo dice %: falta reclasificar.',
                        NEW.fine_id, v_asentado, v_calculado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_appeals_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un recurso no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                    IF NEW.status <> 'open' THEN
                        RAISE EXCEPTION 'Un recurso nace en trámite.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF (SELECT debt FROM fine_balances WHERE fine_id = NEW.fine_id) <= 0 THEN
                        RAISE EXCEPTION 'La multa no tiene saldo pendiente: no hay recurso que pagar.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM fine_payments WHERE fine_id = NEW.fine_id AND status <> 'discarded')
                       OR EXISTS (SELECT 1 FROM fine_historical_movements WHERE fine_id = NEW.fine_id AND status <> 'rejected') THEN
                        RAISE EXCEPTION 'La multa ya tiene pagos: el recurso se registra antes de cualquier pago.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status <> 'open' THEN
                    RAISE EXCEPTION 'El recurso ya se resolvió: queda como está.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status = 'open'
                   OR (to_jsonb(NEW) - ARRAY['status', 'resolved_at', 'resolved_by', 'updated_at'])
                      IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'resolved_at', 'resolved_by', 'updated_at']) THEN
                    RAISE EXCEPTION 'Un recurso en trámite solo cambia al resolverse o al caer.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_follow_appeal() RETURNS trigger AS $$
            DECLARE
                v_estado varchar;
                v_pagado numeric(19,2);
            BEGIN
                IF NEW.kind = 'constitution' THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                v_estado := fine_appeal_status(NEW.fine_id);

                IF NEW.fine_appeal_id IS NULL THEN
                    IF v_estado = 'open' THEN
                        RAISE EXCEPTION 'La multa está en recurso: hasta resolverlo no admite cambios de importe.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_estado = 'upheld' THEN
                        RAISE EXCEPTION 'El recurso de la multa prosperó: su importe ya no se modifica.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.kind = 'reversal' AND EXISTS (
                        SELECT 1 FROM fine_debt_entries WHERE id = NEW.reversal_of_id AND fine_appeal_id IS NOT NULL
                    ) THEN
                        RAISE EXCEPTION 'El veredicto del recurso no se revierte.' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF (SELECT status FROM fine_appeals WHERE id = NEW.fine_appeal_id) IS DISTINCT FROM 'upheld' THEN
                    RAISE EXCEPTION 'Solo un recurso que prosperó reduce la multa.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT operational_paid + historical_paid INTO v_pagado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF fine_obligation(NEW.fine_id) - NEW.amount <> v_pagado THEN
                    RAISE EXCEPTION 'El recurso que prospera lleva la multa a lo cobrado: % y no %.',
                        v_pagado, fine_obligation(NEW.fine_id) - NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_appeal_consistent() RETURNS trigger AS $$
            DECLARE
                v_recurso record;
                v_vivos integer;
                v_aplicados integer;
            BEGIN
                FOR v_recurso IN SELECT id, status FROM fine_appeals WHERE fine_id = NEW.fine_id LOOP
                    SELECT count(*) FILTER (WHERE status <> 'discarded'), count(*) FILTER (WHERE status = 'applied')
                      INTO v_vivos, v_aplicados
                      FROM fine_payments WHERE fine_appeal_id = v_recurso.id;

                    IF v_recurso.status = 'open' AND v_vivos <> 1 THEN
                        RAISE EXCEPTION 'Un recurso en trámite tiene su pago: sin él, el recurso cae.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'dropped' AND v_vivos <> 0 THEN
                        RAISE EXCEPTION 'Un recurso que cayó no conserva pagos vivos.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'rejected' AND v_vivos > 1 THEN
                        RAISE EXCEPTION 'Un recurso tiene un solo pago.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'upheld' THEN
                        IF v_aplicados <> 1 THEN
                            RAISE EXCEPTION 'El recurso prospera con su pago cobrado.' USING ERRCODE = 'check_violation';
                        END IF;

                        IF (SELECT debt FROM fine_balances WHERE fine_id = NEW.fine_id) <> 0 THEN
                            RAISE EXCEPTION 'El recurso que prosperó deja la multa cancelada.' USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                END LOOP;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION fine_amount_moved_since(bigint, bigint);
        SQL;

    /**
     * La vista de `2026_11_03_000000_add_fine_appeals.php`; lo único que cambia
     * es qué aplicaciones cuentan.
     *
     * @param  literal-string  $cuenta
     * @return literal-string
     */
    private static function saldos(string $cuenta): string
    {
        return <<<SQL
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id{$cuenta}
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid,
                       -- El instrumento entero —excedente incluido—, una vez por cadena, mientras su rechazo esté abierto (M03).
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a
                            WHERE a.fine_id = f.id{$cuenta}
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
                   pending_regularization,
                   -- El recurso que no cayó, si hay: uno solo por multa.
                   (SELECT r.status FROM fine_appeals r WHERE r.fine_id = base.fine_id AND r.status <> 'dropped')::varchar(10) AS appeal_status
              FROM base;
        SQL;
    }

    public function up(): void
    {
        // Un veredicto ahora exige el pago aplicado: uno dado antes sin él no se convierte.
        $incompatibles = DB::table('fine_appeals AS r')
            ->whereIn('r.status', ['upheld', 'rejected'])
            ->whereNotExists(fn ($p) => $p->from('fine_payments AS p')
                ->whereColumn('p.fine_appeal_id', 'r.id')
                ->where('p.status', 'applied'))
            ->orderBy('r.fine_id')
            ->pluck('r.fine_id');

        if ($incompatibles->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay recursos resueltos sin su pago aplicado (multas %s): con la regla nueva el veredicto exige el 20 %% cobrado o verificado. '
                .'Revisalos y corré la migración de nuevo.',
                $incompatibles->unique()->implode(', '),
            ));
        }

        /*
         * Hasta qué movimiento llegaba la multa al darse el veredicto se
         * reconstruye por fecha, que está en segundos: uno del mismo segundo
         * que un «no prosperó» puede ser anterior o posterior, y adivinarlo
         * podría dejar revertir un veredicto que ya no se puede revertir.
         * Después de un «prosperó» la base ya no admitía movimientos.
         */
        $ambiguos = DB::table('fine_appeals AS r')
            ->join('fine_debt_entries AS e', 'e.fine_id', '=', 'r.fine_id')
            ->where('r.status', 'rejected')
            ->where('e.kind', '<>', 'constitution')
            ->whereColumn('e.created_at', 'r.resolved_at')
            ->orderBy('r.fine_id')
            ->pluck('r.fine_id');

        if ($ambiguos->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay recursos que no prosperaron con un movimiento de importe en el mismo segundo que el veredicto (multas %s): no se puede saber cuál fue primero. '
                .'Revisalos y corré la migración de nuevo.',
                $ambiguos->unique()->implode(', '),
            ));
        }

        Schema::create('fine_payment_voids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->string('reason_code', 40);
            $table->text('reason')->nullable();
            $table->foreignId('application_reversal_event_id')->unique()->constrained('financial_events')->restrictOnDelete();
            $table->foreignId('receipt_reversal_event_id')->unique()->constrained('financial_events')->restrictOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->useCurrent();
        });

        Schema::create('fine_appeal_verdicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_appeal_id')->constrained('fine_appeals')->restrictOnDelete();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->string('verdict', 10);
            $table->bigInteger('debt_entries_until_id');
            $table->timestampTz('resolved_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reverted_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revert_reason_code', 40)->nullable();
            $table->text('revert_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'fine_id']);
        });

        $anulacion = self::ANULACION;
        $reversionDeVeredicto = self::REVERSION_DE_VEREDICTO;

        DB::unprepared(<<<SQL
            ALTER TABLE fine_payment_voids
                ADD CONSTRAINT fine_payment_voids_payment_fk FOREIGN KEY (fine_payment_id, fine_id)
                    REFERENCES fine_payments (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_payment_voids_reason_check CHECK (
                    reason_code IN ({$anulacion})
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code <> 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_appeal_verdicts
                ADD CONSTRAINT fine_appeal_verdicts_appeal_fk FOREIGN KEY (fine_appeal_id, fine_id)
                    REFERENCES fine_appeals (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_appeal_verdicts_verdict_check CHECK (verdict IN ('upheld', 'rejected')),
                ADD CONSTRAINT fine_appeal_verdicts_revert_check CHECK (
                    (reverted_at IS NULL) = (revert_reason_code IS NULL)
                    AND (reverted_by IS NULL OR reverted_at IS NOT NULL)
                    AND (revert_reason IS NULL OR reverted_at IS NOT NULL)
                    AND (revert_reason_code IS NULL OR revert_reason_code IN ({$reversionDeVeredicto}))
                    AND (revert_reason IS NULL OR btrim(revert_reason) <> '')
                    AND (revert_reason_code IS DISTINCT FROM 'other' OR revert_reason IS NOT NULL)
                );
            CREATE UNIQUE INDEX fine_appeal_verdicts_one_current ON fine_appeal_verdicts (fine_appeal_id) WHERE reverted_at IS NULL;

            ALTER TABLE fine_debt_entries ADD COLUMN fine_appeal_verdict_id bigint;
        SQL);

        $this->convertirVeredictos();

        $ajuste = self::AJUSTE;
        $correccion = self::CORRECCION_DE_IMPORTE;
        $reversion = self::REVERSION;

        DB::unprepared(<<<SQL
            DROP INDEX fine_debt_entries_one_per_appeal;
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                DROP CONSTRAINT fine_debt_entries_appeal_fk,
                DROP COLUMN fine_appeal_id,
                ADD CONSTRAINT fine_debt_entries_verdict_fk FOREIGN KEY (fine_appeal_verdict_id, fine_id)
                    REFERENCES fine_appeal_verdicts (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal'
                        OR (fine_appeal_verdict_id IS NOT NULL AND fine_document_id IS NULL AND reason_code = 'appeal_verdict_reverted')
                        OR (fine_appeal_verdict_id IS NULL AND reason_code IN ({$reversion})))
                    AND (kind NOT IN ('increase', 'reduction')
                        OR (fine_appeal_verdict_id IS NOT NULL AND kind = 'reduction' AND fine_document_id IS NULL AND reason_code = 'appeal_admitted')
                        OR (fine_appeal_verdict_id IS NULL AND fine_document_id IS NOT NULL AND reason_code IN ({$ajuste}))
                        OR (fine_appeal_verdict_id IS NULL AND fine_document_id IS NULL AND reason_code IN ({$correccion}) AND reason IS NOT NULL))
                    AND (fine_appeal_verdict_id IS NULL OR kind IN ('reduction', 'reversal'))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
            CREATE UNIQUE INDEX fine_debt_entries_one_per_verdict ON fine_debt_entries (fine_appeal_verdict_id)
                WHERE fine_appeal_verdict_id IS NOT NULL AND kind = 'reduction';

            ALTER TABLE fine_appeals
                DROP CONSTRAINT fine_appeals_resolution_check,
                DROP COLUMN resolved_at,
                DROP COLUMN resolved_by;

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_status_check,
                ADD CONSTRAINT fine_payments_status_check CHECK (status IN ('reported', 'applied', 'discarded', 'voided'));

            DROP INDEX fine_payments_one_appeal_per_fine;
            CREATE UNIQUE INDEX fine_payments_one_appeal_per_fine ON fine_payments (fine_id)
                WHERE appeal_kind IS NOT NULL AND status NOT IN ('discarded', 'voided');
        SQL);

        $this->guardaDePagos(anular: true);

        DB::unprepared(self::GUARDAS);
        DB::unprepared(self::saldos(<<<'SQL'

                              AND NOT EXISTS (SELECT 1 FROM fine_payment_voids v WHERE v.fine_payment_id = a.fine_payment_id)
            SQL));
    }

    public function down(): void
    {
        if (DB::table('fine_payment_voids')->exists()
            || DB::table('fine_appeal_verdicts')->whereNotNull('reverted_at')->exists()) {
            throw new RuntimeException('Hay cobros anulados o veredictos revertidos: sin estas tablas, ese registro se perdería.');
        }

        DB::unprepared(self::saldos(''));
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_appeals
                ADD COLUMN resolved_at timestamptz,
                ADD COLUMN resolved_by bigint REFERENCES users(id) ON DELETE RESTRICT;
            ALTER TABLE fine_debt_entries ADD COLUMN fine_appeal_id bigint;
        SQL);

        DB::unprepared(self::QUITAR_GUARDAS);
        $this->guardaDePagos(anular: false);

        $ajuste = self::AJUSTE;
        $correccion = self::CORRECCION_DE_IMPORTE;
        $reversion = self::REVERSION;

        // El veredicto vuelve al recurso y la reducción a citarlo, con las guardas apagadas: lo hace el dueño, una vez.
        DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries DROP CONSTRAINT fine_debt_entries_reason_check;
            ALTER TABLE fine_debt_entries DISABLE TRIGGER fine_debt_entries_date_only;
            ALTER TABLE fine_appeals DISABLE TRIGGER fine_appeals_guard;

            UPDATE fine_appeals r
               SET resolved_at = v.resolved_at, resolved_by = v.resolved_by
              FROM fine_appeal_verdicts v
             WHERE v.fine_appeal_id = r.id;

            UPDATE fine_debt_entries e
               SET fine_appeal_id = v.fine_appeal_id
              FROM fine_appeal_verdicts v
             WHERE v.id = e.fine_appeal_verdict_id;

            SET CONSTRAINTS ALL IMMEDIATE;

            ALTER TABLE fine_appeals ENABLE TRIGGER fine_appeals_guard;
            ALTER TABLE fine_debt_entries ENABLE TRIGGER fine_debt_entries_date_only;

            ALTER TABLE fine_appeals
                ADD CONSTRAINT fine_appeals_resolution_check CHECK (
                    (status IN ('upheld', 'rejected')) = (resolved_at IS NOT NULL)
                    AND (resolved_by IS NULL OR resolved_at IS NOT NULL)
                );

            DROP INDEX fine_debt_entries_one_per_verdict;
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_verdict_fk,
                DROP COLUMN fine_appeal_verdict_id,
                ADD CONSTRAINT fine_debt_entries_appeal_fk FOREIGN KEY (fine_appeal_id, fine_id)
                    REFERENCES fine_appeals (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction')
                        OR (fine_appeal_id IS NOT NULL AND kind = 'reduction' AND fine_document_id IS NULL AND reason_code = 'appeal_admitted')
                        OR (fine_appeal_id IS NULL AND fine_document_id IS NOT NULL AND reason_code IN ({$ajuste}))
                        OR (fine_appeal_id IS NULL AND fine_document_id IS NULL AND reason_code IN ({$correccion}) AND reason IS NOT NULL))
                    AND (fine_appeal_id IS NULL OR kind = 'reduction')
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
            CREATE UNIQUE INDEX fine_debt_entries_one_per_appeal ON fine_debt_entries (fine_appeal_id) WHERE fine_appeal_id IS NOT NULL;

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_status_check,
                ADD CONSTRAINT fine_payments_status_check CHECK (status IN ('reported', 'applied', 'discarded'));

            DROP INDEX fine_payments_one_appeal_per_fine;
            CREATE UNIQUE INDEX fine_payments_one_appeal_per_fine ON fine_payments (fine_id)
                WHERE appeal_kind IS NOT NULL AND status <> 'discarded';
        SQL);

        Schema::drop('fine_appeal_verdicts');
        Schema::drop('fine_payment_voids');
    }

    /**
     * Cada recurso resuelto pasa a una fila de veredicto, y su reducción a
     * citarla. Un movimiento no se edita (`fine_debt_entries_date_only`): el
     * vínculo se escribe con la guarda apagada, una vez y dentro de esta
     * migración.
     */
    private function convertirVeredictos(): void
    {
        DB::unprepared(<<<'SQL'
            INSERT INTO fine_appeal_verdicts (fine_appeal_id, fine_id, verdict, debt_entries_until_id, resolved_at, resolved_by, created_at, updated_at)
            SELECT r.id, r.fine_id, r.status,
                   COALESCE((
                       SELECT max(e.id) FROM fine_debt_entries e
                        WHERE e.fine_id = r.fine_id
                          AND e.fine_appeal_id IS NULL
                          AND (r.status = 'upheld' OR e.kind = 'constitution' OR e.created_at < r.resolved_at)
                   ), 0),
                   r.resolved_at, r.resolved_by, r.resolved_at, r.resolved_at
              FROM fine_appeals r
             WHERE r.status IN ('upheld', 'rejected');

            ALTER TABLE fine_debt_entries DISABLE TRIGGER fine_debt_entries_date_only;

            UPDATE fine_debt_entries e
               SET fine_appeal_verdict_id = v.id
              FROM fine_appeal_verdicts v
             WHERE v.fine_appeal_id = e.fine_appeal_id;

            -- Con verificaciones diferidas pendientes, PostgreSQL no admite ALTER TABLE.
            SET CONSTRAINTS ALL IMMEDIATE;

            ALTER TABLE fine_debt_entries ENABLE TRIGGER fine_debt_entries_date_only;
        SQL);
    }

    /**
     * `fine_payments_guard`: la vigente de `2026_10_27_020000_add_reason_codes_to_fines.php`,
     * con la anulación como única salida de un pago aplicado.
     */
    private function guardaDePagos(bool $anular): void
    {
        $transicion = $anular
            ? "NOT ((OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))\n                        OR (OLD.status = 'applied' AND NEW.status = 'voided' AND NEW.medium IN ('cash', 'cheque')))"
            : "NOT (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))";
        $nace = $anular ? "NEW.status IN ('discarded', 'voided')" : "NEW.status = 'discarded'";
        $naceMensaje = $anular ? 'Un pago no nace descartado ni anulado.' : 'Un pago no nace descartado.';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS \$\$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF {$nace} THEN
                        RAISE EXCEPTION '{$naceMensaje}' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'applied' AND NEW.medium NOT IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Un pago por el banco se aplica al verificar el crédito: nace informado.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'reported' AND NEW.medium IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Lo que se cobra en la caja se aplica en el acto.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.appeal_kind IS NOT NULL THEN
                        PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                        IF NEW.appeal_base_amount <> fine_obligation(NEW.fine_id) THEN
                            RAISE EXCEPTION 'La base del 20 %% es el importe vigente de la multa al registrar el recurso: % y no %.',
                                fine_obligation(NEW.fine_id), NEW.appeal_base_amount
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    -- Una corrección reemplaza a un pago de la misma multa y del mismo tipo, ya descartado.
                    IF NEW.replaces_fine_payment_id IS NOT NULL THEN
                        SELECT fine_id, status, appeal_kind IS NOT NULL AS es_recurso, presented_at, presented_precision
                          INTO v_anterior
                          FROM fine_payments WHERE id = NEW.replaces_fine_payment_id;
                        IF v_anterior.fine_id <> NEW.fine_id OR v_anterior.status <> 'discarded'
                            OR v_anterior.es_recurso <> (NEW.appeal_kind IS NOT NULL) THEN
                            RAISE EXCEPTION 'Una corrección reemplaza a un pago descartado de la misma multa y del mismo tipo.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                        -- Corrige los datos de un informado: nace informada y conserva su
                        -- presentación, que es lo que decide el término.
                        IF NEW.status <> 'reported'
                            OR NEW.presented_at IS DISTINCT FROM v_anterior.presented_at
                            OR NEW.presented_precision IS DISTINCT FROM v_anterior.presented_precision THEN
                            RAISE EXCEPTION 'Una corrección nace informada y conserva la presentación del pago que reemplaza.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND {$transicion} THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason, NEW.discard_reason_code)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason, OLD.discard_reason_code) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }
};
