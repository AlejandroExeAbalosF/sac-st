<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos de auditoría del dominio — §9.10 del DER.
 *
 * Responde «quién cambió esto y cuándo». Llega ahora y no más adelante
 * porque la edición de cuotas ya está habilitada: se puede corregir el
 * importe de una cuota, su etiqueta y su medio, y hasta este momento eso
 * no dejaba ningún rastro.
 *
 * La auditoría NO sustituye los eventos monetarios. Un asiento contable es
 * el hecho; esto es el registro de quién lo provocó. Cuando exista
 * `journal_lines`, esa sigue siendo la fuente de verdad de la plata.
 *
 * Append-only, impuesto por trigger: un registro de auditoría que se puede
 * editar no es un registro de auditoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();

            // Nulo a propósito: hay cambios que no provoca una persona —una
            // importación, un comando programado—, y perder el evento por
            // no tener a quién atribuirlo sería peor.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 60);
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            /*
             * Solo lo que cambió, no la fila entera. Guardar el registro
             * completo en cada edición hace que encontrar el cambio real
             * sea el trabajo, y multiplica el peso de la tabla que más
             * crece del sistema.
             */
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            // La pregunta habitual es «qué pasó con este expediente»,
            // así que el índice va por sujeto y en orden inverso de tiempo.
            $table->index(['subject_type', 'subject_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });

        /*
         * Append-only. Es el mismo criterio que ya rige sobre
         * `user_login_events`: si la auditoría se puede reescribir, no
         * prueba nada.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_audit_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Los eventos de auditoría no se modifican ni se borran.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER audit_events_append_only
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION forbid_audit_mutation()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_events_append_only ON audit_events');
        DB::statement('DROP FUNCTION IF EXISTS forbid_audit_mutation()');

        Schema::dropIfExists('audit_events');
    }
};
