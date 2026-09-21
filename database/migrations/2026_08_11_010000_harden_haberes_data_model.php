<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expedientes', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('subject');
        });

        Schema::table('haberes', function (Blueprint $table): void {
            $table->string('concept', 255)->nullable()->after('payment_terms');
        });

        // Los registros existentes guardaban el concepto resuelto en cada
        // cuota. Se toma el primero como concepto base del haber para que las
        // cuotas que lleguen más adelante puedan heredarlo.
        DB::statement(<<<'SQL'
            UPDATE haberes AS h
               SET concept = (
                    SELECT bi.description
                      FROM beneficiary_installments AS bi
                     WHERE bi.haber_id = h.id
                       AND bi.description IS NOT NULL
                     ORDER BY bi.installment_number
                     LIMIT 1
               )
             WHERE h.concept IS NULL
        SQL);

        // En registros contables una eliminación accidental no debe borrar
        // en cascada el derecho y sus cuotas. El dominio usa estados para
        // anular; la base restringe la eliminación física.
        Schema::table('haberes', function (Blueprint $table): void {
            $table->dropForeign(['expediente_id']);
            $table->foreign('expediente_id')->references('id')->on('expedientes')->restrictOnDelete();
        });

        Schema::table('beneficiary_installments', function (Blueprint $table): void {
            $table->dropForeign(['haber_id']);
            $table->foreign('haber_id')->references('id')->on('haberes')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
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
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER haber_assigned_amount_sum
                AFTER UPDATE OF assigned_amount ON haberes
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION check_haber_amount_against_installments();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS haber_assigned_amount_sum ON haberes');
        DB::statement('DROP FUNCTION IF EXISTS check_haber_amount_against_installments()');

        Schema::table('beneficiary_installments', function (Blueprint $table): void {
            $table->dropForeign(['haber_id']);
            $table->foreign('haber_id')->references('id')->on('haberes')->cascadeOnDelete();
        });

        Schema::table('haberes', function (Blueprint $table): void {
            $table->dropForeign(['expediente_id']);
            $table->foreign('expediente_id')->references('id')->on('expedientes')->cascadeOnDelete();
            $table->dropColumn('concept');
        });

        Schema::table('expedientes', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
