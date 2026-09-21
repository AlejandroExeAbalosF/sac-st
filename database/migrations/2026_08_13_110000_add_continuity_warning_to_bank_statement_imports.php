<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La advertencia de continuidad, guardada con la importación.
 *
 * La cadena de saldos del §22 de las correcciones verifica que un archivo
 * esté completo **por dentro**. Esta columna registra la otra mitad: que
 * el archivo encadene con lo que ya se importó de esa cuenta.
 *
 * Un hueco entre importaciones no invalida el archivo que se está
 * subiendo —ese está bien—, sino que revela que falta subir otro. Por eso
 * advierte en lugar de rechazar, y por eso queda escrito: dentro de seis
 * meses, cuando alguien busque por qué una cuota nunca se financió, el
 * registro de que ese día el sistema avisó de un salto de $437.200 es la
 * pista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->string('continuity_warning', 300)->nullable()->after('balance_chain_ok');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->dropColumn('continuity_warning');
        });
    }
};
