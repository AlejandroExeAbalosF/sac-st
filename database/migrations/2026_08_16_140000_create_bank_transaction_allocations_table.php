<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imputaciones de movimientos bancarios — §9.3 del DER.
 *
 * La quinta y última tabla del bloque de banco. Es la costura entre lo que
 * el banco informó y lo que el sistema asentó: vincula una porción de un
 * movimiento con el evento financiero que ese movimiento explica.
 *
 * **Por qué una porción y no el movimiento entero.** Un crédito de
 * $150.000 puede ser el depósito de dos expedientes distintos. Cada uno es
 * su propia recepción, con su propio asiento, y ambos apuntan al mismo
 * movimiento. Lo que el sistema garantiza es que entre todos no reclamen
 * más plata de la que el banco informó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transaction_allocations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('bank_transaction_id')->constrained('bank_transactions')->restrictOnDelete();
            $table->foreignId('financial_event_id')->constrained('financial_events')->restrictOnDelete();

            $table->string('allocation_role', 40);
            $table->decimal('amount', 19, 2);

            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('bank_transaction_allocations')->restrictOnDelete();

            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('allocated_at')->useCurrent();
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->index(['bank_transaction_id', 'allocation_role']);
            $table->index('financial_event_id');
        });

        DB::statement("ALTER TABLE bank_transaction_allocations ADD CONSTRAINT bank_transaction_allocations_role_check
            CHECK (allocation_role IN (
                'funds_received', 'payment_confirmation', 'cash_deposit_confirmation'
            ))");
        DB::statement('ALTER TABLE bank_transaction_allocations ADD CONSTRAINT bank_transaction_allocations_amount_check
            CHECK (amount > 0)');

        /*
         * El mismo movimiento no se vincula dos veces al mismo evento.
         *
         * Parcial sobre las vigentes: una reversión es una fila más que
         * apunta al mismo par, y tiene que poder existir.
         */
        DB::statement('CREATE UNIQUE INDEX bank_transaction_allocations_unique_live
            ON bank_transaction_allocations (bank_transaction_id, financial_event_id)
            WHERE reversal_of_id IS NULL');

        /*
         * ─── No se puede repartir más de lo que el banco informó ───────
         *
         * §9.3: *«La suma neta vinculada nunca supera el movimiento
         * bancario»*. Un crédito de $72.000 no puede respaldar $80.000 en
         * recepciones, y sin este control el sistema mostraría fondos que
         * no existen.
         *
         * «Neta» es la palabra importante: las reversiones restan. Una
         * vinculación revertida libera el importe para volver a imputarlo,
         * que es lo que ocurre cuando el operador se equivoca de
         * expediente.
         *
         * Este va inmediato, no diferido. A diferencia del balance de un
         * asiento —que solo está completo al final de la transacción—, cada
         * imputación se puede juzgar sola: o entra en lo que queda, o no
         * entra. Fallar en la fila que se pasa da un diagnóstico que
         * fallar al confirmar no daría.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_allocation_within_transaction() RETURNS trigger AS $$
            DECLARE
                importe_movimiento NUMERIC(19,2);
                total_neto NUMERIC(19,2);
            BEGIN
                SELECT ABS(amount) INTO importe_movimiento
                FROM bank_transactions
                WHERE id = NEW.bank_transaction_id;

                SELECT COALESCE(SUM(
                    CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END
                ), 0)
                INTO total_neto
                FROM bank_transaction_allocations
                WHERE bank_transaction_id = NEW.bank_transaction_id;

                IF total_neto > importe_movimiento THEN
                    RAISE EXCEPTION
                        'El movimiento bancario es de % y ya tiene % imputados.',
                        importe_movimiento, total_neto
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER bank_allocation_within_transaction
            AFTER INSERT OR UPDATE ON bank_transaction_allocations
            FOR EACH ROW EXECUTE FUNCTION bank_allocation_within_transaction()');

        /*
         * Append-only: una imputación equivocada no se corrige editándola,
         * se revierte con otra que la anula. El rastro de que alguien creyó
         * que ese crédito era de otro expediente es parte de la auditoría.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_transaction_allocations_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una imputacion bancaria no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                    OR NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.allocation_role IS DISTINCT FROM OLD.allocation_role
                    OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id
                THEN
                    RAISE EXCEPTION 'Una imputacion bancaria no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER bank_transaction_allocations_append_only
            BEFORE UPDATE OR DELETE ON bank_transaction_allocations
            FOR EACH ROW EXECUTE FUNCTION bank_transaction_allocations_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS bank_transaction_allocations_append_only ON bank_transaction_allocations');
        DB::statement('DROP TRIGGER IF EXISTS bank_allocation_within_transaction ON bank_transaction_allocations');
        DB::statement('DROP FUNCTION IF EXISTS bank_transaction_allocations_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS bank_allocation_within_transaction()');

        Schema::dropIfExists('bank_transaction_allocations');
    }
};
