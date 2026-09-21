<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La recepción se puede revertir — desvío 46.
 *
 * El trigger `fund_receipts_append_only` viene diciendo desde el principio
 * «una recepcion de fondos no se borra: se revierte» y «los datos de una
 * recepcion no se editan: se revierte y se registra de nuevo». Esa
 * reversión **no existía**: no había Action, ni ruta, ni botón. La base
 * mandaba a una puerta sin construir, que es peor que no ofrecerla.
 *
 * El caso que lo destapó: el crédito de nuestro propio depósito de efectivo
 * llega al extracto como cualquier otro, y si alguien lo registra como
 * recepción en vez de acreditarlo contra el traslado, la misma plata queda
 * contada dos veces —una en tránsito desde la caja y otra como si hubiera
 * entrado de la nada— sin forma de deshacerlo.
 *
 * Revertir exige decir por qué, como descartar un comprobante: es afirmar
 * que ese dinero nunca entró por esta vía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_receipts', function (Blueprint $table): void {
            $table->foreignId('reversal_event_id')
                ->nullable()
                ->unique()
                ->constrained('financial_events')
                ->restrictOnDelete();
            $table->timestampTz('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
        });

        // Las cuatro columnas van juntas o no va ninguna: media reversión
        // no es un estado del que se pueda decir nada.
        DB::statement('ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_reversal_check
            CHECK (
                (reversal_event_id IS NULL) = (reversed_at IS NULL)
                AND (reversal_event_id IS NULL) = (reversal_reason IS NULL)
            )');

        /*
         * Lo revertido no se vuelve a repartir.
         *
         * El Action lo comprueba y da la frase legible, pero la garantía
         * vive acá: una imputación desde una recepción revertida sería
         * dinero que el libro ya dijo que no entró.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION funding_allocations_receipt_live() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts
                    WHERE id = NEW.fund_receipt_id
                      AND reversal_event_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Esa recepcion esta revertida: su dinero no se puede imputar.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER funding_allocations_receipt_live
            BEFORE INSERT ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION funding_allocations_receipt_live()');

        /*
         * Y la reversión, una vez hecha, tampoco se edita: se agrega al
         * juego de campos que el append-only ya congelaba.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.medium IS DISTINCT FROM OLD.medium
                    OR NEW.received_date IS DISTINCT FROM OLD.received_date
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                    OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                    OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                THEN
                    RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reversal_event_id IS NOT NULL
                    AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                THEN
                    RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS funding_allocations_receipt_live ON funding_allocations');
        DB::statement('DROP FUNCTION IF EXISTS funding_allocations_receipt_live()');
        DB::statement('ALTER TABLE fund_receipts DROP CONSTRAINT IF EXISTS fund_receipts_reversal_check');

        Schema::table('fund_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_event_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
