<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un adjunto anulado se puede eliminar definitivamente.
 *
 * Es la salida para lo que no debería quedar guardado —la nota médica de
 * otra persona subida por error—. Por eso exige dos pasos: primero se
 * anula, con su motivo, y recién un anulado se elimina, con otro motivo y
 * solo el administrador (`adjuntos.eliminar`, que no se delega).
 *
 * La base impone:
 * - que solo se borre por `purge_attachment()`, y solo un anulado de la
 *   lista de anulables: un DELETE directo sigue rechazado, porque
 *   `sacst_app` no es dueño de la tabla;
 * - que el borrado del archivo quede anotado en la misma transacción, en
 *   `attachment_file_deletions`, y que lo anote **la propia función** a
 *   partir de la fila que elimina: una tarea no se inserta para un archivo
 *   que todavía usa algún adjunto, y si otro adjunto comparte el archivo no
 *   se anota ninguna. Si el disco falla o el proceso se corta después del
 *   commit, la tarea queda pendiente y se reintenta. La fila solo admite
 *   registrar intentos y el cierre, nunca borrarse;
 * - que `adjuntos.eliminar` no se otorgue a otro rol que el administrador.
 */
return new class extends Migration
{
    /** La lista de `2026_11_04_010000_add_verified_payment_corrections`. */
    private const NO_DELEGABLES = [
        'roles.gestionar',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.desactivar',
        'usuarios.restablecer-password',
        'multas.corregir-pagos',
    ];

    public function up(): void
    {
        Schema::create('attachment_file_deletions', function (Blueprint $table): void {
            $table->id();
            // Sin FK: el adjunto ya no existe cuando esto se procesa.
            $table->unsignedBigInteger('attachment_id');
            $table->string('disk', 40);
            $table->string('object_key', 500);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
            // El archivo no se borró porque al procesarlo lo usaba un adjunto.
            $table->timestampTz('kept_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->index(['deleted_at', 'kept_at']);
        });

        // Una tarea termina una sola vez: borrado o conservado, no las dos.
        DB::statement('ALTER TABLE attachment_file_deletions ADD CONSTRAINT attachment_file_deletions_one_outcome
            CHECK (deleted_at IS NULL OR kept_at IS NULL)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachment_file_deletions_track_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una tarea de borrado no se borra: es el rastro de un archivo eliminado.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Solo se anotan los intentos y, una vez, cómo terminó.
                IF (to_jsonb(NEW) - ARRAY['attempts', 'last_error', 'deleted_at', 'kept_at'])
                     <> (to_jsonb(OLD) - ARRAY['attempts', 'last_error', 'deleted_at', 'kept_at'])
                   OR NEW.attempts < OLD.attempts
                   OR ((OLD.deleted_at IS NOT NULL OR OLD.kept_at IS NOT NULL)
                       AND (NEW.deleted_at IS DISTINCT FROM OLD.deleted_at OR NEW.kept_at IS DISTINCT FROM OLD.kept_at)) THEN
                    RAISE EXCEPTION 'Una tarea de borrado solo registra sus intentos y cómo terminó.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER attachment_file_deletions_track_only
                BEFORE UPDATE OR DELETE ON attachment_file_deletions
                FOR EACH ROW EXECUTE FUNCTION attachment_file_deletions_track_only();

            -- Un archivo que todavía usa algún adjunto no se manda a borrar.
            CREATE OR REPLACE FUNCTION attachment_file_deletions_only_unused() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM attachments
                    WHERE storage_disk = NEW.disk AND object_key = NEW.object_key
                ) THEN
                    RAISE EXCEPTION 'Ese archivo lo usa un adjunto: no se manda a borrar.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER attachment_file_deletions_only_unused
                BEFORE INSERT ON attachment_file_deletions
                FOR EACH ROW EXECUTE FUNCTION attachment_file_deletions_only_unused();

            -- Devuelve la tarea de borrado del archivo, o NULL si otro adjunto lo comparte.
            CREATE OR REPLACE FUNCTION purge_attachment(p_attachment_id bigint, p_requested_by bigint) RETURNS bigint
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_adjunto attachments%ROWTYPE;
                v_tarea bigint;
            BEGIN
                SELECT * INTO v_adjunto FROM attachments WHERE id = p_attachment_id FOR UPDATE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'El adjunto % no existe.', p_attachment_id
                        USING ERRCODE = 'no_data_found';
                END IF;

                IF v_adjunto.voided_at IS NULL THEN
                    RAISE EXCEPTION 'Solo se elimina un adjunto anulado: primero se anula, con su motivo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NOT attachment_is_voidable(v_adjunto.subject_type, v_adjunto.document_type, v_adjunto.source) THEN
                    RAISE EXCEPTION 'Este adjunto no se elimina: es la prueba de un hecho.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                PERFORM set_config('sacst.allow_attachment_delete', 'on', true);
                DELETE FROM attachments WHERE id = p_attachment_id;
                PERFORM set_config('sacst.allow_attachment_delete', 'off', true);

                -- La tarea sale de la fila recién eliminada, no de lo que diga quien llama.
                IF NOT EXISTS (
                    SELECT 1 FROM attachments
                    WHERE storage_disk = v_adjunto.storage_disk AND object_key = v_adjunto.object_key
                ) THEN
                    INSERT INTO attachment_file_deletions (attachment_id, disk, object_key, requested_by)
                    VALUES (v_adjunto.id, v_adjunto.storage_disk, v_adjunto.object_key, p_requested_by)
                    RETURNING id INTO v_tarea;
                END IF;

                RETURN v_tarea;
            END;
            $$;
            SQL);

        $this->guardarNoDelegables([...self::NO_DELEGABLES, 'adjuntos.eliminar']);
    }

    public function down(): void
    {
        $this->guardarNoDelegables(self::NO_DELEGABLES);

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS purge_attachment(bigint, bigint);
            DROP TRIGGER IF EXISTS attachment_file_deletions_only_unused ON attachment_file_deletions;
            DROP FUNCTION IF EXISTS attachment_file_deletions_only_unused();
            DROP TRIGGER IF EXISTS attachment_file_deletions_track_only ON attachment_file_deletions;
            DROP FUNCTION IF EXISTS attachment_file_deletions_track_only();
            SQL);

        Schema::dropIfExists('attachment_file_deletions');
    }

    /**
     * La guarda de `2026_10_12_010000` con la lista de permisos no delegables.
     *
     * @param  list<string>  $reservados
     */
    private function guardarNoDelegables(array $reservados): void
    {
        $lista = implode(', ', array_map(fn (string $v): string => "'{$v}'", $reservados));

        DB::statement(str_replace('{reservados}', $lista, <<<'SQL'
            CREATE OR REPLACE FUNCTION role_has_permissions_guard_non_delegable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name IN ({reservados}))
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name IN ('administrador', 'super-admin')) THEN
                    RAISE EXCEPTION 'Este permiso no se delega: lo tiene solo el administrador.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL));
    }
};
