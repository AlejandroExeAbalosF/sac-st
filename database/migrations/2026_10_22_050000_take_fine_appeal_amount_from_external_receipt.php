<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El recurso del 20 %, como lo definió el usuario el 21/10/2026.
 *
 * - **Se cobra lo que dice el recibo externo.** La liquidación del 20 % la
 *   hace otra oficina: el importe del pago es el de su recibo, y el 20 %
 *   calculado sobre el importe vigente queda al lado, como referencia. Una
 *   diferencia no frena el cobro. Reemplaza la regla anterior de M07
 *   («exactamente el 20 %», revisión del administrador si difiere).
 * - **Una multa admite un solo recurso.** Un segundo se rechaza; un intento
 *   descartado no cuenta. Cierra P05.
 *
 * Sin diferencia que revisar ni recurso repetido, ya nada queda en revisión:
 * se retiran el estado `under_review`, la resolución y sus columnas, y la
 * marca de recurso repetido.
 *
 * Y un pago informado que se cargó mal se **corrige**: se descarta con motivo
 * y el corregido apunta al que reemplaza (`replaces_fine_payment_id`).
 */
return new class extends Migration
{
    private const NO_DELEGABLES = [
        'roles.gestionar',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.desactivar',
        'usuarios.restablecer-password',
    ];

    public function up(): void
    {
        if (DB::table('fine_payments')->where('status', 'under_review')->exists()) {
            throw new RuntimeException('Hay recursos en revisión. Resolvelos o descartalos antes de migrar.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_review_check,
                DROP CONSTRAINT fine_payments_appeal_repeated_check,
                DROP CONSTRAINT fine_payments_appeal_check,
                DROP CONSTRAINT fine_payments_status_check;
            ALTER TABLE fine_payments
                DROP COLUMN appeal_repeated,
                DROP COLUMN external_receipt_amount,
                DROP COLUMN reviewed_by,
                DROP COLUMN reviewed_at,
                DROP COLUMN review_reason,
                ADD COLUMN replaces_fine_payment_id bigint UNIQUE REFERENCES fine_payments(id) ON DELETE RESTRICT;

            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_status_check CHECK (status IN ('reported', 'applied', 'discarded')),
                ADD CONSTRAINT fine_payments_appeal_check CHECK (
                    num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount, external_receipt_reference) IN (0, 4)
                    AND (appeal_kind IS NULL OR (
                        appeal_kind IN ('hierarchical', 'appeal')
                        AND medium IN ('cash', 'direct_deposit')
                        AND NOT judicial_origin
                        AND btrim(external_receipt_reference) <> ''
                        -- El 20 % calculado, pesos enteros a favor del deudor: la referencia.
                        AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                    ))
                );

            -- Una multa, un recurso vigente (U, 21/10/2026).
            CREATE UNIQUE INDEX fine_payments_one_appeal_per_fine ON fine_payments (fine_id)
                WHERE appeal_kind IS NOT NULL AND status <> 'discarded';

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
        SQL);

        // Sin revisión del recurso, su permiso sale del catálogo y de la guarda.
        $this->guardarNoDelegables(self::NO_DELEGABLES);
    }

    public function down(): void
    {
        $this->guardarNoDelegables([...self::NO_DELEGABLES, 'multas.resolver-recurso']);

        DB::unprepared(<<<'SQL'
            DROP INDEX fine_payments_one_appeal_per_fine;
            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_appeal_check,
                DROP CONSTRAINT fine_payments_status_check;
            ALTER TABLE fine_payments
                DROP COLUMN replaces_fine_payment_id,
                ADD COLUMN appeal_repeated boolean NOT NULL DEFAULT false,
                ADD COLUMN external_receipt_amount numeric(19,2),
                ADD COLUMN reviewed_by bigint REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN reviewed_at timestamptz,
                ADD COLUMN review_reason varchar(500);
            UPDATE fine_payments SET external_receipt_amount = amount WHERE appeal_kind IS NOT NULL;
            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_status_check
                    CHECK (status IN ('reported', 'applied', 'discarded', 'under_review')),
                ADD CONSTRAINT fine_payments_appeal_check CHECK (
                    num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount,
                        external_receipt_reference, external_receipt_amount) IN (0, 5)
                    AND (appeal_kind IS NULL OR (
                        appeal_kind IN ('hierarchical', 'appeal')
                        AND medium IN ('cash', 'direct_deposit')
                        AND NOT judicial_origin
                        AND btrim(external_receipt_reference) <> ''
                        AND external_receipt_amount > 0
                        AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                        AND amount = appeal_required_amount
                    ))
                ) NOT VALID,
                ADD CONSTRAINT fine_payments_appeal_repeated_check CHECK (NOT appeal_repeated OR appeal_kind IS NOT NULL),
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    AND (status <> 'under_review'
                         OR (appeal_kind IS NOT NULL AND (external_receipt_amount <> amount OR appeal_repeated)))
                    AND (appeal_kind IS NULL OR (external_receipt_amount = amount AND NOT appeal_repeated)
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                ) NOT VALID;

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

    /**
     * La guarda de 2026_10_12_010000 con la lista de permisos no delegables.
     *
     * @param  list<string>  $reservados
     */
    private function guardarNoDelegables(array $reservados): void
    {
        $lista = implode(', ', array_map(fn (string $v): string => "'{$v}'", $reservados));

        DB::statement(str_replace('{reservados}', $lista, <<<'SQL'
            CREATE OR REPLACE FUNCTION role_has_permissions_guard_non_delegable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name IN ({reservados}))
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name IN ('administrador', 'super-admin')) THEN
                    RAISE EXCEPTION 'Este permiso no se delega: lo tiene solo el administrador.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL));
    }
};
