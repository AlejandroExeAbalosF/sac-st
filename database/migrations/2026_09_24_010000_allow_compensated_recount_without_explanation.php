<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Un recuento total que cuadra puede tener otro reparto interno de billetes. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT cash_counts_explanation_check');

        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount - COALESCE(carry_counted_amount, 0)
                    = expected_amount - uncounted_amount - COALESCE(carry_expected_amount, 0)
                OR (carry_counted_amount IS NOT NULL AND counted_amount = expected_amount)
                OR explanation IS NOT NULL
            )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT cash_counts_explanation_check');

        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount - COALESCE(carry_counted_amount, 0)
                    = expected_amount - uncounted_amount - COALESCE(carry_expected_amount, 0)
                OR explanation IS NOT NULL
            )');
    }
};
