<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domicilio y teléfono de la persona.
 *
 * **Los pide el formulario de la Orden de Pago**, y para las dos partes:
 * el beneficiario que va a cobrar y la empresa que depositó. La Orden 3582
 * los imprime —«B° SAN JORGE MZA 54 CASA 03 CAMPO QUIJANO SALTA»,
 * «387-6004216»— y hasta ahora no vivían en ningún lado.
 *
 * Van en `people` y no en `payment_orders` porque son de la persona: se
 * cargan una vez y sirven para todas sus Órdenes. La Orden igual se queda
 * con su copia congelada, que es lo que el papel entregado dice.
 *
 * Nulos, porque la mayoría de las personas del sistema no llegan a
 * necesitarlos: quien emite una Orden los completa en ese momento, en el
 * modal que le dice qué le falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('address', 300)->nullable()->after('document');
            $table->string('phone', 40)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['address', 'phone']);
        });
    }
};
