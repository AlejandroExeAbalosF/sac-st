<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER haber_assigned_amount_sum ON haberes');
        DB::statement('DROP TRIGGER beneficiary_installments_sum ON beneficiary_installments');
    }

    public function down(): void
    {
        DB::statement('CREATE CONSTRAINT TRIGGER haber_assigned_amount_sum
            AFTER UPDATE OF assigned_amount ON haberes
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION check_haber_amount_against_installments()');

        DB::statement('CREATE CONSTRAINT TRIGGER beneficiary_installments_sum
            AFTER INSERT OR UPDATE OR DELETE ON beneficiary_installments
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION check_installments_sum()');
    }
};
