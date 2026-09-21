<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de accesos — append-only.
 *
 * Es el registro de quién entró, desde dónde y cuándo. En un sistema que
 * mueve plata de terceros esto no es telemetría: es la evidencia de que la
 * contadora que validó un egreso el martes a las 14:32 era efectivamente
 * ella. Por eso la tabla no se edita ni se borra, y el trigger lo impone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_events', function (Blueprint $table): void {
            $table->id();

            // Nullable: un intento fallido contra un usuario inexistente no
            // tiene a quién referenciar, y ese intento es justamente el que
            // más interesa conservar.
            //
            // `restrict` y no `nullOnDelete`: un usuario con historial no se
            // borra, se marca inactivo. Poner el vínculo en NULL al eliminarlo
            // sería perder la atribución, que es justamente lo que esta tabla
            // existe para garantizar.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Lo que se tipeó en el formulario, aunque no exista. Permite
            // correlacionar los intentos de un mismo atacante.
            $table->string('username_attempted', 60)->nullable();

            $table->string('event_type', 40);
            $table->string('failure_reason', 60)->nullable();

            // FK lógica a sessions.id, no real: sessions se vacía al cerrar
            // sesión y acá el histórico tiene que sobrevivir.
            $table->string('session_id')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Resumen legible del user agent: "Chrome 138 / Windows 11".
            $table->string('device_label', 150)->nullable();

            $table->jsonb('meta')->nullable();

            // Sin updated_at: la fila nace y no cambia nunca.
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('event_type');
            $table->index('created_at');
            $table->index('ip_address');
            $table->index('session_id');
        });

        DB::statement("
            ALTER TABLE user_login_events ADD CONSTRAINT user_login_events_type_check
            CHECK (event_type IN (
                'login_success', 'login_failed', 'logout',
                'password_reset', 'password_changed',
                'two_factor_challenged', 'two_factor_failed',
                'session_revoked', 'lockout'
            ))
        ");

        // Un failure_reason solo tiene sentido en un intento fallido.
        DB::statement("
            ALTER TABLE user_login_events ADD CONSTRAINT user_login_events_reason_check
            CHECK (failure_reason IS NULL OR event_type IN ('login_failed', 'two_factor_failed', 'lockout'))
        ");

        $this->makeAppendOnly('user_login_events');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS user_login_events_append_only ON user_login_events');
        Schema::dropIfExists('user_login_events');
        DB::statement('DROP FUNCTION IF EXISTS forbid_mutation()');
    }

    /**
     * Bloquea UPDATE y DELETE a nivel de motor.
     *
     * La función es genérica a propósito: las tablas de hechos monetarios
     * de la Fase 4 (journal_lines, financial_events, receipts) van a
     * reutilizar exactamente este trigger.
     */
    private function makeAppendOnly(string $table): void
    {
        DB::statement("
            CREATE OR REPLACE FUNCTION forbid_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'La tabla % es append-only: no admite % .', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        ");

        DB::statement("
            CREATE TRIGGER {$table}_append_only
            BEFORE UPDATE OR DELETE ON {$table}
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation();
        ");
    }
};
