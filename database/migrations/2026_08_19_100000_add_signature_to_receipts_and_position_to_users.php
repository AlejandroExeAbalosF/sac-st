<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quién firma el comprobante.
 *
 * El recibo de papel cierra con «Firma y Aclaración» y debajo, impreso a
 * sello, el nombre y el cargo de quien responde por él: *«C.P.N. Achad
 * Nora Beatriz — Asesor Contable — Secretaría de Trabajo»*. El sistema no
 * tenía dónde guardar ese cargo.
 *
 * **Va en el usuario y se congela en el recibo.** En el usuario porque es
 * un dato de la persona, no del comprobante; congelado en el recibo porque
 * el papel entregado dice lo que decía el día que se emitió, y un ascenso
 * no puede reescribir comprobantes viejos. Es el mismo criterio de los
 * otros `_snapshot`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * Opcional: la mayoría de los usuarios no firma comprobantes.
             * Quien no lo tenga cargado simplemente no aparece con cargo
             * en el pie del recibo.
             */
            $table->string('position', 120)->nullable()->after('document_number');
        });

        Schema::table('receipts', function (Blueprint $table): void {
            $table->foreignId('signed_by')->nullable()->after('issued_by')
                ->constrained('users')->nullOnDelete();
            $table->string('signed_by_name_snapshot', 200)->nullable()->after('signed_by');
            $table->string('signed_by_title_snapshot', 120)->nullable()->after('signed_by_name_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signed_by');
            $table->dropColumn(['signed_by_name_snapshot', 'signed_by_title_snapshot']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
