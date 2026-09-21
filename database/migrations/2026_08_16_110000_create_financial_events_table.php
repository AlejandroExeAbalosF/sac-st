<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos financieros — §9.4 del DER.
 *
 * **El hecho monetario.** Todo lo que mueve dinero en el sistema es un
 * evento: una recepción, una asignación, un egreso, un traslado. Cada uno
 * lleva su asiento en `journal_lines`, y ninguno se edita después
 * (invariante 15).
 *
 * `Ledger` no sabe que existen expedientes ni cuotas. Un evento conoce su
 * caja y nada más del dominio: eso es lo que permite que Aranceles y
 * Multas reutilicen este motor sin arrastrar el modelo jurídico de
 * Haberes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_events', function (Blueprint $table): void {
            $table->id();

            /*
             * Identificador público. Un ULID en vez del `id`: los eventos
             * van a aparecer en comprobantes y consultas externas, y una
             * secuencia revela cuántas operaciones hace el área.
             */
            $table->ulid('public_id')->unique();

            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->restrictOnDelete();

            $table->string('event_type', 40);
            $table->date('event_date');
            $table->string('status', 20)->default('draft');

            /** Una reversión no borra el original: lo resta explícitamente. */
            $table->foreignId('reversal_of_id')->nullable()->constrained('financial_events')->restrictOnDelete();
            $table->string('reversal_reason', 300)->nullable();

            /*
             * La llave que impide postear dos veces el mismo hecho.
             *
             * El escenario es banal y el daño no: el operador hace doble
             * clic en «Registrar recepción» y el sistema asienta el mismo
             * crédito bancario dos veces. Con la clave derivada del hecho
             * —no del click— el segundo intento choca contra el índice.
             */
            $table->string('idempotency_key', 120)->unique();

            $table->string('description', 300)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->index(['cash_box_id', 'event_date']);
            $table->index(['event_type', 'status']);
        });

        DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
            CHECK (event_type IN (
                'funds_received', 'funds_allocated', 'cash_disbursement',
                'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                'cash_adjustment', 'opening_balance', 'legacy_disbursement',
                'reversal', 'authorized_adjustment'
            ))");
        DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_status_check
            CHECK (status IN ('draft', 'posted', 'reversed'))");
        /*
         * Un evento posteado tiene fecha de posteo, y uno en borrador no.
         *
         * El autor **no** se exige, y es deliberado: hay eventos que no
         * provoca una persona —la migración de los saldos históricos, un
         * proceso programado— y perderlos por no tener a quién
         * atribuirlos sería peor. Es el mismo criterio que ya toma
         * `audit_events.user_id`. Cuando hay alguien detrás, el Action lo
         * registra.
         */
        DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_posted_check
            CHECK ((status = 'draft') = (posted_at IS NULL))");
        DB::statement("ALTER TABLE financial_events ADD CONSTRAINT financial_events_reversal_check
            CHECK ((event_type = 'reversal') = (reversal_of_id IS NOT NULL))");
        DB::statement('ALTER TABLE financial_events ADD CONSTRAINT financial_events_reversal_reason_check
            CHECK (reversal_of_id IS NULL OR reversal_reason IS NOT NULL)');

        /*
         * Append-only, con la precisión de §9.4: lo que no se toca nunca
         * es el hecho —tipo, fecha, importe, caja, idempotencia—. El
         * estado sí cambia, porque un evento nace en borrador, se postea y
         * eventualmente se revierte, y cada paso queda en `audit_events`.
         *
         * Es el mismo criterio que ya rige `bank_transactions`.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un hecho monetario no se borra: se revierte con otro evento.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.public_id IS DISTINCT FROM OLD.public_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.event_type IS DISTINCT FROM OLD.event_type
                    OR NEW.event_date IS DISTINCT FROM OLD.event_date
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id
                THEN
                    RAISE EXCEPTION 'Los datos de un hecho monetario no se editan.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Un evento posteado no vuelve a borrador: ese camino es la
                -- reversion, que deja rastro.
                IF OLD.status = 'posted' AND NEW.status = 'draft' THEN
                    RAISE EXCEPTION 'Un evento posteado no vuelve a borrador: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER financial_events_append_only
            BEFORE UPDATE OR DELETE ON financial_events
            FOR EACH ROW EXECUTE FUNCTION financial_events_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS financial_events_append_only ON financial_events');
        DB::statement('DROP FUNCTION IF EXISTS financial_events_append_only()');

        Schema::dropIfExists('financial_events');
    }
};
