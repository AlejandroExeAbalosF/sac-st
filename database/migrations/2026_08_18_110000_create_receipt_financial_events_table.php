<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué hechos monetarios documenta cada recibo — §9.8 del DER.
 *
 * **Por qué no alcanza con una columna en `receipts`.** El recibo de
 * ingreso se emite una sola vez, cuando la cuota queda completa, pero esa
 * cuota pudo financiarse con más de un ingreso (§2.1.9). Un único
 * comprobante puede entonces documentar varias asignaciones, y esta tabla
 * es la que deja ver cuáles.
 *
 * Vive en `Ledger` y no en `Shared` con `receipts`, y la razón es la
 * dirección de las dependencias: referencia `financial_events`, que es de
 * Ledger. Desde Shared esa foránea apuntaría hacia arriba; desde Ledger
 * apunta a Shared, que es el sentido permitido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_financial_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('receipt_id')->constrained('receipts')->cascadeOnDelete();
            $table->foreignId('financial_event_id')->constrained('financial_events')->restrictOnDelete();

            $table->timestampsTz();

            /*
             * El mismo hecho no se documenta dos veces en el mismo
             * recibo. Sin esto, un error de reintento haría que el
             * comprobante pareciera respaldar el doble de lo que
             * respalda.
             */
            $table->unique(['receipt_id', 'financial_event_id']);
            $table->index('financial_event_id');
        });

        /*
         * Append-only sobre el vínculo: qué respalda un comprobante
         * entregado no se reescribe. Si el recibo estaba mal, se anula el
         * recibo —y con él caen sus vínculos, que es el único caso en que
         * esta tabla pierde filas—.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION receipt_financial_events_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Lo que un comprobante respalda no se edita: se anula el comprobante.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER receipt_financial_events_append_only
            BEFORE UPDATE ON receipt_financial_events
            FOR EACH ROW EXECUTE FUNCTION receipt_financial_events_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS receipt_financial_events_append_only ON receipt_financial_events');
        DB::statement('DROP FUNCTION IF EXISTS receipt_financial_events_append_only()');

        Schema::dropIfExists('receipt_financial_events');
    }
};
