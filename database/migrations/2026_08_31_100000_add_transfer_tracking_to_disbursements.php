<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El débito bancario, observado antes de que exista el asiento.
 *
 * ── El choque que esta columna resuelve ────────────────────────────────
 *
 * El §9.7 dice que la confirmación de una transferencia exige
 * *«vinculación con débito mediante `bank_transaction_allocations`»*. Pero
 * esa tabla exige un `financial_event_id`, y el §2.3.5 es igual de
 * explícito en la otra dirección: el evento financiero del egreso **se
 * postea recién después de la validación del contador**.
 *
 * Los dos no pueden cumplirse a la vez mientras el débito se observe
 * antes, y el §12.4 —«débito observado antes del informe»— dice que se
 * observa antes. Sin un lugar donde anotarlo, el operador que ve el débito
 * en el extracto no tiene dónde dejarlo asentado y el dato se pierde hasta
 * que el organismo informe.
 *
 * Así que el movimiento se guarda acá desde que se lo reconoce, y la fila
 * de `bank_transaction_allocations` se escribe al confirmar, junto con el
 * evento que la tabla necesita. **El DER se cumple entero**: al final del
 * circuito la imputación existe, con su rol `payment_confirmation`; lo
 * único que cambia es que el reconocimiento y la imputación dejan de ser
 * el mismo acto, que es lo que el propio DER pide al separarlos en dos
 * estados.
 *
 * Mientras no esté confirmado el vínculo es corregible: el operador puede
 * desvincular un débito que resultó ser de otra Orden. Después de
 * confirmar ya no, y eso lo impone el trigger de más abajo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disbursements', function (Blueprint $table): void {
            $table->foreignId('bank_transaction_id')->nullable()->after('transfer_reference')
                ->constrained('bank_transactions')->restrictOnDelete();

            $table->index('bank_transaction_id');
        });

        /*
         * ─── La tercera condición del §9.7 ───────────────────────────
         *
         * Las otras dos —informe y validación— ya estaban en
         * `disbursements_transfer_confirmed_check`. Ésta faltaba porque no
         * tenía columna: un egreso por transferencia no se confirma sin el
         * débito que lo prueba contra el extracto del banco.
         *
         * Es lo que separa «el organismo dice que pagó» de «el banco
         * muestra que salió», y con dinero de terceros esa diferencia es
         * la razón de ser del control (invariantes 12 y 13).
         */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_transfer_needs_debit_check
            CHECK (
                status <> 'confirmed'
                OR method <> 'bank_transfer'
                OR bank_transaction_id IS NOT NULL
            )");

        /*
         * El movimiento queda fijo una vez confirmado.
         *
         * El trigger original protege importe, vía, cuota y asiento desde
         * el primer día; el débito no podía estar en esa lista porque
         * mientras se prepara el egreso es justamente lo que se corrige.
         * Lo que no puede cambiar es el débito de un pago ya validado: ahí
         * hay un asiento posteado y una imputación bancaria que lo
         * referencian.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'confirmed'
                    AND NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                THEN
                    RAISE EXCEPTION 'El débito de un egreso confirmado no se cambia: hay un asiento que lo referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE disbursements DROP CONSTRAINT IF EXISTS disbursements_transfer_needs_debit_check');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        Schema::table('disbursements', function (Blueprint $table): void {
            $table->dropForeign(['bank_transaction_id']);
            $table->dropColumn('bank_transaction_id');
        });
    }
};
