<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El estado de la cuota y su egreso dicen lo mismo.
 *
 * **Pagada: con su egreso confirmado.** Es lo que `InstallmentWorkflowStatus::Paid`
 * significa, y hasta acá solo lo cumplían los Actions: por SQL una cuota podía
 * figurar pagada sin que saliera un peso. Un egreso confirmado no cambia de
 * estado ni se borra (`disbursements_append_only`), así que alcanza con mirarlo
 * al entrar a `paid`. El pago fuera del circuito es otro estado, con su regla.
 *
 * **Anulada: sin egreso vivo**, en ninguno de los dos órdenes.
 *
 * `CancelHaber` y `CancelExpediente` ya lo rechazaban, pero la base solo
 * frenaba anular con el recibo de ingreso vigente: dado de baja el recibo por
 * fuera del Action, una cuota pagada se podía anular.
 *
 * Los dos triggers se encuentran en la fila de la cuota: el `UPDATE` la
 * bloquea, y el alta del egreso la lee con `FOR SHARE`, que choca con él. La
 * FK solo toma `FOR KEY SHARE`, que no choca.
 *
 * **Los datos se revisan con las dos tablas bloqueadas para escritura**, hasta
 * que las guardas están puestas: sin eso, una escritura en curso no se veía al
 * revisar y quedaba confirmada antes del trigger. El egreso va primero, que es
 * el orden en que los Actions escriben. También se revisan las anuladas que la
 * auditoría recuerda pagadas sin egreso: reactivarlas restauraría `paid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE disbursements, beneficiary_installments IN SHARE ROW EXCLUSIVE MODE;

            DO $$
            DECLARE
                cruzadas integer;
                sin_egreso integer;
                sin_vuelta integer;
            BEGIN
                SELECT count(*) INTO sin_egreso
                  FROM beneficiary_installments i
                 WHERE i.workflow_status = 'paid'
                   AND NOT EXISTS (
                       SELECT 1 FROM disbursements d
                        WHERE d.beneficiary_installment_id = i.id AND d.status = 'confirmed'
                   );

                IF sin_egreso > 0 THEN
                    RAISE EXCEPTION 'Hay % cuotas pagadas sin egreso confirmado: hay que resolverlas antes de migrar.', sin_egreso;
                END IF;

                SELECT count(*) INTO cruzadas
                  FROM beneficiary_installments i
                  JOIN disbursements d ON d.beneficiary_installment_id = i.id
                 WHERE i.workflow_status = 'cancelled'
                   AND d.status NOT IN ('reversed', 'failed');

                IF cruzadas > 0 THEN
                    RAISE EXCEPTION 'Hay % cuotas anuladas con un egreso vivo: hay que resolverlas antes de migrar.', cruzadas;
                END IF;

                -- La anulación vigente de cada haber y expediente, que es la que
                -- leen `ReactivateHaber` y `ReactivateExpediente`.
                WITH ultimas AS (
                    SELECT DISTINCT ON (subject_type, subject_id) action, metadata
                      FROM audit_events
                     WHERE (subject_type, action) IN (
                               ('Haber', 'haber.anulado'), ('Haber', 'haber.reactivado'),
                               ('Expediente', 'expediente.anulado'), ('Expediente', 'expediente.reactivado')
                           )
                     ORDER BY subject_type, subject_id, occurred_at DESC, id DESC
                )
                SELECT count(DISTINCT i.id) INTO sin_vuelta
                  FROM ultimas u
                 CROSS JOIN LATERAL jsonb_array_elements(
                           CASE WHEN jsonb_typeof(u.metadata -> 'cuotas') = 'array' THEN u.metadata -> 'cuotas' ELSE '[]'::jsonb END
                       ) AS previa
                  JOIN beneficiary_installments i ON i.id = (previa ->> 'id')::bigint
                 WHERE u.action IN ('haber.anulado', 'expediente.anulado')
                   AND previa ->> 'workflow_status' = 'paid'
                   AND i.workflow_status = 'cancelled';

                -- Con egreso vivo ya la frenó el control anterior.
                IF sin_vuelta > 0 THEN
                    RAISE EXCEPTION 'Hay % cuotas anuladas que estaban pagadas sin egreso confirmado: no se podrían reactivar. Hay que resolverlas antes de migrar.', sin_vuelta;
                END IF;
            END;
            $$;

            CREATE OR REPLACE FUNCTION beneficiary_installments_paid_with_disbursement() RETURNS trigger AS $$
            BEGIN
                IF NEW.workflow_status = 'paid' AND NOT EXISTS (
                    SELECT 1 FROM disbursements
                     WHERE beneficiary_installment_id = NEW.id
                       AND status = 'confirmed'
                ) THEN
                    RAISE EXCEPTION 'La cuota % no tiene un egreso confirmado: no puede figurar pagada.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER beneficiary_installments_paid_with_disbursement
                BEFORE INSERT OR UPDATE OF workflow_status ON beneficiary_installments
                FOR EACH ROW EXECUTE FUNCTION beneficiary_installments_paid_with_disbursement();

            CREATE OR REPLACE FUNCTION beneficiary_installments_cancel_without_disbursement() RETURNS trigger AS $$
            BEGIN
                IF NEW.workflow_status = 'cancelled' AND OLD.workflow_status <> 'cancelled' AND EXISTS (
                    SELECT 1 FROM disbursements
                     WHERE beneficiary_installment_id = NEW.id
                       AND status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La cuota % tiene un egreso registrado: no se puede anular.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER beneficiary_installments_cancel_without_disbursement
                BEFORE UPDATE OF workflow_status ON beneficiary_installments
                FOR EACH ROW EXECUTE FUNCTION beneficiary_installments_cancel_without_disbursement();

            CREATE OR REPLACE FUNCTION disbursements_not_on_cancelled() RETURNS trigger AS $$
            DECLARE
                estado_cuota text;
            BEGIN
                SELECT workflow_status INTO estado_cuota
                  FROM beneficiary_installments
                 WHERE id = NEW.beneficiary_installment_id
                   FOR SHARE;

                IF estado_cuota = 'cancelled' THEN
                    RAISE EXCEPTION 'La cuota % está anulada: no admite un egreso.', NEW.beneficiary_installment_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER disbursements_not_on_cancelled
                BEFORE INSERT ON disbursements
                FOR EACH ROW EXECUTE FUNCTION disbursements_not_on_cancelled();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS disbursements_not_on_cancelled ON disbursements;
            DROP FUNCTION IF EXISTS disbursements_not_on_cancelled();
            DROP TRIGGER IF EXISTS beneficiary_installments_cancel_without_disbursement ON beneficiary_installments;
            DROP FUNCTION IF EXISTS beneficiary_installments_cancel_without_disbursement();
            DROP TRIGGER IF EXISTS beneficiary_installments_paid_with_disbursement ON beneficiary_installments;
            DROP FUNCTION IF EXISTS beneficiary_installments_paid_with_disbursement();
        SQL);
    }
};
