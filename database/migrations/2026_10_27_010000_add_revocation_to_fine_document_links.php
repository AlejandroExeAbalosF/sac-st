<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un vínculo de documento con multa se anula; no se borra.
 *
 * Un documento vinculado por error —una resolución que era de otra multa, un
 * acta con el papel equivocado— se saca de la multa anulando el vínculo, con
 * motivo y autor. El vínculo queda a la vista como anulado y el documento
 * sigue en el expediente.
 *
 * - La anulación vive en la misma fila: es lo único que admite el vínculo
 *   después de nacer (`fine_document_links_revoke_only`), como la fecha en un
 *   movimiento de deuda. Una vez anulado, no vuelve.
 * - Por eso el vínculo pasa a tener `id` propio, y lo que antes era la clave
 *   —multa, documento, papel— es único solo entre los vigentes: un documento
 *   desvinculado por error se puede volver a vincular.
 * - No se anula el último vínculo de un documento que respalda un movimiento
 *   vigente de la obligación —el importe original o un ajuste sin revertir—,
 *   y un movimiento nuevo no cita un documento con el vínculo anulado.
 * - La resolución de origen única cuenta solo los vínculos vigentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TRIGGER fine_document_links_append_only ON fine_document_links');
        DB::statement('ALTER TABLE fine_document_links DROP CONSTRAINT fine_document_links_pkey');

        Schema::table('fine_document_links', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revocation_reason', 500)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX fine_document_links_active_unique
            ON fine_document_links (fine_id, fine_document_id, role) WHERE revoked_at IS NULL');

        // Anulado es con fecha y motivo; vigente, sin nada de la anulación.
        DB::statement("ALTER TABLE fine_document_links ADD CONSTRAINT fine_document_links_revocation_check
            CHECK (
                (revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL)
                OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL AND btrim(revocation_reason) <> '')
            )");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_document_links_revoke_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'El vinculo % ya esta anulado.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.revoked_at IS NULL
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason') THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se edita: solo se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Ordena la anulacion con un ajuste simultaneo de la misma multa.
                PERFORM 1 FROM fines WHERE id = OLD.fine_id FOR UPDATE;

                IF NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = OLD.fine_id
                       AND fine_document_id = OLD.fine_document_id
                       AND revoked_at IS NULL
                       AND id <> OLD.id
                ) AND EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = OLD.fine_id
                       AND e.fine_document_id = OLD.fine_document_id
                       AND e.kind <> 'reversal'
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                ) THEN
                    RAISE EXCEPTION 'El documento % respalda un movimiento vigente de la obligacion de la multa %: no se desvincula.', OLD.fine_document_id, OLD.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_document_links_revoke_only
                BEFORE UPDATE OR DELETE ON fine_document_links
                FOR EACH ROW EXECUTE FUNCTION fine_document_links_revoke_only();

            CREATE OR REPLACE FUNCTION fine_debt_entries_cite_active_link() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = NEW.fine_id
                       AND fine_document_id = NEW.fine_document_id
                       AND revoked_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'El documento que respalda el movimiento tiene el vinculo con la multa anulado.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_debt_entries_cite_active_link
                BEFORE INSERT ON fine_debt_entries
                FOR EACH ROW EXECUTE FUNCTION fine_debt_entries_cite_active_link();

            CREATE OR REPLACE FUNCTION fine_document_links_single_origin_resolution() RETURNS trigger AS $$
            BEGIN
                IF NEW.role <> 'origin'
                   OR (SELECT kind FROM fine_documents WHERE id = NEW.fine_document_id) <> 'resolution' THEN
                    RETURN NEW;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('fine_document_links.origin_resolution'), NEW.fine_id::integer);

                IF EXISTS (
                    SELECT 1
                      FROM fine_document_links l
                      JOIN fine_documents d ON d.id = l.fine_document_id
                     WHERE l.fine_id = NEW.fine_id
                       AND l.role = 'origin'
                       AND l.revoked_at IS NULL
                       AND d.kind = 'resolution'
                       AND l.fine_document_id <> NEW.fine_document_id
                ) THEN
                    RAISE EXCEPTION 'La multa % ya tiene su resolucion de origen: otra resolucion se vincula como modificacion o ratificacion.', NEW.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        if (DB::table('fine_document_links')->whereNotNull('revoked_at')->exists()) {
            throw new RuntimeException('Hay vínculos anulados: volver atrás los mostraría como vigentes.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER fine_debt_entries_cite_active_link ON fine_debt_entries;
            DROP FUNCTION fine_debt_entries_cite_active_link();
            DROP TRIGGER fine_document_links_revoke_only ON fine_document_links;
            DROP FUNCTION fine_document_links_revoke_only();
            DROP INDEX fine_document_links_active_unique;
        SQL);

        Schema::table('fine_document_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn(['id', 'revoked_at', 'revocation_reason']);
        });

        DB::statement('ALTER TABLE fine_document_links DROP CONSTRAINT IF EXISTS fine_document_links_revocation_check');
        DB::statement('ALTER TABLE fine_document_links ADD PRIMARY KEY (fine_id, fine_document_id, role)');
        DB::statement('CREATE TRIGGER fine_document_links_append_only
            BEFORE UPDATE OR DELETE ON fine_document_links
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_document_links_single_origin_resolution() RETURNS trigger AS $$
            BEGIN
                IF NEW.role <> 'origin'
                   OR (SELECT kind FROM fine_documents WHERE id = NEW.fine_document_id) <> 'resolution' THEN
                    RETURN NEW;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('fine_document_links.origin_resolution'), NEW.fine_id::integer);

                IF EXISTS (
                    SELECT 1
                      FROM fine_document_links l
                      JOIN fine_documents d ON d.id = l.fine_document_id
                     WHERE l.fine_id = NEW.fine_id
                       AND l.role = 'origin'
                       AND d.kind = 'resolution'
                       AND l.fine_document_id <> NEW.fine_document_id
                ) THEN
                    RAISE EXCEPTION 'La multa % ya tiene su resolucion de origen: otra resolucion se vincula como modificacion o ratificacion.', NEW.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
