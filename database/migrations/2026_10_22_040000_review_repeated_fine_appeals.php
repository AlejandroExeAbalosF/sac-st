<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un recurso repetido va a revisión — P05 provisorio (DER físico de Multas §10).
 *
 * Hasta que el área defina si se admiten recursos repetidos sobre la misma
 * multa, el segundo no se cobra solo aunque su recibo externo coincida: queda
 * `under_review` y lo resuelve el administrador, como una diferencia de
 * importe. Un intento descartado no cuenta como recurso anterior.
 *
 * `appeal_repeated` lo dice la fila y lo comprueba la base al insertar, con la
 * multa bloqueada: no depende de que el Action haya mirado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments ADD COLUMN appeal_repeated boolean NOT NULL DEFAULT false;

            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_appeal_repeated_check CHECK (NOT appeal_repeated OR appeal_kind IS NOT NULL),
                DROP CONSTRAINT fine_payments_review_check,
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    -- En revisión queda un recurso con diferencia o repetido, y no sale sin resolver.
                    AND (status <> 'under_review'
                         OR (appeal_kind IS NOT NULL AND (external_receipt_amount <> amount OR appeal_repeated)))
                    AND (appeal_kind IS NULL OR (external_receipt_amount = amount AND NOT appeal_repeated)
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                );

            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
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
                        -- P05 provisorio: un recurso sobre una multa que ya tiene otro
                        -- vigente va a revisión. Un intento descartado no cuenta.
                        IF NEW.appeal_repeated IS DISTINCT FROM EXISTS (
                            SELECT 1 FROM fine_payments
                             WHERE fine_id = NEW.fine_id AND appeal_kind IS NOT NULL AND status <> 'discarded'
                        ) THEN
                            RAISE EXCEPTION 'Un recurso es repetido si la multa ya tiene otro que no se descartó, y entonces va a revisión.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        if (DB::table('fine_payments')->where('appeal_repeated', true)->exists()) {
            throw new RuntimeException('Hay recursos marcados como repetidos. No se puede retirar esta migración sin perder esa marca.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
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
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_review_check,
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    AND (status <> 'under_review' OR (appeal_kind IS NOT NULL AND external_receipt_amount <> amount))
                    AND (appeal_kind IS NULL OR external_receipt_amount = amount
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                ),
                DROP CONSTRAINT fine_payments_appeal_repeated_check;
            ALTER TABLE fine_payments DROP COLUMN appeal_repeated;
        SQL);
    }
};
