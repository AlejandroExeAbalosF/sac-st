<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pases — §9.7 del DER.
 *
 * **La nota formal que acompaña a la Orden hacia el organismo superior.**
 * El expediente no viaja: al SAF se remiten únicamente la Orden de Pago y
 * el Pase, y se generan juntos para una cuota ya financiada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pases', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('payment_order_id')
                ->constrained('payment_orders')->restrictOnDelete();

            /** Sin uso hoy: la nota se identifica por el número de su Orden. */
            $table->string('reference_number', 40)->nullable();

            /**
             * A quién va dirigida. Hoy siempre «SAF Gobierno», pero como
             * columna y no como constante: el área anticipó que el
             * receptor puede cambiar.
             */
            $table->string('destination', 160);

            $table->date('issue_date');
            $table->string('status', 20)->default('generated');

            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            /*
             * Queda nulo. La nota se imprime con la firma en blanco para
             * que la firme quien corresponda, y el sistema no puede
             * afirmar quién lo hizo hasta que alguien se lo diga.
             */
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestampsTz();

            /** 1:1 con la Orden: una sola nota por Orden. */
            $table->unique('payment_order_id');
        });

        DB::statement("ALTER TABLE pases ADD CONSTRAINT pases_status_check
            CHECK (status IN ('draft', 'generated', 'signed', 'archived', 'voided'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pases');
    }
};
