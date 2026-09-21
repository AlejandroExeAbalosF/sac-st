<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos bancarios canónicos — §9.3 del DER.
 *
 * Un crédito o un débito real de la cuenta, una sola vez, aunque aparezca
 * en tres descargas distintas. Es la contracara de `bank_statement_rows`:
 * la fila es lo que decía un archivo, esto es lo que pasó en el banco.
 *
 * **`operation_id` no es único, y ya no es una pregunta abierta.** §4.3
 * del DER dejaba la unicidad en suspenso hasta ver muestras reales. El
 * extracto del área la responde: las referencias `86934222`, `85761672` y
 * `85634410` aparecen dos veces cada una —la transferencia y su comisión
 * comparten referencia—. Queda indexada para búsqueda y nada más.
 *
 * Dos desvíos respecto del DER, ambos por los archivos reales:
 *
 * - **`causal_code`.** MacroOnline lo trae en los dos formatos: 3913
 *   transferencia, 3914 comisión, 3861/3862 entre cuentas propias, 4397 y
 *   493 créditos de terceros. Es el mejor discriminador que da el banco y
 *   el DER lo descarta; sin él hay que adivinar por el texto del concepto.
 * - **`balance_after` dentro del `fingerprint`.** Dos comisiones de $121
 *   el mismo día son indistinguibles por fecha, importe y concepto, pero
 *   su saldo posterior difiere porque la cadena de saldos es estrictamente
 *   creciente en el tiempo. Eso convierte la deduplicación entre
 *   descargas solapadas de asistida a exacta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            /*
             * La importación donde este movimiento se vio por primera vez.
             * `restrictOnDelete` a propósito: revertir una importación
             * tiene que decidir explícitamente qué pasa con los
             * movimientos que también aparecen en otra, y la base no lo
             * deja pasar por alto.
             */
            $table->foreignId('first_seen_import_id')
                ->constrained('bank_statement_imports')
                ->restrictOnDelete();

            $table->date('transaction_date');
            /*
             * MacroOnline no informa fecha valor en ninguno de los dos
             * formatos, así que hoy queda siempre nula. Se conserva porque
             * es dato del DER y otros bancos sí la traen.
             */
            $table->date('value_date')->nullable();

            $table->decimal('amount', 19, 2);
            $table->string('direction', 10);

            $table->string('operation_id', 40)->nullable();
            $table->string('causal_code', 10)->nullable();
            $table->string('description', 300)->nullable();

            /*
             * Los conceptos traen el CUIT del empleador embebido en media
             * docena de formas —`TRANSF SANDOVAL 20444444445 VAR`,
             * `CCERR ... 30333333339 CIRC.CERRADO`,
             * `TRANSF:XXXX-30715030817`—. Extraerlo acá es lo que después
             * permite sugerir de quién es el ingreso, que es el trabajo
             * que hoy se hace a mano en las hojas del libro banco.
             */
            $table->string('counterparty_name', 200)->nullable();
            $table->string('counterparty_identifier', 20)->nullable();

            $table->decimal('balance_after', 19, 2)->nullable();

            $table->char('fingerprint', 64);

            $table->string('reconciliation_status', 20)->default('pending');
            $table->string('ignored_reason', 300)->nullable();
            $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('ignored_at')->nullable();

            $table->timestampsTz();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['bank_account_id', 'operation_id']);
            $table->index(['bank_account_id', 'reconciliation_status']);
            $table->index('counterparty_identifier');
        });

        DB::statement('ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_amount_check
            CHECK (amount > 0)');
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_direction_check
            CHECK (direction IN ('credit', 'debit'))");
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_reconciliation_check
            CHECK (reconciliation_status IN ('pending', 'partial', 'reconciled', 'ignored'))");
        // Declarar que un movimiento no entra en la contabilidad exige
        // decir por qué y quién lo dijo: es una decisión, no un estado.
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_ignored_reason_check
            CHECK ((reconciliation_status = 'ignored') = (ignored_reason IS NOT NULL))");
        DB::statement('ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_ignored_pair_check
            CHECK ((ignored_by IS NULL) = (ignored_at IS NULL))');
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_ignored_author_check
            CHECK (reconciliation_status <> 'ignored' OR ignored_by IS NOT NULL)");

        /*
         * Único parcial, no total: sin saldo posterior la huella no
         * distingue dos movimientos genuinamente idénticos, y prohibirlos
         * sería descartar uno real. Esas filas caen a revisión manual,
         * que es lo que el DER llama «duplicados asistidos».
         */
        DB::statement('CREATE UNIQUE INDEX bank_transactions_fingerprint_unique
            ON bank_transactions (bank_account_id, fingerprint) WHERE balance_after IS NOT NULL');
        DB::statement('CREATE INDEX bank_transactions_fingerprint_lookup
            ON bank_transactions (bank_account_id, fingerprint)');

        /*
         * Append-only sobre los hechos monetarios, con la precisión que da
         * §9.4 del DER: lo que no se toca nunca es el importe, la fecha,
         * la dirección y la identidad del movimiento. `reconciliation_status`
         * y sus acompañantes son marcadores administrativos y sí cambian
         * —cada cambio queda en `audit_events`—.
         *
         * El borrado se habilita solo dentro de una transacción que lo
         * declare. Es la salida que necesita revertir una importación
         * equivocada: sin ella, un archivo mal parseado dejaría movimientos
         * falsos para siempre y su huella única bloquearía la importación
         * del movimiento correcto. `SET LOCAL` muere con la transacción,
         * así que la puerta no queda abierta.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_bank_transaction_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un movimiento bancario no se borra: se revierte la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.value_date IS DISTINCT FROM OLD.value_date
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.causal_code IS DISTINCT FROM OLD.causal_code
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.balance_after IS DISTINCT FROM OLD.balance_after
                    OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                THEN
                    RAISE EXCEPTION 'Los datos de un movimiento bancario no se editan: son lo que informó el banco.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER bank_transactions_append_only
            BEFORE UPDATE OR DELETE ON bank_transactions
            FOR EACH ROW EXECUTE FUNCTION bank_transactions_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS bank_transactions_append_only ON bank_transactions');
        DB::statement('DROP FUNCTION IF EXISTS bank_transactions_append_only()');

        Schema::dropIfExists('bank_transactions');
    }
};
