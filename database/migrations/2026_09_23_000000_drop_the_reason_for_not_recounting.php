<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Declarar un saldo sin recontar deja de exigir un motivo.
 *
 * El motivo se pidió suponiendo que no recontar era la excepción. La planilla
 * de junio de 2026 muestra lo contrario: **los veinte días** arrastran un
 * saldo sin recontar, y en cuatro de ellos es el cajón entero.
 *
 * Un campo obligatorio que se completa igual todas las tardes deja de aportar
 * y empieza a estorbar: se llena con cualquier cosa y nadie lo lee. El dato
 * que importa ya está en la fila —cuánto se declaró sin contar— y ese no se
 * va a ningún lado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT IF EXISTS cash_counts_uncounted_reason_check');

        Schema::table('cash_counts', function (Blueprint $table): void {
            $table->dropColumn('uncounted_reason');
        });
    }

    public function down(): void
    {
        Schema::table('cash_counts', function (Blueprint $table): void {
            $table->string('uncounted_reason', 300)->nullable();
        });

        /*
         * Los motivos de los arqueos que ya existían no vuelven: la columna
         * se recrea vacía. Por eso el `CHECK` se restablece sin poder exigir
         * lo que ya no está, y solo alcanza a las filas nuevas.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_uncounted_reason_check
            CHECK (uncounted_amount = 0 OR uncounted_reason IS NOT NULL) NOT VALID
        SQL);
    }
};
