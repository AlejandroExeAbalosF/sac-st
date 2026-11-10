<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El excedente de una cuota: lo que entró de más, guardado en la cuota donde entró.
 *
 * `2026_11_08_010000_allow_payable_installment_excess` permitió que las cuotas
 * sumaran más que lo reconocido, pero el excedente quedó implícito en el haber:
 * nadie sabía en qué cuota estaba. Ahora cada cuota dice cuánto de su importe es
 * excedente, y por qué.
 *
 * - `expected_amount` sigue siendo el total que la cuota recibe y se paga: la
 *   financiación, su tope, el recibo y la Orden no cambian. Lo que se debía es
 *   `expected_amount − excess_amount`.
 * - La suma de lo que se debía vuelve a tener tope en lo reconocido, como antes
 *   del 08/11; el excedente va aparte.
 * - El recibo de ingreso congela lo que se debía al emitir
 *   (`receipts.amount_due_snapshot`), solo cuando difiere de lo recibido. Entra
 *   en las columnas que un comprobante no deja editar.
 *
 * Lo ya cargado: un haber cuyas cuotas superan lo reconocido tiene su excedente
 * en algún lado. Solo un plan de cuota única lo dice sin dudas: es esa. Con
 * varias cuotas cargadas, o con una sola pero más previstas —atribuirle todo
 * dejaría a las que faltan sin saldo—, no hay cómo saberlo, y la migración se
 * detiene con la lista antes de tocar nada.
 */
