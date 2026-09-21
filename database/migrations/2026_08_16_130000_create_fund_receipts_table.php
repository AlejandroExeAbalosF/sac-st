<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recepciones de fondos — §9.4 del DER.
 *
 * **El dinero entró.** Todavía no se sabe de quién es —eso lo dice la
 * asignación— pero ya está bajo custodia del organismo y tiene que
 * aparecer en los saldos.
 *
 * Cada recepción es exactamente un evento financiero (`UNIQUE` sobre
 * `financial_event_id`): la recepción es la cara legible del hecho y el
 * evento es su asiento. Duplicar uno sin el otro rompería el equilibrio
 * entre el libro y lo que el área ve en pantalla.
 *
 * **No guarda el remanente no asignado.** El §5.1 es terminante: *«Los
 * saldos se calculan; no son contadores editables»*. Lo que sí guarda es
 * `residual_status`, que no es un importe sino una declaración: alguien
 * miró ese sobrante y lo dio por cerrado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_receipts', function (Blueprint $table): void {
            $table->id();

            /** Uno a uno con su asiento. */
            $table->foreignId('financial_event_id')->unique()->constrained('financial_events')->restrictOnDelete();

            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->restrictOnDelete();

            /*
             * Quién puso el dinero, cuando se sabe.
             *
             * Es opcional a propósito: un crédito bancario puede llegar sin
             * que el ordenante esté identificado en el maestro de personas,
             * y frenar la recepción por eso dejaría plata real fuera de los
             * libros. Se completa después, al identificarla.
             */
            $table->foreignId('depositor_id')->nullable()->constrained('people')->restrictOnDelete();

            $table->string('medium', 20);
            $table->decimal('amount', 19, 2);
            $table->date('received_date');

            /*
             * El cheque, que sigue el circuito del efectivo (§2.5).
             *
             * Sus datos están en el núcleo y no diferidos porque el reverso
             * de la planilla de caja los exige uno por uno: número, banco
             * librador, fecha e importe. Un `fund_receipt` por cheque es la
             * unidad natural — el recibo 64685 de junio de 2026 tiene tres.
             */
            $table->string('cheque_number', 50)->nullable();
            $table->string('cheque_bank', 120)->nullable();
            $table->date('cheque_issue_date')->nullable();
            $table->string('cheque_status', 20)->nullable();

            $table->string('residual_status', 30)->default('open');
            $table->string('residual_note', 300)->nullable();
            $table->foreignId('residual_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('residual_acknowledged_at')->nullable();

            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->index(['medium', 'received_date']);
            $table->index(['residual_status']);
            $table->index(['cash_box_id', 'received_date']);
            // El inventario de cheques en custodia sale de acá, sin tabla
            // de arqueo propia.
            $table->index(['cheque_status']);
        });

        DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_medium_check
            CHECK (medium IN ('cash', 'cheque', 'bank'))");
        DB::statement('ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_amount_check
            CHECK (amount > 0)');

        /*
         * Un cheque sin número no se puede buscar ni conciliar, y un
         * número de cheque en una recepción bancaria es un dato que
         * contradice al medio. La equivalencia impide las dos cosas de una
         * sola vez.
         */
        DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_cheque_check
            CHECK ((medium = 'cheque') = (cheque_number IS NOT NULL))");
        DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_cheque_status_check
            CHECK (
                (medium = 'cheque') = (cheque_status IS NOT NULL)
                AND (cheque_status IS NULL OR cheque_status IN (
                    'in_custody', 'delivered', 'deposited', 'cleared', 'rejected'
                ))
            )");

        DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_residual_status_check
            CHECK (residual_status IN ('open', 'acknowledged_excess'))");

        /*
         * Reconocer un excedente exige decir por qué.
         *
         * Sin esto, «excedente reconocido» sería un botón que hace
         * desaparecer plata de la cola de trabajo sin dejar rastro de quién
         * decidió que ahí no había nada que revisar.
         */
        DB::statement("ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_residual_note_check
            CHECK (
                residual_status = 'open'
                OR (residual_note IS NOT NULL AND residual_acknowledged_by IS NOT NULL
                    AND residual_acknowledged_at IS NOT NULL)
            )");

        /*
         * Append-only con la precisión del §8, la misma que rige
         * `bank_transactions`: el **hecho monetario** —importe, medio,
         * fecha, depositante, evento— no se edita nunca. Los marcadores
         * administrativos sí, y cada cambio queda en `audit_events`.
         *
         * Los datos del cheque quedan del lado mutable en un solo punto:
         * `cheque_status`, porque el papel se mueve. Su número, banco y
         * fecha son del hecho.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.medium IS DISTINCT FROM OLD.medium
                    OR NEW.received_date IS DISTINCT FROM OLD.received_date
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                    OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                    OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                THEN
                    RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER fund_receipts_append_only
            BEFORE UPDATE OR DELETE ON fund_receipts
            FOR EACH ROW EXECUTE FUNCTION fund_receipts_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS fund_receipts_append_only ON fund_receipts');
        DB::statement('DROP FUNCTION IF EXISTS fund_receipts_append_only()');

        Schema::dropIfExists('fund_receipts');
    }
};
