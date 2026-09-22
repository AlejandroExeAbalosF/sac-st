<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importaciones de extractos bancarios — §9.3 del DER.
 *
 * Cada archivo que se descarga del banco y entra al sistema. Existe para
 * que una importación se pueda auditar y deshacer sin tocar los
 * movimientos que ya venían de otra. El archivo en sí vive en
 * `attachments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();

            $table->string('original_filename', 255);
            $table->unsignedBigInteger('file_size');
            $table->char('file_sha256', 64);

            $table->string('source_format', 30);
            $table->string('parser_version', 20);

            /*
             * La cabecera del archivo, tal como vino. Se compara contra la
             * cuenta elegida antes de escribir una sola fila; queda
             * guardada porque es la prueba de esa comprobación.
             */
            $table->string('account_number_in_file', 40)->nullable();
            $table->char('currency_in_file', 3)->nullable();
            $table->timestampTz('downloaded_at')->nullable();
            $table->string('operator_in_file', 160)->nullable();

            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('opening_balance', 19, 2)->nullable();
            $table->decimal('closing_balance', 19, 2)->nullable();

            /*
             * En la muestra real la cadena de saldos cierra exacta en las
             * catorce filas: `saldo[n] = saldo[n+1] ± importe[n]`. Es el
             * control de integridad más fuerte que da el archivo — si no
             * cierra, falta una fila o alguien la editó a mano.
             */
            $table->boolean('balance_chain_ok')->nullable();

            $table->string('status', 20);
            $table->string('failure_reason', 300)->nullable();

            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            /** Cuántos movimientos canónicos nacieron acá y cuántos ya existían. */
            $table->unsignedInteger('rows_new')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);

            $table->timestampTz('imported_at')->nullable();
            $table->timestampsTz();

            /*
             * Lo que el importador no puede decidir solo: un salto de
             * saldo entre un extracto y el siguiente puede ser un
             * archivo faltante o un rango que se pisa. Se avisa y lo
             * resuelve quien mira.
             */
            $table->string('continuity_warning', 300)->nullable();

            // El mismo archivo no se importa dos veces contra la misma
            // cuenta. No alcanza para evitar duplicados —el operador baja
            // rangos que se pisan y esos son archivos distintos—, pero
            // ataja el error más frecuente: subir el mismo otra vez.
            $table->unique(['bank_account_id', 'file_sha256']);
            $table->index(['bank_account_id', 'period_from']);
        });

        DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_status_check
            CHECK (status IN ('uploaded', 'parsing', 'completed', 'failed'))");
        DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_format_check
            CHECK (source_format IN ('macro_online_csv', 'macro_excel'))");
        DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_currency_check
            CHECK (currency_in_file IS NULL OR currency_in_file IN ('ARS', 'USD'))");
        DB::statement('ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_row_totals_check
            CHECK (rows_total = rows_valid + rows_rejected)');
        DB::statement('ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_row_origin_check
            CHECK (rows_new + rows_duplicate <= rows_valid)');
        // Una importación fallida sin motivo no sirve para nada, y un
        // motivo sobre una que salió bien confunde.
        DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_failure_check
            CHECK ((status = 'failed') = (failure_reason IS NOT NULL))");
        DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_completed_check
            CHECK ((status = 'completed') = (imported_at IS NOT NULL))");
        DB::statement('ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_period_check
            CHECK (period_from IS NULL OR period_to IS NULL OR period_to >= period_from)');
        DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_sha_check
            CHECK (file_sha256 ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_imports');
    }
};
