<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Series de numeración — §9.8 del DER.
 *
 * Una sola tabla numera todos los documentos numerados del sistema:
 * recibos de ingreso, recibos de egreso y Órdenes de Pago. Reemplaza a
 * `receipt_series` y `receipt_internal_sequences`, que en la v4.2 eran dos
 * numeraciones en pie de igualdad y obligaban a decidir cuál mandaba en
 * los reportes.
 *
 * **El número lo genera siempre el sistema**, incluso para los
 * comprobantes confeccionados en papel: el código de serie distingue el
 * origen (`0010` sistema / `0011` talonario) y el número preimpreso queda
 * como referencia opcional en `receipts.talonario_number`. Es el
 * invariante 19 del DER.
 *
 * Formato `CCCC/NNNNNNNN`, siguiendo la convención de AFIP —cuatro
 * dígitos de punto de venta y ocho de comprobante—. Ocho dígitos dan
 * margen de sobra: el talonario actual lleva unos 76.000 comprobantes en
 * ocho años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table): void {
            $table->id();

            /*
             * El código dice todo lo que la serie es:
             *
             *   0 0 1 0
             *   │ │ │ └── origen: 0 sistema, 1 talonario preimpreso
             *   │ │ └──── tipo:   1 recibo de ingreso, 2 de egreso, 3 Orden
             *   └─┴────── caja:   00 Haberes, 01 Aranceles, 02 Multas
             */
            $table->char('code', 4)->unique();
            $table->string('document_type', 20);
            $table->string('label', 120);

            /*
             * La caja a la que pertenece. Nulo hasta que `cash_boxes`
             * exista: se agrega la FK en su propia migración, cuando haya
             * a qué apuntar.
             */
            $table->unsignedBigInteger('cash_box_id')->nullable();

            $table->string('origin', 20);

            $table->string('format_pattern', 40)->default('{code}/{number}');
            $table->unsignedSmallInteger('code_padding')->default(4);
            $table->unsignedSmallInteger('number_padding')->default(8);

            $table->string('reset_rule', 10)->default('never');
            $table->unsignedSmallInteger('year')->nullable();

            /*
             * El correlativo propio de la serie. Se toma con `lockForUpdate`
             * y se incrementa; **nunca** se calcula con `MAX(number) + 1`,
             * que bajo concurrencia entrega el mismo número dos veces.
             */
            $table->unsignedBigInteger('next_number')->default(1);

            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_code_check
            CHECK (code ~ '^[0-9]{4}$')");
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_type_check
            CHECK (document_type IN ('receipt_income', 'receipt_expense', 'payment_order'))");
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_origin_check
            CHECK (origin IN ('system', 'talonario_loaded'))");
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_reset_check
            CHECK (reset_rule IN ('never', 'yearly'))");
        // Una serie anual sin año no sabe cuándo reiniciar; una que nunca
        // reinicia no tiene por qué llevarlo.
        DB::statement("ALTER TABLE document_series ADD CONSTRAINT document_series_year_check
            CHECK ((reset_rule = 'yearly') = (year IS NOT NULL))");
        DB::statement('ALTER TABLE document_series ADD CONSTRAINT document_series_next_number_check
            CHECK (next_number >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
