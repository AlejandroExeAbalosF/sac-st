<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrección de descripción, anulación y eliminación controlada de adjuntos.
 */
return new class extends Migration
{
    /**
     * Los pares anulables. Igual que `Attachment::VOIDABLE`: un test los
     * compara.
     */
    private const VOIDABLE = [
        ['fine_case', 'fine_case_paper'],
        ['fine_document', 'fine_document_paper'],
        ['fine', 'fine_support'],
        ['fine_payment', 'fine_payment_evidence'],
        ['fine_historical_movement', 'fine_historical_evidence'],
        ['receipt', 'talonario_photo'],
        ['deposit_ticket', 'deposit_ticket'],
        ['legacy_document', 'legacy_income_receipt'],
        ['legacy_document', 'legacy_payment_order'],
        ['legacy_document', 'legacy_expense_receipt'],
        ['disbursement', 'transfer_report'],
    ];

    /** Los motivos de `SharedReasonAct::VoidAttachment`. */
    private const VOID_REASONS = ['wrong_file', 'wrong_subject', 'illegible', 'duplicate', 'sensitive_data', 'other'];

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
        Schema::table('attachments', function (Blueprint $table): void {
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason_code', 40)->nullable();
            $table->string('void_reason', 500)->nullable();
        });

        $motivos = implode(', ', array_map(static fn (string $codigo): string => "'{$codigo}'", self::VOID_REASONS));

        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_void_complete CHECK (
            (voided_at IS NULL AND voided_by IS NULL AND void_reason_code IS NULL AND void_reason IS NULL)
            OR (
                voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason_code IS NOT NULL
                AND (void_reason_code <> 'other' OR btrim(coalesce(void_reason, '')) <> '')
            )
        )");
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_void_reason_code_check
            CHECK (void_reason_code IS NULL OR void_reason_code IN ({$motivos}))");

        $pares = implode(",\n                    ", array_map(
            static fn (array $par): string => "('{$par[0]}', '{$par[1]}')",
            self::VOIDABLE,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION attachment_is_voidable(p_subject_type text, p_document_type text, p_source text)
            RETURNS boolean LANGUAGE sql IMMUTABLE AS \$\$
                SELECT p_source <> 'generated' AND (p_subject_type, p_document_type) IN (
                    {$pares}
                );
            \$\$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachments_born_current() RETURNS trigger AS $$
            BEGIN
                IF NEW.voided_at IS NOT NULL OR NEW.voided_by IS NOT NULL
                   OR NEW.void_reason_code IS NOT NULL OR NEW.void_reason IS NOT NULL THEN
                    RAISE EXCEPTION 'Un adjunto nace vigente: se anula después, con su motivo.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER attachments_born_current
                BEFORE INSERT ON attachments
                FOR EACH ROW EXECUTE FUNCTION attachments_born_current();

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Renombrar: cambia la descripción y ninguna otra columna.
                IF (to_jsonb(NEW) - 'title') = (to_jsonb(OLD) - 'title') THEN
                    IF OLD.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Un adjunto anulado no se renombra.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF btrim(NEW.title) = '' THEN
                        RAISE EXCEPTION 'Un adjunto no se queda sin descripción.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                -- Anular: se completan los datos de la anulación, una vez, y nada más.
                IF OLD.voided_at IS NULL AND NEW.voided_at IS NOT NULL
                   AND (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason_code', 'void_reason'])
                     = (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason_code', 'void_reason']) THEN
                    IF NOT attachment_is_voidable(OLD.subject_type, OLD.document_type, OLD.source) THEN
                        RAISE EXCEPTION 'Este adjunto no se anula: es la prueba de un hecho, no un respaldo opcional.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

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

        DB::statement('DROP FUNCTION IF EXISTS attachment_is_voidable(text, text, text)');
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_void_reason_code_check');
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_void_complete');

        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason_code', 'void_reason']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);
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
