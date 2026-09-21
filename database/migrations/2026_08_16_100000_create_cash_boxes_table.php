<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cajas — §9.1 del DER.
 *
 * **No son cajones físicos: son la clasificación contable** que separa
 * Haberes de Aranceles y de Multas. El §4.4 lo dice al descartar una caja
 * por moneda: *«`cash_boxes` sigue siendo la clasificación contable y el
 * cajón físico guarda las dos monedas»*.
 *
 * Es lo que permite que Ledger se reutilice. Un evento financiero sabe a
 * qué caja pertenece sin saber si detrás hay un expediente, un arancel o
 * una multa — y por eso el motor contable puede servir a los tres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_boxes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);

            /*
             * Qué admite cada caja. Haberes recibe y paga; una caja de
             * recaudación pura podría recibir y no pagar nunca, y esa
             * diferencia conviene que sea un dato y no una regla escondida
             * en el código.
             */
            $table->boolean('allows_income')->default(true);
            $table->boolean('allows_expense')->default(true);

            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE cash_boxes ADD CONSTRAINT cash_boxes_code_check
            CHECK (code ~ '^[a-z_]+$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_boxes');
    }
};
