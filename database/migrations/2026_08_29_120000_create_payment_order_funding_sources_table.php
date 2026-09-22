<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fuentes de fondos de la Orden — §9.6 del DER.
 *
 * **Congela una sola cosa: la tabla de depósitos que el formulario
 * imprime.** Los datos del beneficiario, del empleador y de la cuenta ya
 * están congelados como columnas de `payment_orders`; acá vive el renglón
 * que el área le muestra al organismo para decir *«esta plata vino de
 * acá»*:
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_order_funding_sources', function (Blueprint $table): void {
            $table->id();

            /*
             * En cascada, al revés que el resto del sistema: estas filas
             * no tienen significado propio fuera de su Orden. Es una
             * cascada teórica —las Órdenes no se borran, lo impide un
             * trigger— pero declararla dice qué son.
             */
            $table->foreignId('payment_order_id')
                ->constrained('payment_orders')->cascadeOnDelete();

            $table->foreignId('funding_allocation_id')
                ->constrained('funding_allocations')->restrictOnDelete();

            /** El movimiento del extracto, cuando el dinero vino por banco. */
            $table->foreignId('bank_transaction_id')->nullable()
                ->constrained('bank_transactions')->restrictOnDelete();

            $table->decimal('amount', 19, 2);

            /*
             * Lo que va impreso en cada columna del renglón, copiado.
             *
             * No se leen del movimiento al imprimir: si un `parser_version`
             * nuevo relee el archivo, o el banco rectifica, el dato
             * derivado cambia y el impreso no debería.
             */
            $table->string('operation_number_snapshot', 60)->nullable();
            $table->date('operation_date_snapshot')->nullable();
            $table->string('bank_account_snapshot', 80)->nullable();
            $table->string('bank_name_snapshot', 120)->nullable();

            $table->timestampTz('created_at')->nullable();

            $table->unique(['payment_order_id', 'funding_allocation_id']);
        });

        DB::statement('ALTER TABLE payment_order_funding_sources
            ADD CONSTRAINT payment_order_funding_sources_amount_check CHECK (amount > 0)');

        /*
         * ─── Un excedente de redondeo nunca respalda una Orden ────────
         *
         * §2.4 e invariante 3 del §11. La regla no se puede escribir como
         * `CHECK`, porque el tipo de la asignación vive en otra tabla:
         * va como trigger, que es la forma que la base tiene de mirar
         * hacia afuera de la fila.
         *
         * El fondo del asunto: el excedente se asignaba a la cuota
         * *porque se iba a entregar en mano*. Una Orden es lo contrario
         * —transferencia—, y si ese efectivo se depositó, la asignación
         * de excedente ya se revirtió y el remanente quedó en la cuenta.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_order_sources_only_allocations() RETURNS trigger AS $$
            DECLARE
                v_kind text;
            BEGIN
                SELECT allocation_kind INTO v_kind
                FROM funding_allocations
                WHERE id = NEW.funding_allocation_id;

                IF v_kind IS DISTINCT FROM 'allocation' THEN
                    RAISE EXCEPTION
                        'Una Orden de Pago solo puede respaldarse en asignaciones normales, y esta es «%».',
                        COALESCE(v_kind, 'inexistente')
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER payment_order_sources_only_allocations
            BEFORE INSERT OR UPDATE ON payment_order_funding_sources
            FOR EACH ROW EXECUTE FUNCTION payment_order_sources_only_allocations()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payment_order_sources_only_allocations
            ON payment_order_funding_sources');
        DB::statement('DROP FUNCTION IF EXISTS payment_order_sources_only_allocations()');

        Schema::dropIfExists('payment_order_funding_sources');
    }
};
