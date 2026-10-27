<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El motivo de Multas se elige de una lista y se guarda como código.
 *
 * Cada acto que deshace o corrige algo ya cargado guardaba su motivo como
 * texto libre, que no se puede contar. Ahora cada columna de motivo tiene al
 * lado su código, y el texto queda como detalle:
 *
 * - el código es el de la lista de ese acto (`FineReasonAct`), y está siempre
 *   que el acto pide motivo;
 * - el detalle es opcional, salvo con «Otro»;
 * - un detalle, si está, no es solo espacios.
 *
 * Las filas que ya tenían motivo quedan en «Otro», con su texto como detalle;
 * los pagos descartados por una corrección, en «Reemplazado por su
 * corrección». Las guardas que comparan una fila entera suman la columna
 * nueva entre las que el acto puede escribir.
 *
 * El rechazo de un cheque y la cancelación de un depósito guardan su motivo
 * en tablas que también usa Haberes: van con los otros módulos.
 */
return new class extends Migration
{
    private const AJUSTE = "'appeal_admitted', 'court_ruling', 'resolution_correction', 'other'";

    private const REVERSION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

    private const DESCARTE = "'data_entry_error', 'duplicate', 'wrong_fine', 'not_credited', 'replaced_by_correction', 'other'";

    private const DESVINCULO = "'data_entry_error', 'wrong_document', 'wrong_fine', 'duplicate', 'other'";

    private const CORRECCION = "'data_entry_error', 'misread_paper', 'other'";

    private const RECHAZO = "'no_evidence', 'duplicate', 'wrong_fine', 'data_entry_error', 'other'";

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_debt_entries ADD COLUMN reason_code varchar(30);
            ALTER TABLE fine_payments ADD COLUMN discard_reason_code varchar(30);
            ALTER TABLE fine_document_links ADD COLUMN revocation_reason_code varchar(30);
            ALTER TABLE fine_identifier_revisions ADD COLUMN reason_code varchar(30);
            ALTER TABLE fine_historical_movements ADD COLUMN review_reason_code varchar(30);
        SQL);

        $this->completarLoCargado();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status = 'discarded' THEN
                        RAISE EXCEPTION 'Un pago no nace descartado.' USING ERRCODE = 'check_violation';
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
                    AND NOT (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded')) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason, NEW.discard_reason_code)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason, OLD.discard_reason_code) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_historical_review_once() RETURNS trigger AS $$
            BEGIN
                -- Completar o corregir la fecha desde una revision, en cualquier
                -- estado: no cambia lo que el antecedente aporta al saldo.
                IF pg_trigger_depth() > 1
                   AND (to_jsonb(NEW) - 'occurred_on' - 'occurred_precision')
                       IS NOT DISTINCT FROM (to_jsonb(OLD) - 'occurred_on' - 'occurred_precision') THEN
                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending_review' THEN
                    RAISE EXCEPTION 'El antecedente ya se reviso: queda como esta.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note' - 'review_reason_code')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note' - 'review_reason_code') THEN
                    RAISE EXCEPTION 'Revisar un antecedente no modifica sus datos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_document_links_revoke_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'El vinculo % ya esta anulado.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.revoked_at IS NULL
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code') THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se edita: solo se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Ordena la anulacion con un ajuste simultaneo de la misma multa.
                PERFORM 1 FROM fines WHERE id = OLD.fine_id FOR UPDATE;

                IF NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = OLD.fine_id
                       AND fine_document_id = OLD.fine_document_id
                       AND revoked_at IS NULL
                       AND id <> OLD.id
                ) AND EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = OLD.fine_id
                       AND e.fine_document_id = OLD.fine_document_id
                       AND e.kind <> 'reversal'
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                ) THEN
                    RAISE EXCEPTION 'El documento % respalda un movimiento vigente de la obligacion de la multa %: no se desvincula.', OLD.fine_document_id, OLD.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        $ajuste = self::AJUSTE;
        $reversion = self::REVERSION;
        $descarte = self::DESCARTE;
        $desvinculo = self::DESVINCULO;
        $correccion = self::CORRECCION;
        $rechazo = self::RECHAZO;

        DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction') OR reason_code IN ({$ajuste}))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_discard_check,
                ADD CONSTRAINT fine_payments_discard_check CHECK (
                    num_nonnulls(discarded_by, discarded_at) IN (0, 2)
                    AND (status = 'discarded') = (discarded_at IS NOT NULL)
                    AND (status = 'discarded') = (discard_reason_code IS NOT NULL)
                    AND (discard_reason_code IS NULL OR discard_reason_code IN ({$descarte}))
                    AND (discard_reason IS NULL OR btrim(discard_reason) <> '')
                    AND (discard_reason IS NULL OR discard_reason_code IS NOT NULL)
                    AND (discard_reason_code NOT IN ('other', 'replaced_by_correction') OR discard_reason IS NOT NULL)
                );

            ALTER TABLE fine_document_links
                DROP CONSTRAINT fine_document_links_revocation_check,
                ADD CONSTRAINT fine_document_links_revocation_check CHECK (
                    (revoked_at IS NULL) = (revocation_reason_code IS NULL)
                    AND (revoked_at IS NOT NULL OR (revoked_by IS NULL AND revocation_reason IS NULL))
                    AND (revocation_reason_code IS NULL OR revocation_reason_code IN ({$desvinculo}))
                    AND (revocation_reason IS NULL OR btrim(revocation_reason) <> '')
                    AND (revocation_reason_code IS DISTINCT FROM 'other' OR revocation_reason IS NOT NULL)
                );

            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check CHECK (
                    (kind = 'completion') = (reason_code IS NULL)
                    AND (reason_code IS NULL OR reason_code IN ({$correccion}))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason IS NULL OR reason_code IS NOT NULL)
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_historical_movements
                DROP CONSTRAINT fine_historical_movements_rejected_note_check,
                ADD CONSTRAINT fine_historical_movements_rejected_note_check CHECK (
                    (status = 'rejected') = (review_reason_code IS NOT NULL)
                    AND (review_reason_code IS NULL OR review_reason_code IN ({$rechazo}))
                    AND (review_note IS NULL OR btrim(review_note) <> '')
                    AND (review_reason_code IS DISTINCT FROM 'other' OR review_note IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check
                    CHECK (kind = 'constitution' OR (reason IS NOT NULL AND btrim(reason) <> '')) NOT VALID;

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_discard_check,
                ADD CONSTRAINT fine_payments_discard_check CHECK (
                    num_nonnulls(discarded_by, discarded_at, discard_reason) IN (0, 3)
                    AND (status = 'discarded') = (discard_reason IS NOT NULL)
                    AND (discard_reason IS NULL OR btrim(discard_reason) <> '')
                ) NOT VALID;

            ALTER TABLE fine_document_links
                DROP CONSTRAINT fine_document_links_revocation_check,
                ADD CONSTRAINT fine_document_links_revocation_check CHECK (
                    (revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL)
                    OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL AND btrim(revocation_reason) <> '')
                ) NOT VALID;

            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check
                    CHECK (kind = 'completion' OR (reason IS NOT NULL AND btrim(reason) <> '')) NOT VALID;

            ALTER TABLE fine_historical_movements
                DROP CONSTRAINT fine_historical_movements_rejected_note_check,
                ADD CONSTRAINT fine_historical_movements_rejected_note_check
                    CHECK (status <> 'rejected' OR (review_note IS NOT NULL AND btrim(review_note) <> '')) NOT VALID;

            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status = 'discarded' THEN
                        RAISE EXCEPTION 'Un pago no nace descartado.' USING ERRCODE = 'check_violation';
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

                IF (to_jsonb(NEW) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded')) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_historical_review_once() RETURNS trigger AS $$
            BEGIN
                -- Completar o corregir la fecha desde una revision, en cualquier
                -- estado: no cambia lo que el antecedente aporta al saldo.
                IF pg_trigger_depth() > 1
                   AND (to_jsonb(NEW) - 'occurred_on' - 'occurred_precision')
                       IS NOT DISTINCT FROM (to_jsonb(OLD) - 'occurred_on' - 'occurred_precision') THEN
                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending_review' THEN
                    RAISE EXCEPTION 'El antecedente ya se reviso: queda como esta.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note') THEN
                    RAISE EXCEPTION 'Revisar un antecedente no modifica sus datos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_document_links_revoke_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'El vinculo % ya esta anulado.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.revoked_at IS NULL
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason') THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se edita: solo se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Ordena la anulacion con un ajuste simultaneo de la misma multa.
                PERFORM 1 FROM fines WHERE id = OLD.fine_id FOR UPDATE;

                IF NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = OLD.fine_id
                       AND fine_document_id = OLD.fine_document_id
                       AND revoked_at IS NULL
                       AND id <> OLD.id
                ) AND EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = OLD.fine_id
                       AND e.fine_document_id = OLD.fine_document_id
                       AND e.kind <> 'reversal'
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                ) THEN
                    RAISE EXCEPTION 'El documento % respalda un movimiento vigente de la obligacion de la multa %: no se desvincula.', OLD.fine_document_id, OLD.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE fine_debt_entries DROP COLUMN reason_code;
            ALTER TABLE fine_payments DROP COLUMN discard_reason_code;
            ALTER TABLE fine_document_links DROP COLUMN revocation_reason_code;
            ALTER TABLE fine_identifier_revisions DROP COLUMN reason_code;
            ALTER TABLE fine_historical_movements DROP COLUMN review_reason_code;
        SQL);
    }

    /**
     * Lo que ya tenía motivo queda en «Otro», con su texto como detalle.
     *
     * Son tablas que no admiten cambios —justamente por eso—: el relleno corre
     * con sus guardas apagadas, dentro de la migración y como dueño de la
     * tabla, y no toca ninguna otra columna.
     */
    private function completarLoCargado(): void
    {
        $tablas = ['fine_debt_entries', 'fine_payments', 'fine_document_links', 'fine_identifier_revisions', 'fine_historical_movements'];

        foreach ($tablas as $tabla) {
            DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
        }

        DB::unprepared(<<<'SQL'
            UPDATE fine_debt_entries SET reason_code = 'other' WHERE kind <> 'constitution';

            UPDATE fine_payments
               SET discard_reason_code = 'replaced_by_correction',
                   discard_reason = COALESCE(NULLIF(btrim(substr(discard_reason, length('Corregido:') + 1)), ''), discard_reason)
             WHERE status = 'discarded' AND discard_reason LIKE 'Corregido:%';
            UPDATE fine_payments SET discard_reason_code = 'other'
             WHERE status = 'discarded' AND discard_reason_code IS NULL;

            UPDATE fine_document_links SET revocation_reason_code = 'other' WHERE revoked_at IS NOT NULL;
            UPDATE fine_identifier_revisions SET reason_code = 'other' WHERE kind = 'correction';
            UPDATE fine_historical_movements SET review_reason_code = 'other' WHERE status = 'rejected';
        SQL);

        foreach ($tablas as $tabla) {
            DB::statement("ALTER TABLE {$tabla} ENABLE TRIGGER USER");
        }
    }
};
