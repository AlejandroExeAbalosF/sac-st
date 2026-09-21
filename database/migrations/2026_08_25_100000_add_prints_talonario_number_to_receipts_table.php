<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué número encabeza el papel.
 *
 * El recibo tiene dos: el que le asigna el sistema y, cuando se escribió a
 * mano, el del talonario. La unicidad es la combinación de los dos, y los
 * dos se imprimen; lo que esto guarda es cuál va arriba.
 *
 * **Se guarda y no se deduce** porque es una decisión del operador, y
 * porque la reimpresión tiene que salir igual que el original: si el
 * criterio viviera solo en la pantalla, el papel emitido hoy y su copia de
 * mañana podrían encabezar distinto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table): void {
            $table->boolean('prints_talonario_number')->default(false);
        });

        /*
         * No se puede encabezar con un número que no se cargó. Va a la
         * base y no solo al Action: es la clase de incoherencia que
         * después aparece impresa.
         */
        DB::statement('
            ALTER TABLE receipts
            ADD CONSTRAINT receipts_prints_talonario_requires_number
            CHECK (NOT prints_talonario_number OR talonario_number IS NOT NULL)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE receipts DROP CONSTRAINT IF EXISTS receipts_prints_talonario_requires_number');

        Schema::table('receipts', function (Blueprint $table): void {
            $table->dropColumn('prints_talonario_number');
        });
    }
};
