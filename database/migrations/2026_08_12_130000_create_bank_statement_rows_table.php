<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Filas del extracto bancario — §9.3 del DER.
 *
 * Cada fila tal como vino, en `raw_data`, junto a lo que el parser
 * entendió de ella. Es la evidencia: si mañana se descubre que el parser
 * interpretaba mal una columna, el original sigue acá y se puede rehacer.
 *
 * La separación entre esto y `bank_transactions` es lo que resuelve el
 * problema real del área: el operador descarga «Últimos movimientos» con
 * rangos que se pisan, así que el mismo crédito llega en dos archivos
 * distintos. La fila se guarda las dos veces —son dos hechos, dos
 * archivos—; el movimiento existe una sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_rows', function (Blueprint $table): void {
            $table->id();

            // Cascade: revertir una importación se lleva sus filas. Son
            // parte del archivo, no tienen vida propia sin él.
            $table->foreignId('bank_statement_import_id')
                ->constrained('bank_statement_imports')
                ->cascadeOnDelete();

            $table->unsignedInteger('row_number');

            /** Lo que decía el archivo, sin interpretar. */
            $table->jsonb('raw_data');

            $table->date('parsed_transaction_date')->nullable();
            $table->date('parsed_value_date')->nullable();
            $table->decimal('parsed_amount', 19, 2)->nullable();
            $table->string('parsed_direction', 10)->nullable();
            $table->string('parsed_operation_id', 40)->nullable();
            $table->string('parsed_causal_code', 10)->nullable();
            $table->string('parsed_description', 300)->nullable();
            $table->string('parsed_counterparty', 200)->nullable();
            $table->string('parsed_counterparty_identifier', 20)->nullable();
            $table->decimal('parsed_balance_after', 19, 2)->nullable();

            /** Huella de similitud, no clave: el índice es a propósito no único. */
            $table->char('fingerprint', 64)->nullable();

            $table->string('parse_status', 20);
            $table->string('error_message', 300)->nullable();

            // Se anula al revertir la importación que trajo el movimiento,
            // sin borrar la fila: la fila sigue siendo lo que decía el
            // archivo aunque su movimiento ya no exista.
            $table->foreignId('bank_transaction_id')
                ->nullable()
                ->constrained('bank_transactions')
                ->nullOnDelete();

            $table->string('match_method', 20)->nullable();
            $table->unsignedSmallInteger('match_confidence')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['bank_statement_import_id', 'row_number']);
            $table->index('fingerprint');
            $table->index('bank_transaction_id');
        });

        DB::statement("ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_parse_status_check
            CHECK (parse_status IN ('valid', 'warning', 'rejected'))");
        DB::statement("ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_direction_check
            CHECK (parsed_direction IS NULL OR parsed_direction IN ('credit', 'debit'))");
        DB::statement('ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_amount_check
            CHECK (parsed_amount IS NULL OR parsed_amount > 0)');
        // Una fila rechazada tiene que decir por qué; una válida no tiene
        // nada que explicar.
        DB::statement("ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_error_check
            CHECK ((parse_status = 'rejected') = (error_message IS NOT NULL))");
        // Una fila rechazada no se interpretó: no puede haber quedado
        // vinculada a un movimiento.
        DB::statement("ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_rejected_link_check
            CHECK (parse_status <> 'rejected' OR bank_transaction_id IS NULL)");
        DB::statement("ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_match_method_check
            CHECK (match_method IS NULL OR match_method IN ('imported', 'fingerprint', 'operation_id', 'exact', 'manual'))");
        DB::statement('ALTER TABLE bank_statement_rows ADD CONSTRAINT bank_statement_rows_confidence_check
            CHECK (match_confidence IS NULL OR match_confidence BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_rows');
    }
};
