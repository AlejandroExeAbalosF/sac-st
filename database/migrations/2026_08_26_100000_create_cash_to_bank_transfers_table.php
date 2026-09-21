<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El efectivo que sale de la caja camino al banco — §9.5 del DER.
 *
 * Ocurre cuando el beneficiario no viene a retirar lo suyo: el área lleva
 * ese efectivo a la cuenta del organismo. **Es un traslado interno, no un
 * ingreso nuevo** (§2.1, punto 145): el dinero sigue siendo del mismo
 * beneficiario y sigue imputado a la misma cuota, solo cambia de lugar.
 *
 * Por eso el recibo de ingreso no se toca: sigue diciendo «Efectivo»,
 * porque eso fue lo que pasó y el empleador tiene su copia firmada. El
 * traslado es un hecho nuevo, no una corrección del anterior.
 *
 * **Los dos eventos y la ventana que los separa.** Entre que el efectivo
 * sale de la caja y el banco lo acredita, ese dinero no está en ninguno de
 * los dos lugares, y por eso son dos asientos con `CASH_IN_TRANSIT` en el
 * medio. Postear directo a `BANK_ACCOUNT` haría que el libro afirme que el
 * banco tiene una plata que el banco todavía no confirmó.
 *
 * **Desvío del §9.5.** No lleva `decision_date`, `decided_by`,
 * `decision_reason` ni el estado `decided`. El DER modela una decisión
 * previa al depósito; acá el traslado se registra cuando ya ocurrió —el
 * operador vuelve del banco con el ticket— y un estado «decidido»
 * describiría un trámite que el área no tiene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_to_bank_transfers', function (Blueprint $table): void {
            $table->id();

            /*
             * Los dos asientos. `deposit_event_id` nace con la fila;
             * `credit_event_id` aparece cuando el extracto confirma.
             */
            $table->foreignId('deposit_event_id')->unique()->constrained('financial_events')->restrictOnDelete();
            $table->foreignId('credit_event_id')->nullable()->unique()->constrained('financial_events')->restrictOnDelete();

            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->decimal('amount', 19, 2);

            /*
             * Lo que dice el ticket del cajero.
             *
             * Se guarda como **evidencia de lo que dice el papel**, no como
             * clave de vinculación: el área confirmó que el número de
             * operación no es fiable. La acreditación se busca por fecha e
             * importe, igual que el resto de los movimientos (§4.3).
             */
            $table->date('deposit_date');
            $table->time('deposit_time', 0)->nullable();
            $table->string('deposit_operation_number', 40)->nullable();
            $table->string('deposit_terminal', 40)->nullable();

            $table->foreignId('deposited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('deposited');

            $table->timestamps();

            $table->index(['status', 'deposit_date']);
            $table->index(['bank_account_id', 'deposit_date', 'amount']);
        });

        DB::statement("ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_status_check
            CHECK (status IN ('deposited', 'bank_confirmed', 'cancelled'))");

        DB::statement('ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_amount_check
            CHECK (amount > 0)');

        /*
         * No hay traslado acreditado sin su asiento, ni asiento sin el
         * estado que lo dice. Las dos mitades de la misma afirmacion.
         */
        DB::statement("ALTER TABLE cash_to_bank_transfers ADD CONSTRAINT cash_to_bank_transfers_credit_matches_status
            CHECK ((credit_event_id IS NOT NULL) = (status = 'bank_confirmed'))");

        /*
         * Append-only sobre los hechos; el estado si cambia, que es lo que
         * lo hace avanzar. Mismo criterio que `bank_transactions`: lo que
         * se congela es lo que ocurrio, no el marcador administrativo.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_to_bank_transfers_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.deposit_event_id IS DISTINCT FROM OLD.deposit_event_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.deposit_date IS DISTINCT FROM OLD.deposit_date
                THEN
                    RAISE EXCEPTION 'Un traslado de efectivo no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                /*
                 * El asiento de acreditacion se escribe una sola vez. Sin
                 * esto, reapuntarlo dejaria un evento posteado sin nadie
                 * que lo reclame y el otro contado dos veces.
                 */
                IF OLD.credit_event_id IS NOT NULL
                    AND NEW.credit_event_id IS DISTINCT FROM OLD.credit_event_id
                THEN
                    RAISE EXCEPTION 'La acreditacion de un traslado no se reasigna.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER cash_to_bank_transfers_append_only
            BEFORE UPDATE OR DELETE ON cash_to_bank_transfers
            FOR EACH ROW EXECUTE FUNCTION cash_to_bank_transfers_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_to_bank_transfers_append_only ON cash_to_bank_transfers');
        DB::statement('DROP FUNCTION IF EXISTS cash_to_bank_transfers_append_only()');

        Schema::dropIfExists('cash_to_bank_transfers');
    }
};
