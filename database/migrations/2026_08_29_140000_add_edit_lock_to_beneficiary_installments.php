<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La ventana de edición justificada — Correcciones §33.
 *
 * Hasta el Pase, la cuota se corrige entera y sin trabas: el recibo copia
 * lo que imprime al emitirse, así que corregirla no puede desmentir al
 * papel *porque el papel no la lee*.
 *
 * **Con el Pase eso cambia.** El expediente sale del área y el dato entra
 * en circulación: la Orden que el organismo tiene en la mano dice un
 * importe, un beneficiario y una cuenta, y editar la cuota por detrás
 * dejaría al sistema afirmando algo distinto de lo que está viajando.
 *
 * El área definió cómo se sale del bloqueo, y son tres pasos:
 *
 * 1. un botón para **registrar el caso y el porqué**;
 * 2. registrado eso, se habilita la edición de todos los campos;
 * 3. guardado, se vuelve a bloquear.
 *
 * Estas tres columnas son ese estado. **No son un permiso**: son una
 * ventana abierta por un acto que quedó registrado, con nombre y motivo,
 * y que se cierra sola al guardar. El motivo va además a `audit_events`
 * junto al antes y el después, que es donde alguien lo va a leer el año
 * que viene.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiary_installments', function (Blueprint $table): void {
            $table->timestampTz('edit_unlocked_at')->nullable();
            $table->string('edit_unlock_reason', 300)->nullable();
            $table->foreignId('edit_unlocked_by')->nullable()
                ->constrained('users')->nullOnDelete();
        });

        /*
         * Una ventana abierta sin motivo no es una ventana justificada:
         * es un bloqueo apagado. Los tres campos van juntos o no van.
         */
        DB::statement('ALTER TABLE beneficiary_installments
            ADD CONSTRAINT beneficiary_installments_edit_unlock_check
            CHECK (
                (edit_unlocked_at IS NULL AND edit_unlock_reason IS NULL AND edit_unlocked_by IS NULL)
                OR (edit_unlocked_at IS NOT NULL AND edit_unlock_reason IS NOT NULL)
            )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE beneficiary_installments
            DROP CONSTRAINT IF EXISTS beneficiary_installments_edit_unlock_check');

        Schema::table('beneficiary_installments', function (Blueprint $table): void {
            $table->dropColumn(['edit_unlocked_at', 'edit_unlock_reason', 'edit_unlocked_by']);
        });
    }
};
