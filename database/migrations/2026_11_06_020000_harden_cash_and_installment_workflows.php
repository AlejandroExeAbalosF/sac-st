<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
             * Dos afirmaciones incompatibles que la base no frenaba.
             *
             * Liberar la plata de una cuota con egreso vivo dejaba al beneficiario en
             * negativo y al pozo ofreciendo dinero que ya salió. Anular una cuota con su
             * recibo de ingreso vigente dejaba un comprobante firme sobre algo dado de
             * baja. Los Actions lo explican; esto lo impide también por fuera de ellos.
             */
            // Migración original: 2026_11_06_020000_guard_delivered_and_receipted_installments.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION funding_allocations_reversal_without_disbursement() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.allocation_kind = 'reversal' AND EXISTS (
                    SELECT 1 FROM disbursements
                    WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                      AND status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un egreso registrado: sus fondos no se pueden liberar.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

                    DB::statement('CREATE TRIGGER funding_allocations_reversal_without_disbursement
            BEFORE INSERT ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION funding_allocations_reversal_without_disbursement()');

                    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION beneficiary_installments_cancel_without_receipt() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.workflow_status = 'cancelled'
                   -- La saldada afuera tiene su propia guarda, que pide anular el registro.
                   AND OLD.workflow_status NOT IN ('cancelled', 'legacy_settled')
                   AND (
                       EXISTS (
                           SELECT 1 FROM receipts
                           WHERE beneficiary_installment_id = NEW.id
                             AND receipt_type = 'income'
                             AND status = 'issued'
                       )
                       OR EXISTS (
                           SELECT 1 FROM legacy_documents
                           WHERE beneficiary_installment_id = NEW.id
                             AND kind = 'income_receipt'
                             AND voided_at IS NULL
                       )
                   ) THEN
                    RAISE EXCEPTION 'La cuota tiene un recibo de ingreso vigente: no se puede anular.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

                    DB::statement('CREATE TRIGGER beneficiary_installments_cancel_without_receipt
            BEFORE UPDATE OF workflow_status ON beneficiary_installments
            FOR EACH ROW EXECUTE FUNCTION beneficiary_installments_cancel_without_receipt()');
                }

                public function down(): void
                {
                    DB::statement('DROP TRIGGER IF EXISTS beneficiary_installments_cancel_without_receipt ON beneficiary_installments');
                    DB::statement('DROP FUNCTION IF EXISTS beneficiary_installments_cancel_without_receipt()');
                    DB::statement('DROP TRIGGER IF EXISTS funding_allocations_reversal_without_disbursement ON funding_allocations');
                    DB::statement('DROP FUNCTION IF EXISTS funding_allocations_reversal_without_disbursement()');
                }
            },

            /**
             * Lo entregado en mano no viaja al banco.
             *
             * Trasladar una imputación de una cuota con egreso vivo asentaba una salida
             * de la caja por un efectivo que ya se había ido con el beneficiario.
             */
            // Migración original: 2026_11_06_030000_guard_deposit_of_delivered_cash.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION cash_to_bank_transfer_items_not_delivered() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.funding_allocation_id IS NOT NULL AND EXISTS (
                    SELECT 1
                    FROM funding_allocations a
                    JOIN disbursements d ON d.beneficiary_installment_id = a.beneficiary_installment_id
                    WHERE a.id = NEW.funding_allocation_id
                      AND d.status NOT IN ('reversed', 'failed')
                ) THEN
                    RAISE EXCEPTION 'La cuota tiene un egreso registrado: ese efectivo ya no esta en la caja.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

                    DB::statement('CREATE TRIGGER cash_to_bank_transfer_items_not_delivered
            BEFORE INSERT ON cash_to_bank_transfer_items
            FOR EACH ROW EXECUTE FUNCTION cash_to_bank_transfer_items_not_delivered()');
                }

                public function down(): void
                {
                    DB::statement('DROP TRIGGER IF EXISTS cash_to_bank_transfer_items_not_delivered ON cash_to_bank_transfer_items');
                    DB::statement('DROP FUNCTION IF EXISTS cash_to_bank_transfer_items_not_delivered()');
                }
            },

        ];
    }
};