return new class extends Migration
{
    private const MOTIVOS = "'employer_overpaid', 'employer_settlement_error', 'other'";

    public function up(): void
    {
        $ambiguos = DB::table('haberes as h')
            ->join('beneficiary_installments as i', 'i.haber_id', '=', 'h.id')
            ->where('i.workflow_status', '<>', 'cancelled')
            ->groupBy('h.id', 'h.assigned_amount', 'h.expected_installment_count')
            ->havingRaw('SUM(i.expected_amount) > h.assigned_amount AND (COUNT(i.id) > 1 OR COALESCE(h.expected_installment_count, 1) > 1)')
            ->orderBy('h.id')
            ->pluck('h.id');

        if ($ambiguos->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Estos haberes tienen cuotas que superan lo reconocido y su plan no es de cuota única: no se sabe en cuál está el excedente. Haberes: %s. Corregilos antes de migrar.',
                $ambiguos->implode(', '),
            ));
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE beneficiary_installments
                ADD COLUMN excess_amount numeric(19,2) NOT NULL DEFAULT 0,
                ADD COLUMN excess_reason_code varchar(30),
                ADD COLUMN excess_reason varchar(300);

            UPDATE beneficiary_installments i
               SET excess_amount = i.expected_amount - h.assigned_amount,
                   excess_reason_code = 'other',
                   excess_reason = 'Cargado como excedente del haber, antes de registrarlo por cuota.'
              FROM haberes h
             WHERE h.id = i.haber_id
               AND i.workflow_status <> 'cancelled'
               AND i.expected_amount > h.assigned_amount;
        SQL);

        DB::unprepared(sprintf(<<<'SQL'
            ALTER TABLE beneficiary_installments
                ADD CONSTRAINT beneficiary_installments_excess_check CHECK (
                    excess_amount >= 0 AND excess_amount < expected_amount
                ),
                ADD CONSTRAINT beneficiary_installments_excess_reason_check CHECK (
                    (excess_amount = 0) = (excess_reason_code IS NULL)
                    AND (excess_reason_code IS NULL OR excess_reason_code IN (%s))
                    AND (excess_reason IS NULL OR excess_reason_code IS NOT NULL)
                    AND (excess_reason IS NULL OR btrim(excess_reason) <> '')
                    AND (excess_reason_code IS DISTINCT FROM 'other' OR excess_reason IS NOT NULL)
                );
        SQL, self::MOTIVOS));

        // El tope de lo que se debía, en los dos lados: la cuota y el haber.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_installments_sum() RETURNS trigger AS $$
            DECLARE
                objetivo bigint;
                suma numeric(19,2);
                derecho numeric(19,2);
            BEGIN
                objetivo := COALESCE(NEW.haber_id, OLD.haber_id);

                SELECT assigned_amount INTO derecho FROM haberes WHERE id = objetivo;

                IF derecho IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(expected_amount - excess_amount), 0) INTO suma
                  FROM beneficiary_installments
                 WHERE haber_id = objetivo
                   AND workflow_status <> 'cancelled';

                IF suma > derecho THEN
                    RAISE EXCEPTION
                        'Lo que se debe de las cuotas (%) supera el importe reconocido del haber (%).', suma, derecho
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION check_haber_amount_against_installments() RETURNS trigger AS $$
            DECLARE
                suma numeric(19,2);
            BEGIN
                SELECT COALESCE(SUM(expected_amount - excess_amount), 0) INTO suma
                  FROM beneficiary_installments
                 WHERE haber_id = NEW.id
                   AND workflow_status <> 'cancelled';

                IF suma > NEW.assigned_amount THEN
                    RAISE EXCEPTION
                        'Lo que se debe de las cuotas (%) supera el importe reconocido del haber (%).', suma, NEW.assigned_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER haber_assigned_amount_sum
                AFTER UPDATE OF assigned_amount ON haberes
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION check_haber_amount_against_installments();

            CREATE CONSTRAINT TRIGGER beneficiary_installments_sum
                AFTER INSERT OR UPDATE OR DELETE ON beneficiary_installments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION check_installments_sum();
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE receipts
                ADD COLUMN amount_due_snapshot numeric(19,2),
                ADD CONSTRAINT receipts_amount_due_snapshot_check CHECK (
                    amount_due_snapshot IS NULL OR (amount_due_snapshot > 0 AND amount_due_snapshot < amount)
                );
        SQL);

        $this->protegerRecibos(conImporteAAbonar: true);
    }

    public function down(): void
    {
        $this->protegerRecibos(conImporteAAbonar: false);

        DB::unprepared(<<<'SQL'
            ALTER TABLE receipts DROP COLUMN amount_due_snapshot;

            DROP TRIGGER beneficiary_installments_sum ON beneficiary_installments;
            DROP TRIGGER haber_assigned_amount_sum ON haberes;

            CREATE OR REPLACE FUNCTION check_installments_sum() RETURNS trigger AS $$
            DECLARE
                objetivo bigint;
                suma numeric(19,2);
                derecho numeric(19,2);
            BEGIN
                objetivo := COALESCE(NEW.haber_id, OLD.haber_id);

                SELECT assigned_amount INTO derecho FROM haberes WHERE id = objetivo;

                IF derecho IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(expected_amount), 0) INTO suma
                  FROM beneficiary_installments
                 WHERE haber_id = objetivo
                   AND workflow_status <> 'cancelled';

                IF suma > derecho THEN
                    RAISE EXCEPTION
                        'La suma de las cuotas (%) supera el importe del haber (%).', suma, derecho
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION check_haber_amount_against_installments() RETURNS trigger AS $$
            DECLARE
                suma numeric(19,2);
            BEGIN
                SELECT COALESCE(SUM(expected_amount), 0) INTO suma
                  FROM beneficiary_installments
                 WHERE haber_id = NEW.id
                   AND workflow_status <> 'cancelled';

                IF suma > NEW.assigned_amount THEN
                    RAISE EXCEPTION
                        'La suma de las cuotas (%) supera el importe del haber (%).', suma, NEW.assigned_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE beneficiary_installments
                DROP CONSTRAINT beneficiary_installments_excess_reason_check,
                DROP CONSTRAINT beneficiary_installments_excess_check,
                DROP COLUMN excess_reason,
                DROP COLUMN excess_reason_code,
                DROP COLUMN excess_amount;
        SQL);
    }

    /** Las columnas de un comprobante que no se editan: se anula y se emite otro. */
    private function protegerRecibos(bool $conImporteAAbonar): void
    {
        $importeAAbonar = $conImporteAAbonar
            ? 'OR NEW.amount_due_snapshot IS DISTINCT FROM OLD.amount_due_snapshot'
            : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION receipts_append_only() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un comprobante no se borra: se anula y se emite el reemplazante.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.talonario_number IS DISTINCT FROM OLD.talonario_number
                    OR NEW.receipt_type IS DISTINCT FROM OLD.receipt_type
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.counterparty_name_snapshot IS DISTINCT FROM OLD.counterparty_name_snapshot
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
                    OR NEW.medium_snapshot IS DISTINCT FROM OLD.medium_snapshot
                    {$importeAAbonar}
                THEN
                    RAISE EXCEPTION 'Los datos de un comprobante no se editan: se anula y se emite otro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }
};
