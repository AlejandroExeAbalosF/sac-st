<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments DROP CONSTRAINT fine_payments_appeal_check;
            ALTER TABLE fine_payments ADD CONSTRAINT fine_payments_appeal_check CHECK (
                num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount) IN (0, 3)
                AND (appeal_kind IS NULL OR (
                    appeal_kind IN ('hierarchical', 'appeal')
                    AND medium IN ('cash', 'direct_deposit')
                    AND NOT judicial_origin
                    AND (external_receipt_reference IS NULL OR btrim(external_receipt_reference) <> '')
                    AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                ))
                AND (appeal_kind IS NOT NULL OR external_receipt_reference IS NULL)
            );
        SQL);
    }

    public function down(): void
    {
        if (DB::table('fine_payments')->whereNotNull('appeal_kind')->whereNull('external_receipt_reference')->exists()) {
            throw new RuntimeException('Hay recursos sin número de recibo externo; no se puede volver a exigirlo.');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments DROP CONSTRAINT fine_payments_appeal_check;
            ALTER TABLE fine_payments ADD CONSTRAINT fine_payments_appeal_check CHECK (
                num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount, external_receipt_reference) IN (0, 4)
                AND (appeal_kind IS NULL OR (
                    appeal_kind IN ('hierarchical', 'appeal')
                    AND medium IN ('cash', 'direct_deposit')
                    AND NOT judicial_origin
                    AND btrim(external_receipt_reference) <> ''
                    AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                ))
            );
        SQL);
    }
};
