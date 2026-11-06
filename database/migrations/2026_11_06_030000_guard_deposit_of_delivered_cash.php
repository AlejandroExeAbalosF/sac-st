<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lo entregado en mano no viaja al banco.
 *
 * Trasladar una imputación de una cuota con egreso vivo asentaba una salida
 * de la caja por un efectivo que ya se había ido con el beneficiario.
 */
return new class extends Migration
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
};
