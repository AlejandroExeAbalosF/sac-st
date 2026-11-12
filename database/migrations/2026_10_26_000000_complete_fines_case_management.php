<?php

declare(strict_types=1);

use App\Support\Text\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidación cronológica del proceso. Cada bloque conserva la migración
 * original para mantener visibles sus reglas y permitir el rollback inverso.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->migrations() as $migration) {
            $this->run($migration, 'up');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->migrations()) as $migration) {
            $this->run($migration, 'down');
        }
    }

    private function run(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];

        if (! is_callable($callback)) {
            throw new LogicException("La migración consolidada no tiene {$method}().");
        }

        $callback();
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        return [
            /**
             * El documento de Multas como dueño de su archivo.
             *
             * La foto de una resolución o de un acta colgaba del expediente, y con tres
             * actas nada decía cuál era la de cuál. Ahora el archivo va al documento;
             * como el documento se comparte entre expedientes y multas, el archivo se ve
             * en todos sin duplicarse. Lo que ya se subió al expediente queda donde
             * está: un adjunto no se mueve.
             */
            // Migración original: 2026_10_26_000000_add_fine_document_attachment_subject.php
            new class extends Migration
            {
                private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'fine_payment', 'cheque_rejection'";

                public function up(): void
                {
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'fine_document'))");
                }

                public function down(): void
                {
                    if (DB::table('attachments')->where('subject_type', 'fine_document')->exists()) {
                        throw new RuntimeException(
                            'Hay archivos adjuntos a documentos de Multas: decidí qué hacer con ellos antes de volver atrás.',
                        );
                    }

                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');
                }
            },

            /**
             * El número de la multa dentro de su expediente: «la multa 2 del 169607».
             *
             * Es lo que va en la dirección —`/multas/expedientes/12/169607-2024-0/multa/2`—
             * en lugar del id global, que no le dice nada a nadie, y de la carátula, que
             * puede ser el nombre de una persona. Igual que el haber en Haberes.
             *
             * Lo pone la base al insertar, no el Action: el alta, la importación del Excel
             * histórico y cualquier carga futura salen numeradas sin acordarse de
             * hacerlo. El candado por expediente ordena dos altas simultáneas; el índice
             * único es la garantía de fondo. Una vez puesto no cambia: `fine_identity_guard`
             * rechaza modificar cualquier dato que no figure entre los editables.
             */
            // Migración original: 2026_10_26_010000_add_fine_number_to_fines.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fines ADD COLUMN fine_number smallint;

            -- Las multas que ya están se numeran en el orden en que se cargaron.
            ALTER TABLE fines DISABLE TRIGGER fines_identity_guard;
            UPDATE fines f
               SET fine_number = n.orden
              FROM (SELECT id, row_number() OVER (PARTITION BY fine_case_id ORDER BY id) AS orden FROM fines) n
             WHERE n.id = f.id;
            ALTER TABLE fines ENABLE TRIGGER fines_identity_guard;

            ALTER TABLE fines ALTER COLUMN fine_number SET NOT NULL;
            ALTER TABLE fines ADD CONSTRAINT fines_number_positive_check CHECK (fine_number >= 1);
            CREATE UNIQUE INDEX fines_case_number_unique ON fines (fine_case_id, fine_number);

            CREATE OR REPLACE FUNCTION fines_assign_number() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_number IS NULL THEN
                    -- Dos altas del mismo expediente no leen el mismo máximo.
                    PERFORM pg_advisory_xact_lock(hashtext('fines.fine_number'), NEW.fine_case_id::integer);

                    SELECT coalesce(max(fine_number), 0) + 1
                      INTO NEW.fine_number
                      FROM fines
                     WHERE fine_case_id = NEW.fine_case_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fines_assign_number BEFORE INSERT ON fines
                FOR EACH ROW EXECUTE FUNCTION fines_assign_number();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER fines_assign_number ON fines;
            DROP FUNCTION fines_assign_number();
            DROP INDEX fines_case_number_unique;
            ALTER TABLE fines DROP CONSTRAINT fines_number_positive_check;
            ALTER TABLE fines DROP COLUMN fine_number;
        SQL);
                }
            },

            /**
             * Una multa tiene una sola resolución de origen: la que la impone.
             *
             * Las demás resoluciones de una multa son posteriores y tienen otro papel:
             * la reducen, la revocan o la ratifican (`modification`, `ratification`).
             * Es lo que muestra el Excel histórico: 135 multas con dos resoluciones en la
             * misma celda, y en las que lo aclaran la segunda es una reducción del
             * Ministerio, un fallo de la Cámara o la corrección de un número —ninguna
             * impone la multa junto con la primera—. Sin esta regla, una segunda
             * resolución podía entrar como origen por error, y el recibo, el importe
             * original y las hojas del expediente leen «la» resolución de origen.
             *
             * Va en un trigger porque el tipo del documento vive en `fine_documents` y un
             * índice parcial no puede mirarlo. Alcanza con el alta: los vínculos son de
             * solo inserción (`fine_document_links_append_only`) y el tipo de un
             * documento no se corrige (`fine_identity_guard`). El candado por multa
             * ordena dos altas simultáneas.
             */
            // Migración original: 2026_10_27_000000_add_single_origin_resolution_to_fines.php
            new class extends Migration
            {
                public function up(): void
                {
                    $repetidas = DB::table('fine_document_links AS l')
                        ->join('fine_documents AS d', 'd.id', '=', 'l.fine_document_id')
                        ->where('l.role', 'origin')
                        ->where('d.kind', 'resolution')
                        ->groupBy('l.fine_id')
                        ->havingRaw('count(*) > 1')
                        ->pluck('l.fine_id');

                    if ($repetidas->isNotEmpty()) {
                        throw new RuntimeException(
                            'Hay multas con más de una resolución de origen ('.$repetidas->implode(', ').'): decidí cuál es la de origen antes de aplicar la regla.',
                        );
                    }

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

            CREATE TRIGGER fine_document_links_single_origin_resolution BEFORE INSERT ON fine_document_links
                FOR EACH ROW EXECUTE FUNCTION fine_document_links_single_origin_resolution();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER fine_document_links_single_origin_resolution ON fine_document_links;
            DROP FUNCTION fine_document_links_single_origin_resolution();
        SQL);
                }
            },

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
            // Migración original: 2026_10_27_010000_add_revocation_to_fine_document_links.php
            new class extends Migration
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
            },

            /**
             * El motivo de Multas se elige de una lista y se guarda como código.
             *
             * Cada acto que deshace o corrige algo ya cargado guardaba su motivo como
             * texto libre, que no se puede contar. Ahora cada columna de motivo tiene al
             * lado su código, y el texto queda como detalle:
             *
             * - el código es el de la lista de ese acto (`FineReasonAct`), y está siempre
             *   que el acto pide motivo;
             * - el detalle es opcional, salvo con «Otro»;
             * - un detalle, si está, no es solo espacios.
             *
             * Las filas que ya tenían motivo quedan en «Otro», con su texto como detalle;
             * los pagos descartados por una corrección, en «Reemplazado por su
             * corrección». Las guardas que comparan una fila entera suman la columna
             * nueva entre las que el acto puede escribir.
             *
             * El rechazo de un cheque y la cancelación de un depósito guardan su motivo
             * en tablas que también usa Haberes: van con los otros módulos.
             */
            // Migración original: 2026_10_27_020000_add_reason_codes_to_fines.php
            new class extends Migration
            {
                private const AJUSTE = "'appeal_admitted', 'court_ruling', 'resolution_correction', 'other'";

                private const REVERSION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

                private const DESCARTE = "'data_entry_error', 'duplicate', 'wrong_fine', 'not_credited', 'replaced_by_correction', 'other'";

                private const DESVINCULO = "'data_entry_error', 'wrong_document', 'wrong_fine', 'duplicate', 'other'";

                private const CORRECCION = "'data_entry_error', 'misread_paper', 'other'";

                private const RECHAZO = "'no_evidence', 'duplicate', 'wrong_fine', 'data_entry_error', 'other'";

                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_debt_entries ADD COLUMN reason_code varchar(30);
            ALTER TABLE fine_payments ADD COLUMN discard_reason_code varchar(30);
            ALTER TABLE fine_document_links ADD COLUMN revocation_reason_code varchar(30);
            ALTER TABLE fine_identifier_revisions ADD COLUMN reason_code varchar(30);
            ALTER TABLE fine_historical_movements ADD COLUMN review_reason_code varchar(30);
        SQL);

                    $this->completarLoCargado();

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status = 'discarded' THEN
                        RAISE EXCEPTION 'Un pago no nace descartado.' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'applied' AND NEW.medium NOT IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Un pago por el banco se aplica al verificar el crédito: nace informado.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'reported' AND NEW.medium IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Lo que se cobra en la caja se aplica en el acto.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.appeal_kind IS NOT NULL THEN
                        PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                        IF NEW.appeal_base_amount <> fine_obligation(NEW.fine_id) THEN
                            RAISE EXCEPTION 'La base del 20 %% es el importe vigente de la multa al registrar el recurso: % y no %.',
                                fine_obligation(NEW.fine_id), NEW.appeal_base_amount
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    -- Una corrección reemplaza a un pago de la misma multa y del mismo tipo, ya descartado.
                    IF NEW.replaces_fine_payment_id IS NOT NULL THEN
                        SELECT fine_id, status, appeal_kind IS NOT NULL AS es_recurso, presented_at, presented_precision
                          INTO v_anterior
                          FROM fine_payments WHERE id = NEW.replaces_fine_payment_id;
                        IF v_anterior.fine_id <> NEW.fine_id OR v_anterior.status <> 'discarded'
                            OR v_anterior.es_recurso <> (NEW.appeal_kind IS NOT NULL) THEN
                            RAISE EXCEPTION 'Una corrección reemplaza a un pago descartado de la misma multa y del mismo tipo.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                        -- Corrige los datos de un informado: nace informada y conserva su
                        -- presentación, que es lo que decide el término.
                        IF NEW.status <> 'reported'
                            OR NEW.presented_at IS DISTINCT FROM v_anterior.presented_at
                            OR NEW.presented_precision IS DISTINCT FROM v_anterior.presented_precision THEN
                            RAISE EXCEPTION 'Una corrección nace informada y conserva la presentación del pago que reemplaza.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded')) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason, NEW.discard_reason_code)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason, OLD.discard_reason_code) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_historical_review_once() RETURNS trigger AS $$
            BEGIN
                -- Completar o corregir la fecha desde una revision, en cualquier
                -- estado: no cambia lo que el antecedente aporta al saldo.
                IF pg_trigger_depth() > 1
                   AND (to_jsonb(NEW) - 'occurred_on' - 'occurred_precision')
                       IS NOT DISTINCT FROM (to_jsonb(OLD) - 'occurred_on' - 'occurred_precision') THEN
                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending_review' THEN
                    RAISE EXCEPTION 'El antecedente ya se reviso: queda como esta.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note' - 'review_reason_code')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note' - 'review_reason_code') THEN
                    RAISE EXCEPTION 'Revisar un antecedente no modifica sus datos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

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
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code') THEN
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
        SQL);

                    $ajuste = self::AJUSTE;
                    $reversion = self::REVERSION;
                    $descarte = self::DESCARTE;
                    $desvinculo = self::DESVINCULO;
                    $correccion = self::CORRECCION;
                    $rechazo = self::RECHAZO;

                    DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction') OR reason_code IN ({$ajuste}))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_discard_check,
                ADD CONSTRAINT fine_payments_discard_check CHECK (
                    num_nonnulls(discarded_by, discarded_at) IN (0, 2)
                    AND (status = 'discarded') = (discarded_at IS NOT NULL)
                    AND (status = 'discarded') = (discard_reason_code IS NOT NULL)
                    AND (discard_reason_code IS NULL OR discard_reason_code IN ({$descarte}))
                    AND (discard_reason IS NULL OR btrim(discard_reason) <> '')
                    AND (discard_reason IS NULL OR discard_reason_code IS NOT NULL)
                    AND (discard_reason_code NOT IN ('other', 'replaced_by_correction') OR discard_reason IS NOT NULL)
                );

            ALTER TABLE fine_document_links
                DROP CONSTRAINT fine_document_links_revocation_check,
                ADD CONSTRAINT fine_document_links_revocation_check CHECK (
                    (revoked_at IS NULL) = (revocation_reason_code IS NULL)
                    AND (revoked_at IS NOT NULL OR (revoked_by IS NULL AND revocation_reason IS NULL))
                    AND (revocation_reason_code IS NULL OR revocation_reason_code IN ({$desvinculo}))
                    AND (revocation_reason IS NULL OR btrim(revocation_reason) <> '')
                    AND (revocation_reason_code IS DISTINCT FROM 'other' OR revocation_reason IS NOT NULL)
                );

            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check CHECK (
                    (kind = 'completion') = (reason_code IS NULL)
                    AND (reason_code IS NULL OR reason_code IN ({$correccion}))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason IS NULL OR reason_code IS NOT NULL)
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_historical_movements
                DROP CONSTRAINT fine_historical_movements_rejected_note_check,
                ADD CONSTRAINT fine_historical_movements_rejected_note_check CHECK (
                    (status = 'rejected') = (review_reason_code IS NOT NULL)
                    AND (review_reason_code IS NULL OR review_reason_code IN ({$rechazo}))
                    AND (review_note IS NULL OR btrim(review_note) <> '')
                    AND (review_reason_code IS DISTINCT FROM 'other' OR review_note IS NOT NULL)
                );
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check
                    CHECK (kind = 'constitution' OR (reason IS NOT NULL AND btrim(reason) <> '')) NOT VALID;

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_discard_check,
                ADD CONSTRAINT fine_payments_discard_check CHECK (
                    num_nonnulls(discarded_by, discarded_at, discard_reason) IN (0, 3)
                    AND (status = 'discarded') = (discard_reason IS NOT NULL)
                    AND (discard_reason IS NULL OR btrim(discard_reason) <> '')
                ) NOT VALID;

            ALTER TABLE fine_document_links
                DROP CONSTRAINT fine_document_links_revocation_check,
                ADD CONSTRAINT fine_document_links_revocation_check CHECK (
                    (revoked_at IS NULL AND revoked_by IS NULL AND revocation_reason IS NULL)
                    OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL AND btrim(revocation_reason) <> '')
                ) NOT VALID;

            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check
                    CHECK (kind = 'completion' OR (reason IS NOT NULL AND btrim(reason) <> '')) NOT VALID;

            ALTER TABLE fine_historical_movements
                DROP CONSTRAINT fine_historical_movements_rejected_note_check,
                ADD CONSTRAINT fine_historical_movements_rejected_note_check
                    CHECK (status <> 'rejected' OR (review_note IS NOT NULL AND btrim(review_note) <> '')) NOT VALID;

            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status = 'discarded' THEN
                        RAISE EXCEPTION 'Un pago no nace descartado.' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'applied' AND NEW.medium NOT IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Un pago por el banco se aplica al verificar el crédito: nace informado.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'reported' AND NEW.medium IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Lo que se cobra en la caja se aplica en el acto.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.appeal_kind IS NOT NULL THEN
                        PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                        IF NEW.appeal_base_amount <> fine_obligation(NEW.fine_id) THEN
                            RAISE EXCEPTION 'La base del 20 %% es el importe vigente de la multa al registrar el recurso: % y no %.',
                                fine_obligation(NEW.fine_id), NEW.appeal_base_amount
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    -- Una corrección reemplaza a un pago de la misma multa y del mismo tipo, ya descartado.
                    IF NEW.replaces_fine_payment_id IS NOT NULL THEN
                        SELECT fine_id, status, appeal_kind IS NOT NULL AS es_recurso, presented_at, presented_precision
                          INTO v_anterior
                          FROM fine_payments WHERE id = NEW.replaces_fine_payment_id;
                        IF v_anterior.fine_id <> NEW.fine_id OR v_anterior.status <> 'discarded'
                            OR v_anterior.es_recurso <> (NEW.appeal_kind IS NOT NULL) THEN
                            RAISE EXCEPTION 'Una corrección reemplaza a un pago descartado de la misma multa y del mismo tipo.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                        -- Corrige los datos de un informado: nace informada y conserva su
                        -- presentación, que es lo que decide el término.
                        IF NEW.status <> 'reported'
                            OR NEW.presented_at IS DISTINCT FROM v_anterior.presented_at
                            OR NEW.presented_precision IS DISTINCT FROM v_anterior.presented_precision THEN
                            RAISE EXCEPTION 'Una corrección nace informada y conserva la presentación del pago que reemplaza.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded')) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_historical_review_once() RETURNS trigger AS $$
            BEGIN
                -- Completar o corregir la fecha desde una revision, en cualquier
                -- estado: no cambia lo que el antecedente aporta al saldo.
                IF pg_trigger_depth() > 1
                   AND (to_jsonb(NEW) - 'occurred_on' - 'occurred_precision')
                       IS NOT DISTINCT FROM (to_jsonb(OLD) - 'occurred_on' - 'occurred_precision') THEN
                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending_review' THEN
                    RAISE EXCEPTION 'El antecedente ya se reviso: queda como esta.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note') THEN
                    RAISE EXCEPTION 'Revisar un antecedente no modifica sus datos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

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

            ALTER TABLE fine_debt_entries DROP COLUMN reason_code;
            ALTER TABLE fine_payments DROP COLUMN discard_reason_code;
            ALTER TABLE fine_document_links DROP COLUMN revocation_reason_code;
            ALTER TABLE fine_identifier_revisions DROP COLUMN reason_code;
            ALTER TABLE fine_historical_movements DROP COLUMN review_reason_code;
        SQL);
                }

                /**
                 * Lo que ya tenía motivo queda en «Otro», con su texto como detalle.
                 *
                 * Son tablas que no admiten cambios —justamente por eso—: el relleno corre
                 * con sus guardas apagadas, dentro de la migración y como dueño de la
                 * tabla, y no toca ninguna otra columna.
                 */
                private function completarLoCargado(): void
                {
                    $tablas = ['fine_debt_entries', 'fine_payments', 'fine_document_links', 'fine_identifier_revisions', 'fine_historical_movements'];

                    foreach ($tablas as $tabla) {
                        DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
                    }

                    DB::unprepared(<<<'SQL'
            UPDATE fine_debt_entries SET reason_code = 'other' WHERE kind <> 'constitution';

            UPDATE fine_payments
               SET discard_reason_code = 'replaced_by_correction',
                   discard_reason = COALESCE(NULLIF(btrim(substr(discard_reason, length('Corregido:') + 1)), ''), discard_reason)
             WHERE status = 'discarded' AND discard_reason LIKE 'Corregido:%';
            UPDATE fine_payments SET discard_reason_code = 'other'
             WHERE status = 'discarded' AND discard_reason_code IS NULL;

            UPDATE fine_document_links SET revocation_reason_code = 'other' WHERE revoked_at IS NOT NULL;
            UPDATE fine_identifier_revisions SET reason_code = 'other' WHERE kind = 'correction';
            UPDATE fine_historical_movements SET review_reason_code = 'other' WHERE status = 'rejected';
        SQL);

                    foreach ($tablas as $tabla) {
                        DB::statement("ALTER TABLE {$tabla} ENABLE TRIGGER USER");
                    }
                }
            },

            /**
             * El motivo de Haberes se elige de una lista y se guarda como código, como
             * en Multas (`2026_10_27_020000_add_reason_codes_to_fines`).
             *
             * Cada columna de motivo de Haberes tiene al lado su código y el texto queda
             * como detalle:
             *
             * - el código es el de la lista de ese acto (`HaberesReasonAct`), y está
             *   siempre que el acto registró un motivo;
             * - el detalle es opcional, salvo con «Otro»;
             * - un detalle, si está, no es solo espacios.
             *
             * Un recibo de papel que cae porque se anuló el pago fuera del circuito al
             * que pertenece no repite el motivo del pago —su lista es otra—: lleva
             * «Se anuló el pago que respaldaba», que solo admite un papel de un pago. El
             * motivo de fondo queda en el pago.
             *
             * Lo ya cargado queda en «Otro», con su texto como detalle. La guarda de los
             * registros del sistema anterior suma la columna nueva entre las que anular
             * puede escribir.
             *
             * Anular y reactivar un expediente o un haber no tienen columna: el motivo
             * queda en la auditoría, con su código.
             */
            // Migración original: 2026_10_27_030000_add_reason_codes_to_haberes.php
            new class extends Migration
            {
                private const ORDEN = "'wrong_beneficiary_data', 'wrong_amount', 'wrong_installment', 'saf_observation', 'other'";

                private const VENTANA = "'saf_observation', 'data_entry_error', 'resolution_changed', 'other'";

                private const PAGO_ANTERIOR = "'wrong_installment', 'wrong_amount', 'duplicate', 'data_entry_error', 'other'";

                private const PAPEL = "'mistyped_number', 'wrong_installment', 'duplicate', 'other'";

                private const COMPROBANTE = "'deposit_not_made', 'wrong_receipt', 'wrong_expediente', 'duplicate', 'other'";

                private const TABLAS = ['payment_orders', 'beneficiary_installments', 'legacy_settlements', 'legacy_documents', 'deposit_tickets'];

                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE payment_orders ADD COLUMN rejection_or_void_reason_code varchar(30);
            ALTER TABLE beneficiary_installments ADD COLUMN edit_unlock_reason_code varchar(30);
            ALTER TABLE legacy_settlements ADD COLUMN void_reason_code varchar(30);
            ALTER TABLE legacy_documents ADD COLUMN void_reason_code varchar(30);
            ALTER TABLE deposit_tickets ADD COLUMN discarded_reason_code varchar(30);
        SQL);

                    $this->completarLoCargado();

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_records_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un registro anulado del sistema anterior no se modifica.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason', 'void_reason_code'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason', 'void_reason_code']) THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se edita: se anula y se carga de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

                    $orden = self::ORDEN;
                    $ventana = self::VENTANA;
                    $pagoAnterior = self::PAGO_ANTERIOR;
                    $papel = self::PAPEL;
                    $comprobante = self::COMPROBANTE;

                    DB::unprepared(<<<SQL
            ALTER TABLE payment_orders
                DROP CONSTRAINT payment_orders_void_check,
                ADD CONSTRAINT payment_orders_void_check CHECK (
                    (status IN ('voided', 'rejected')) = (rejection_or_void_reason_code IS NOT NULL)
                    AND (status NOT IN ('voided', 'rejected') OR (voided_by IS NOT NULL AND voided_at IS NOT NULL))
                    AND (rejection_or_void_reason_code IS NULL OR rejection_or_void_reason_code IN ({$orden}))
                    AND (rejection_or_void_reason IS NULL OR btrim(rejection_or_void_reason) <> '')
                    AND (rejection_or_void_reason IS NULL OR rejection_or_void_reason_code IS NOT NULL)
                    AND (rejection_or_void_reason_code IS DISTINCT FROM 'other' OR rejection_or_void_reason IS NOT NULL)
                );

            ALTER TABLE beneficiary_installments
                DROP CONSTRAINT beneficiary_installments_edit_unlock_check,
                ADD CONSTRAINT beneficiary_installments_edit_unlock_check CHECK (
                    (edit_unlocked_at IS NULL) = (edit_unlock_reason_code IS NULL)
                    AND (edit_unlocked_at IS NOT NULL OR (edit_unlock_reason IS NULL AND edit_unlocked_by IS NULL))
                    AND (edit_unlock_reason_code IS NULL OR edit_unlock_reason_code IN ({$ventana}))
                    AND (edit_unlock_reason IS NULL OR btrim(edit_unlock_reason) <> '')
                    AND (edit_unlock_reason_code IS DISTINCT FROM 'other' OR edit_unlock_reason IS NOT NULL)
                );

            ALTER TABLE legacy_settlements
                DROP CONSTRAINT legacy_settlements_void_check,
                ADD CONSTRAINT legacy_settlements_void_check CHECK (
                    (voided_at IS NULL) = (voided_by IS NULL)
                    AND (voided_at IS NULL) = (void_reason_code IS NULL)
                    AND (void_reason IS NULL OR voided_at IS NOT NULL)
                    AND (void_reason_code IS NULL OR void_reason_code IN ({$pagoAnterior}))
                    AND (void_reason IS NULL OR btrim(void_reason) <> '')
                    AND (void_reason_code IS DISTINCT FROM 'other' OR void_reason IS NOT NULL)
                );

            ALTER TABLE legacy_documents
                DROP CONSTRAINT legacy_documents_void_check,
                ADD CONSTRAINT legacy_documents_void_check CHECK (
                    (voided_at IS NULL) = (voided_by IS NULL)
                    AND (voided_at IS NULL) = (void_reason_code IS NULL)
                    AND (void_reason IS NULL OR voided_at IS NOT NULL)
                    AND (void_reason_code IS NULL OR void_reason_code IN ({$papel})
                         OR (void_reason_code = 'settlement_voided' AND legacy_settlement_id IS NOT NULL))
                    AND (void_reason IS NULL OR btrim(void_reason) <> '')
                    AND (void_reason_code IS DISTINCT FROM 'other' OR void_reason IS NOT NULL)
                );

            ALTER TABLE deposit_tickets
                DROP CONSTRAINT deposit_tickets_discarded_check,
                ADD CONSTRAINT deposit_tickets_discarded_check CHECK (
                    (status = 'discarded') = (discarded_reason_code IS NOT NULL)
                    AND (discarded_reason IS NULL OR status = 'discarded')
                    AND (discarded_reason_code IS NULL OR discarded_reason_code IN ({$comprobante}))
                    AND (discarded_reason IS NULL OR btrim(discarded_reason) <> '')
                    AND (discarded_reason_code IS DISTINCT FROM 'other' OR discarded_reason IS NOT NULL)
                );
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE payment_orders
                DROP CONSTRAINT payment_orders_void_check,
                ADD CONSTRAINT payment_orders_void_check CHECK (
                    status NOT IN ('voided', 'rejected')
                    OR (voided_by IS NOT NULL AND voided_at IS NOT NULL
                        AND rejection_or_void_reason IS NOT NULL)
                ) NOT VALID;

            ALTER TABLE beneficiary_installments
                DROP CONSTRAINT beneficiary_installments_edit_unlock_check,
                ADD CONSTRAINT beneficiary_installments_edit_unlock_check CHECK (
                    (edit_unlocked_at IS NULL AND edit_unlock_reason IS NULL AND edit_unlocked_by IS NULL)
                    OR (edit_unlocked_at IS NOT NULL AND edit_unlock_reason IS NOT NULL)
                ) NOT VALID;

            ALTER TABLE legacy_settlements
                DROP CONSTRAINT legacy_settlements_void_check,
                ADD CONSTRAINT legacy_settlements_void_check
                    CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL)) NOT VALID;

            ALTER TABLE legacy_documents
                DROP CONSTRAINT legacy_documents_void_check,
                ADD CONSTRAINT legacy_documents_void_check
                    CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL)) NOT VALID;

            ALTER TABLE deposit_tickets
                DROP CONSTRAINT deposit_tickets_discarded_check,
                ADD CONSTRAINT deposit_tickets_discarded_check
                    CHECK ((status = 'discarded') = (discarded_reason IS NOT NULL)) NOT VALID;

            CREATE OR REPLACE FUNCTION legacy_records_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un registro anulado del sistema anterior no se modifica.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason']) THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se edita: se anula y se carga de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE payment_orders DROP COLUMN rejection_or_void_reason_code;
            ALTER TABLE beneficiary_installments DROP COLUMN edit_unlock_reason_code;
            ALTER TABLE legacy_settlements DROP COLUMN void_reason_code;
            ALTER TABLE legacy_documents DROP COLUMN void_reason_code;
            ALTER TABLE deposit_tickets DROP COLUMN discarded_reason_code;
        SQL);
                }

                /**
                 * Lo que ya tenía motivo queda en «Otro», con su texto como detalle.
                 *
                 * Son tablas con guardas que no admiten editar esas filas —justamente
                 * por eso—: el relleno corre con ellas apagadas, dentro de la migración y
                 * como dueño de la tabla, y no toca ninguna otra columna.
                 */
                private function completarLoCargado(): void
                {
                    foreach (self::TABLAS as $tabla) {
                        DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
                    }

                    DB::unprepared(<<<'SQL'
            UPDATE payment_orders SET rejection_or_void_reason_code = 'other'
             WHERE status IN ('voided', 'rejected');
            UPDATE payment_orders SET rejection_or_void_reason = 'Sin motivo registrado'
             WHERE status IN ('voided', 'rejected') AND (rejection_or_void_reason IS NULL OR btrim(rejection_or_void_reason) = '');
            UPDATE beneficiary_installments SET edit_unlock_reason_code = 'other' WHERE edit_unlocked_at IS NOT NULL;
            UPDATE legacy_settlements SET void_reason_code = 'other' WHERE voided_at IS NOT NULL;
            UPDATE legacy_documents SET void_reason_code = 'other' WHERE voided_at IS NOT NULL;
            UPDATE deposit_tickets SET discarded_reason_code = 'other' WHERE status = 'discarded';
        SQL);

                    foreach (self::TABLAS as $tabla) {
                        DB::statement("ALTER TABLE {$tabla} ENABLE TRIGGER USER");
                    }
                }
            },

            /**
             * Cada movimiento de la obligación exige que su documento conserve el papel
             * que le corresponde: el importe original, el de origen; un aumento o una
             * reducción, el de modificación.
             *
             * La guarda anterior (`2026_10_27_010000_add_revocation_to_fine_document_links`)
             * solo pedía que el documento conservara *algún* vínculo vigente. Con un
             * vínculo de respaldo vigente se podía anular el de origen de la resolución
             * del importe original, y la multa quedaba sin resolución de origen aunque su
             * importe la siguiera citando.
             *
             * - `fine_debt_entry_document_role`: el papel que exige cada tipo de
             *   movimiento. Una reversión no exige uno en particular.
             * - `fine_document_links_revoke_only`: no se anula el vínculo con el papel
             *   que exige un movimiento vigente que cita el documento, aunque haya otros.
             *   Los demás papeles del mismo documento se desvinculan.
             * - `fine_debt_entries_cite_active_link`: un movimiento nuevo cita un
             *   documento vinculado y vigente con el papel que le corresponde.
             *
             * La migración se niega a correr si alguna multa ya quedó así, y dice cuáles:
             * se reparan desde la ficha, volviendo a vincular el documento con su papel,
             * para que la reparación quede con autor.
             */
            // Migración original: 2026_10_27_040000_require_the_role_each_debt_entry_cites.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_debt_entry_document_role(p_kind varchar) RETURNS varchar AS $$
                SELECT CASE p_kind
                    WHEN 'constitution' THEN 'origin'
                    WHEN 'increase' THEN 'modification'
                    WHEN 'reduction' THEN 'modification'
                END
            $$ LANGUAGE sql IMMUTABLE;
        SQL);

                    $rotas = DB::table('fine_debt_entries AS e')
                        ->join('fines AS f', 'f.id', '=', 'e.fine_id')
                        ->whereNotNull('e.fine_document_id')
                        ->where('e.kind', '<>', 'reversal')
                        ->whereNotExists(fn ($q) => $q->from('fine_debt_entries AS r')->whereColumn('r.reversal_of_id', 'e.id'))
                        ->whereNotExists(fn ($q) => $q->from('fine_document_links AS l')
                            ->whereColumn('l.fine_id', 'e.fine_id')
                            ->whereColumn('l.fine_document_id', 'e.fine_document_id')
                            ->whereNull('l.revoked_at')
                            ->whereRaw('l.role = fine_debt_entry_document_role(e.kind)'))
                        ->orderBy('f.id')
                        ->get(['f.id', 'f.fine_case_id', 'f.fine_number', 'e.kind', 'e.fine_document_id']);

                    if ($rotas->isNotEmpty()) {
                        $detalle = $rotas
                            ->map(fn (object $r): string => sprintf(
                                'multa %d (expediente %d, multa n.º %s): su %s cita el documento %d sin el vínculo de %s',
                                $r->id,
                                $r->fine_case_id,
                                $r->fine_number,
                                $r->kind === 'constitution' ? 'importe original' : 'ajuste',
                                $r->fine_document_id,
                                $r->kind === 'constitution' ? 'origen' : 'modificación',
                            ))
                            ->implode('; ');

                        throw new RuntimeException(
                            "Hay movimientos cuyo documento perdió el papel que exigen. Volvé a vincular cada documento con su papel desde la ficha de la multa y corré la migración de nuevo. {$detalle}.",
                        );
                    }

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
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code') THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se edita: solo se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Ordena la anulacion con un ajuste simultaneo de la misma multa.
                PERFORM 1 FROM fines WHERE id = OLD.fine_id FOR UPDATE;

                -- El papel que exige un movimiento vigente no se anula, aunque el
                -- documento tenga otros vinculos: el importe original exige el
                -- de origen; un ajuste, el de modificacion.
                IF EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = OLD.fine_id
                       AND e.fine_document_id = OLD.fine_document_id
                       AND e.kind <> 'reversal'
                       AND fine_debt_entry_document_role(e.kind) = OLD.role
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                ) THEN
                    RAISE EXCEPTION 'El documento % respalda un movimiento vigente de la obligacion de la multa % con el papel %: no se desvincula.', OLD.fine_document_id, OLD.fine_id, OLD.role
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_cite_active_link() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = NEW.fine_id
                       AND fine_document_id = NEW.fine_document_id
                       AND revoked_at IS NULL
                       AND (fine_debt_entry_document_role(NEW.kind) IS NULL
                            OR role = fine_debt_entry_document_role(NEW.kind))
                ) THEN
                    RAISE EXCEPTION 'El documento que respalda el movimiento no esta vinculado a la multa con el papel que le corresponde: origen para el importe original, modificacion para un ajuste.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                public function down(): void
                {
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
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code') THEN
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

            DROP FUNCTION fine_debt_entry_document_role(varchar);
        SQL);
                }
            },

            /**
             * La resolución de origen de una multa se reemplaza, en un acto propio.
             *
             * Hasta acá no había salida para una multa cargada con la resolución
             * equivocada —otro papel, no un número mal leído—: el importe original cita
             * su resolución, no se edita, y exige que conserve el papel de origen
             * (`2026_10_27_040000_require_the_role_each_debt_entry_cites`).
             *
             * El reemplazo (`ReplaceFineOriginResolution`) hace, en una transacción:
             * una revisión que cambia el documento —y la fecha, que es la de la
             * resolución— del importe original; la anulación del origen de la
             * resolución vieja; y el vínculo de origen de la nueva. Para eso:
             *
             * - `fine_identifier_revision_apply` admite revisar `fine_document_id`, solo
             *   en el importe original;
             * - `fine_debt_entries_date_only` deja que esa revisión lo escriba; importe,
             *   tipo y reversión siguen como nacieron;
             * - `fine_debt_entries_keep_document_role`, diferido al cierre de la
             *   transacción, exige que el documento nuevo quede vinculado con el papel
             *   que el movimiento pide: entre un paso y otro del reemplazo no lo está;
             * - la revisión acepta el motivo «Documento equivocado», que es el de este
             *   acto, solo sobre un movimiento.
             */
            // Migración original: 2026_10_27_050000_allow_replacing_the_origin_resolution.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_identifier_revision_apply() RETURNS trigger AS $$
            DECLARE
                v_tabla text;
                v_id bigint;
                v_permitidas text[];
                v_actual jsonb;
                v_claves text[];
                v_nuevas text[];
                v_campo text;
                v_prefijo text;
                v_columnas text;
                v_antes_fecha text;
                v_antes_precision text;
                v_despues_fecha text;
                v_despues_precision text;
                -- Listas explicitas: con LIKE '%_on' el guion bajo es comodin y
                -- «caption» pasaria por una fecha.
                v_prefijos CONSTANT text[] := ARRAY['issued', 'registered', 'effective', 'occurred'];
                v_fechas CONSTANT text[] := ARRAY[
                    'issued_on', 'registered_on', 'effective_on', 'occurred_on',
                    'issued_precision', 'registered_precision', 'effective_precision', 'occurred_precision'
                ];
            BEGIN
                IF NEW.fine_case_id IS NOT NULL THEN
                    v_tabla := 'fine_cases';
                    v_id := NEW.fine_case_id;
                    v_permitidas := ARRAY['number', 'year', 'suffix'];
                ELSIF NEW.fine_document_id IS NOT NULL THEN
                    v_tabla := 'fine_documents';
                    v_id := NEW.fine_document_id;
                    v_permitidas := ARRAY['act_type', 'number', 'year', 'issued_on', 'issued_precision'];
                ELSIF NEW.fine_debt_entry_id IS NOT NULL THEN
                    v_tabla := 'fine_debt_entries';
                    v_id := NEW.fine_debt_entry_id;
                    v_permitidas := ARRAY['effective_on', 'effective_precision'];

                    -- El documento del importe original se reemplaza (ReplaceFineOriginResolution).
                    IF (SELECT kind FROM fine_debt_entries WHERE id = v_id) = 'constitution' THEN
                        v_permitidas := v_permitidas || ARRAY['fine_document_id'];
                    END IF;
                ELSIF NEW.fine_historical_movement_id IS NOT NULL THEN
                    v_tabla := 'fine_historical_movements';
                    v_id := NEW.fine_historical_movement_id;
                    v_permitidas := ARRAY['occurred_on', 'occurred_precision'];
                ELSE
                    v_tabla := 'fines';
                    v_id := NEW.fine_id;
                    v_permitidas := ARRAY['caption', 'person_id', 'registered_on', 'registered_precision'];
                END IF;

                EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id = $1 FOR UPDATE', v_tabla)
                    INTO v_actual USING v_id;

                SELECT array_agg(c ORDER BY c) INTO v_claves FROM jsonb_object_keys(NEW.old_values) c;
                SELECT array_agg(c ORDER BY c) INTO v_nuevas FROM jsonb_object_keys(NEW.new_values) c;

                IF v_claves IS NULL OR v_claves IS DISTINCT FROM v_nuevas THEN
                    RAISE EXCEPTION 'La revision tiene que decir el valor anterior y el nuevo de los mismos datos.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (v_claves <@ v_permitidas) THEN
                    RAISE EXCEPTION 'Esos datos de % no se revisan: %.', v_tabla, array_to_string(v_claves, ', ')
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Una fecha y su precision viajan juntas: separadas, el estado
                -- intermedio no cumpliria las CHECK de la tabla.
                FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                    IF ((v_prefijo || '_on') = ANY (v_claves)) <> ((v_prefijo || '_precision') = ANY (v_claves)) THEN
                        RAISE EXCEPTION 'La fecha % se revisa junto con su precision.', v_prefijo || '_on'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                FOREACH v_campo IN ARRAY v_claves LOOP
                    IF (v_actual -> v_campo) IS DISTINCT FROM (NEW.old_values -> v_campo) THEN
                        /*
                         * `check_violation` y no `serialization_failure`:
                         * Laravel trata ese codigo como un choque de
                         * concurrencia y lo reintenta o lo envuelve. Esto no
                         * se arregla reintentando: hay que volver a leer.
                         */
                        RAISE EXCEPTION 'El dato % cambio desde que se abrio el formulario: volve a cargarlo.', v_campo
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                IF NEW.old_values = NEW.new_values THEN
                    RAISE EXCEPTION 'La revision no cambia ningun dato.'
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 * Completar es llenar un vacio o precisar una fecha sin
                 * moverla de mes. Cualquier otra cosa es una correccion y
                 * lleva motivo.
                 *
                 * La fecha y su precision se juzgan como un par, aunque el
                 * valor de la fecha no cambie: «01/05/2026» con dia a «mayo
                 * de 2026» deja la fecha igual y pierde precision, y eso es
                 * corregir (revision del 2026-10-20, R2).
                 */
                IF NEW.kind = 'completion' THEN
                    FOREACH v_campo IN ARRAY v_claves LOOP
                        IF v_campo = ANY (v_fechas)
                           OR (NEW.old_values -> v_campo) = (NEW.new_values -> v_campo) THEN
                            CONTINUE;
                        END IF;

                        IF jsonb_typeof(NEW.old_values -> v_campo) <> 'null' THEN
                            RAISE EXCEPTION 'El dato % ya tenia valor: cambiarlo es una correccion y lleva motivo.', v_campo
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END LOOP;

                    FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                        IF NOT ((v_prefijo || '_on') = ANY (v_claves)) THEN
                            CONTINUE;
                        END IF;

                        v_antes_fecha := NEW.old_values ->> (v_prefijo || '_on');
                        v_antes_precision := NEW.old_values ->> (v_prefijo || '_precision');
                        v_despues_fecha := NEW.new_values ->> (v_prefijo || '_on');
                        v_despues_precision := NEW.new_values ->> (v_prefijo || '_precision');

                        IF v_antes_fecha IS NOT DISTINCT FROM v_despues_fecha
                           AND v_antes_precision IS NOT DISTINCT FROM v_despues_precision THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'unknown' THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'month'
                           AND v_despues_precision = 'day'
                           AND date_trunc('month', v_despues_fecha::date)::date = v_antes_fecha::date THEN
                            CONTINUE;
                        END IF;

                        RAISE EXCEPTION 'Mover o volver menos precisa una fecha es una correccion y lleva motivo.'
                            USING ERRCODE = 'check_violation';
                    END LOOP;
                END IF;

                SELECT string_agg(quote_ident(c), ', ') INTO v_columnas FROM unnest(v_claves) c;

                -- Movimientos y antecedentes no llevan `updated_at`: su historia
                -- es la propia revision.
                EXECUTE format(
                    'UPDATE %I SET (%s) = (SELECT %s FROM jsonb_populate_record(NULL::%I, $1))%s WHERE id = $2',
                    v_tabla, v_columnas, v_columnas, v_tabla,
                    CASE WHEN v_tabla IN ('fine_debt_entries', 'fine_historical_movements') THEN '' ELSE ', updated_at = now()' END
                ) USING NEW.new_values, v_id;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_date_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'La tabla fine_debt_entries es append-only: no admite DELETE.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Desde una revision: la fecha, y el documento del importe original.
                IF pg_trigger_depth() < 2
                   OR (to_jsonb(NEW) - 'effective_on' - 'effective_precision' - 'fine_document_id')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'effective_on' - 'effective_precision' - 'fine_document_id')
                   OR (NEW.fine_document_id IS DISTINCT FROM OLD.fine_document_id AND OLD.kind <> 'constitution') THEN
                    RAISE EXCEPTION 'Un movimiento de deuda no se edita: solo su fecha, y el documento del importe original, se revisan.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_keep_document_role() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_document_id IS NOT NULL
                   AND fine_debt_entry_document_role(NEW.kind) IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM fine_document_links
                        WHERE fine_id = NEW.fine_id
                          AND fine_document_id = NEW.fine_document_id
                          AND revoked_at IS NULL
                          AND role = fine_debt_entry_document_role(NEW.kind)
                   ) THEN
                    RAISE EXCEPTION 'El movimiento % cita el documento % sin vincularlo a la multa con el papel %.', NEW.id, NEW.fine_document_id, fine_debt_entry_document_role(NEW.kind)
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_debt_entries_keep_document_role
                AFTER UPDATE OF fine_document_id ON fine_debt_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fine_debt_entries_keep_document_role();

            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check CHECK (
                    (kind = 'completion') = (reason_code IS NULL)
                    AND (reason_code IS NULL
                         OR reason_code IN ('data_entry_error', 'misread_paper', 'other')
                         OR (reason_code = 'wrong_document' AND fine_debt_entry_id IS NOT NULL))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason IS NULL OR reason_code IS NOT NULL)
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_identifier_revisions
                DROP CONSTRAINT fine_identifier_revisions_reason_check,
                ADD CONSTRAINT fine_identifier_revisions_reason_check CHECK (
                    (kind = 'completion') = (reason_code IS NULL)
                    AND (reason_code IS NULL OR reason_code IN ('data_entry_error', 'misread_paper', 'other'))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason IS NULL OR reason_code IS NOT NULL)
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                ) NOT VALID;

            DROP TRIGGER fine_debt_entries_keep_document_role ON fine_debt_entries;
            DROP FUNCTION fine_debt_entries_keep_document_role();

            CREATE OR REPLACE FUNCTION fine_identifier_revision_apply() RETURNS trigger AS $$
            DECLARE
                v_tabla text;
                v_id bigint;
                v_permitidas text[];
                v_actual jsonb;
                v_claves text[];
                v_nuevas text[];
                v_campo text;
                v_prefijo text;
                v_columnas text;
                v_antes_fecha text;
                v_antes_precision text;
                v_despues_fecha text;
                v_despues_precision text;
                -- Listas explicitas: con LIKE '%_on' el guion bajo es comodin y
                -- «caption» pasaria por una fecha.
                v_prefijos CONSTANT text[] := ARRAY['issued', 'registered', 'effective', 'occurred'];
                v_fechas CONSTANT text[] := ARRAY[
                    'issued_on', 'registered_on', 'effective_on', 'occurred_on',
                    'issued_precision', 'registered_precision', 'effective_precision', 'occurred_precision'
                ];
            BEGIN
                IF NEW.fine_case_id IS NOT NULL THEN
                    v_tabla := 'fine_cases';
                    v_id := NEW.fine_case_id;
                    v_permitidas := ARRAY['number', 'year', 'suffix'];
                ELSIF NEW.fine_document_id IS NOT NULL THEN
                    v_tabla := 'fine_documents';
                    v_id := NEW.fine_document_id;
                    v_permitidas := ARRAY['act_type', 'number', 'year', 'issued_on', 'issued_precision'];
                ELSIF NEW.fine_debt_entry_id IS NOT NULL THEN
                    v_tabla := 'fine_debt_entries';
                    v_id := NEW.fine_debt_entry_id;
                    v_permitidas := ARRAY['effective_on', 'effective_precision'];
                ELSIF NEW.fine_historical_movement_id IS NOT NULL THEN
                    v_tabla := 'fine_historical_movements';
                    v_id := NEW.fine_historical_movement_id;
                    v_permitidas := ARRAY['occurred_on', 'occurred_precision'];
                ELSE
                    v_tabla := 'fines';
                    v_id := NEW.fine_id;
                    v_permitidas := ARRAY['caption', 'person_id', 'registered_on', 'registered_precision'];
                END IF;

                EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE id = $1 FOR UPDATE', v_tabla)
                    INTO v_actual USING v_id;

                SELECT array_agg(c ORDER BY c) INTO v_claves FROM jsonb_object_keys(NEW.old_values) c;
                SELECT array_agg(c ORDER BY c) INTO v_nuevas FROM jsonb_object_keys(NEW.new_values) c;

                IF v_claves IS NULL OR v_claves IS DISTINCT FROM v_nuevas THEN
                    RAISE EXCEPTION 'La revision tiene que decir el valor anterior y el nuevo de los mismos datos.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NOT (v_claves <@ v_permitidas) THEN
                    RAISE EXCEPTION 'Esos datos de % no se revisan: %.', v_tabla, array_to_string(v_claves, ', ')
                        USING ERRCODE = 'check_violation';
                END IF;

                -- Una fecha y su precision viajan juntas: separadas, el estado
                -- intermedio no cumpliria las CHECK de la tabla.
                FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                    IF ((v_prefijo || '_on') = ANY (v_claves)) <> ((v_prefijo || '_precision') = ANY (v_claves)) THEN
                        RAISE EXCEPTION 'La fecha % se revisa junto con su precision.', v_prefijo || '_on'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                FOREACH v_campo IN ARRAY v_claves LOOP
                    IF (v_actual -> v_campo) IS DISTINCT FROM (NEW.old_values -> v_campo) THEN
                        /*
                         * `check_violation` y no `serialization_failure`:
                         * Laravel trata ese codigo como un choque de
                         * concurrencia y lo reintenta o lo envuelve. Esto no
                         * se arregla reintentando: hay que volver a leer.
                         */
                        RAISE EXCEPTION 'El dato % cambio desde que se abrio el formulario: volve a cargarlo.', v_campo
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;

                IF NEW.old_values = NEW.new_values THEN
                    RAISE EXCEPTION 'La revision no cambia ningun dato.'
                        USING ERRCODE = 'check_violation';
                END IF;

                /*
                 * Completar es llenar un vacio o precisar una fecha sin
                 * moverla de mes. Cualquier otra cosa es una correccion y
                 * lleva motivo.
                 *
                 * La fecha y su precision se juzgan como un par, aunque el
                 * valor de la fecha no cambie: «01/05/2026» con dia a «mayo
                 * de 2026» deja la fecha igual y pierde precision, y eso es
                 * corregir (revision del 2026-10-20, R2).
                 */
                IF NEW.kind = 'completion' THEN
                    FOREACH v_campo IN ARRAY v_claves LOOP
                        IF v_campo = ANY (v_fechas)
                           OR (NEW.old_values -> v_campo) = (NEW.new_values -> v_campo) THEN
                            CONTINUE;
                        END IF;

                        IF jsonb_typeof(NEW.old_values -> v_campo) <> 'null' THEN
                            RAISE EXCEPTION 'El dato % ya tenia valor: cambiarlo es una correccion y lleva motivo.', v_campo
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END LOOP;

                    FOREACH v_prefijo IN ARRAY v_prefijos LOOP
                        IF NOT ((v_prefijo || '_on') = ANY (v_claves)) THEN
                            CONTINUE;
                        END IF;

                        v_antes_fecha := NEW.old_values ->> (v_prefijo || '_on');
                        v_antes_precision := NEW.old_values ->> (v_prefijo || '_precision');
                        v_despues_fecha := NEW.new_values ->> (v_prefijo || '_on');
                        v_despues_precision := NEW.new_values ->> (v_prefijo || '_precision');

                        IF v_antes_fecha IS NOT DISTINCT FROM v_despues_fecha
                           AND v_antes_precision IS NOT DISTINCT FROM v_despues_precision THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'unknown' THEN
                            CONTINUE;
                        END IF;

                        IF v_antes_precision = 'month'
                           AND v_despues_precision = 'day'
                           AND date_trunc('month', v_despues_fecha::date)::date = v_antes_fecha::date THEN
                            CONTINUE;
                        END IF;

                        RAISE EXCEPTION 'Mover o volver menos precisa una fecha es una correccion y lleva motivo.'
                            USING ERRCODE = 'check_violation';
                    END LOOP;
                END IF;

                SELECT string_agg(quote_ident(c), ', ') INTO v_columnas FROM unnest(v_claves) c;

                -- Movimientos y antecedentes no llevan `updated_at`: su historia
                -- es la propia revision.
                EXECUTE format(
                    'UPDATE %I SET (%s) = (SELECT %s FROM jsonb_populate_record(NULL::%I, $1))%s WHERE id = $2',
                    v_tabla, v_columnas, v_columnas, v_tabla,
                    CASE WHEN v_tabla IN ('fine_debt_entries', 'fine_historical_movements') THEN '' ELSE ', updated_at = now()' END
                ) USING NEW.new_values, v_id;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_date_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'La tabla fine_debt_entries es append-only: no admite DELETE.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF pg_trigger_depth() < 2
                   OR (to_jsonb(NEW) - 'effective_on' - 'effective_precision')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'effective_on' - 'effective_precision') THEN
                    RAISE EXCEPTION 'Un movimiento de deuda no se edita: solo su fecha se completa o corrige con una revision.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }
            },

            /**
             * La rendición oficial mensual de Multas (P02): dos informes por mes cerrado
             * —los ingresos en término y los fuera de término— congelados como la
             * planilla de caja.
             *
             * Cuelgan del cierre mensual de la caja de Multas y de su versión: no hay un
             * segundo calendario de cierres. Reabrir y volver a cerrar el mes produce una
             * versión nueva del cierre, y con ella otra rendición; la anterior queda.
             * Cada informe se escribe una vez y no se modifica.
             *
             * Para saber qué se reconoció antes de un cierre, la evidencia de cada
             * versión anota el último asiento de la caja que vio. No alcanza con comparar
             * horas: `closed_at` y `created_at` se guardan al segundo —PostgreSQL
             * redondea—, y un reconocimiento hecho en el mismo segundo que el cierre, o
             * en el siguiente redondeado hacia abajo, no se distingue de uno anterior.
             * Los asientos de una caja y su cierre toman el mismo candado (`cash_boxes
             * FOR UPDATE`), así que el orden de los ids es el orden real.
             */
            // Migración original: 2026_10_28_000000_add_fine_monthly_reports.php
            new class extends Migration
            {
                public function up(): void
                {
                    $this->evidenceWatermark();

                    Schema::create('fine_monthly_reports', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('period_closing_id')->constrained('period_closings')->restrictOnDelete();
                        $table->unsignedInteger('closing_version');
                        $table->string('kind', 10);
                        $table->jsonb('payload');
                        $table->decimal('total', 19, 2);
                        $table->unsignedInteger('rows_count');
                        $table->foreignId('attachment_id')->nullable()->unique()->constrained('attachments')->restrictOnDelete();
                        $table->foreignId('emitted_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('emitted_at');
                        $table->unique(['period_closing_id', 'closing_version', 'kind']);
                    });

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_monthly_reports
                ADD CONSTRAINT fine_monthly_reports_kind_check CHECK (kind IN ('on_time', 'late')),
                ADD CONSTRAINT fine_monthly_reports_amounts_check CHECK (total >= 0 AND rows_count >= 0),
                ADD CONSTRAINT fine_monthly_reports_payload_check CHECK (jsonb_typeof(payload) = 'array');

            -- Solo sobre el cierre mensual vigente de la caja de Multas, cerrado.
            CREATE OR REPLACE FUNCTION fine_monthly_report_closing_ok() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM period_closings
                     WHERE id = NEW.period_closing_id
                       AND cash_box_id = multas_cash_box_id()
                       AND period_type = 'monthly'
                       AND status = 'closed'
                       AND evidence_version = NEW.closing_version
                ) THEN
                    RAISE EXCEPTION 'La rendición se emite sobre el cierre mensual vigente de Multas, cerrado.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_monthly_reports_closing_ok BEFORE INSERT ON fine_monthly_reports
                FOR EACH ROW EXECUTE FUNCTION fine_monthly_report_closing_ok();

            -- El PDF se adjunta una vez, después de congelar las filas; nada más cambia.
            CREATE OR REPLACE FUNCTION fine_monthly_report_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una rendición emitida no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.attachment_id IS NOT NULL
                    OR NEW.attachment_id IS NULL
                    OR (to_jsonb(NEW) - 'attachment_id') IS DISTINCT FROM (to_jsonb(OLD) - 'attachment_id') THEN
                    RAISE EXCEPTION 'Una rendición emitida no se modifica.' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_monthly_reports_frozen BEFORE UPDATE OR DELETE ON fine_monthly_reports
                FOR EACH ROW EXECUTE FUNCTION fine_monthly_report_frozen();
        SQL);
                }

                public function down(): void
                {
                    Schema::dropIfExists('fine_monthly_reports');
                    DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fine_monthly_report_closing_ok();
            DROP FUNCTION IF EXISTS fine_monthly_report_frozen();
            DROP TRIGGER IF EXISTS closing_evidence_watermark ON period_closing_evidence;
            DROP FUNCTION IF EXISTS closing_evidence_watermark();
            ALTER TABLE period_closing_evidence DROP COLUMN IF EXISTS covers_through_event_id;
        SQL);
                }

                /**
                 * El último asiento de la caja que vio cada versión del cierre. Lo pone
                 * la base al congelar la evidencia, dentro del candado de la caja: el
                 * código no lo elige ni lo puede adelantar.
                 *
                 * Las versiones ya congeladas se completan por hora, la única referencia
                 * que tienen. Ninguna es de Multas en producción —la caja todavía no se
                 * abrió—, que es la única que lee la marca.
                 */
                private function evidenceWatermark(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE period_closing_evidence ADD COLUMN covers_through_event_id bigint;

            ALTER TABLE period_closing_evidence DISABLE TRIGGER closing_evidence_append_only;
            UPDATE period_closing_evidence e
               SET covers_through_event_id = COALESCE((
                   SELECT max(f.id)
                     FROM financial_events f
                     JOIN period_closings c ON c.cash_box_id = f.cash_box_id
                    WHERE c.id = e.period_closing_id
                      AND f.created_at <= e.created_at
               ), 0);
            ALTER TABLE period_closing_evidence ENABLE TRIGGER closing_evidence_append_only;

            ALTER TABLE period_closing_evidence
                ALTER COLUMN covers_through_event_id SET NOT NULL,
                ADD CONSTRAINT period_closing_evidence_watermark_check CHECK (covers_through_event_id >= 0);

            CREATE OR REPLACE FUNCTION closing_evidence_watermark() RETURNS trigger AS $$
            BEGIN
                NEW.covers_through_event_id := COALESCE((
                    SELECT max(f.id)
                      FROM financial_events f
                     WHERE f.cash_box_id = (SELECT cash_box_id FROM period_closings WHERE id = NEW.period_closing_id)
                ), 0);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER closing_evidence_watermark BEFORE INSERT ON period_closing_evidence
                FOR EACH ROW EXECUTE FUNCTION closing_evidence_watermark();
        SQL);
                }
            },

            /**
             * Lleva el nombre y el apellido ya cargados a la forma en que se muestran:
             * «Pérez, Juan». Desde ahora los modelos lo hacen al guardar; esto alcanza a
             * lo anterior.
             *
             * Usa la misma regla de la app para que no haya dos versiones de ella: lo
             * que ya mezcla mayúsculas y minúsculas no se toca. No tiene vuelta atrás,
             * porque cómo estaba escrito antes no se conserva.
             */
            // Migración original: 2026_10_28_010000_normalize_person_and_user_names.php
            new class extends Migration
            {
                public function up(): void
                {
                    foreach (['users', 'people'] as $table) {
                        DB::table($table)
                            ->whereNotNull('first_name')
                            ->orderBy('id')
                            ->select(['id', 'first_name', 'last_name'])
                            ->chunkById(500, function ($rows) use ($table): void {
                                foreach ($rows as $row) {
                                    $firstName = PersonName::format($row->first_name);
                                    $lastName = PersonName::format($row->last_name);

                                    if ($firstName !== $row->first_name || $lastName !== $row->last_name) {
                                        DB::table($table)->where('id', $row->id)->update([
                                            'first_name' => $firstName,
                                            'last_name' => $lastName,
                                        ]);
                                    }
                                }
                            });
                    }
                }

                public function down(): void {}
            },

            /**
             * La corrección del importe de una multa, sin resolución.
             *
             * Un aumento o una reducción eran siempre lo que disponía una resolución
             * (M04). El área confirmó (28/10/2026) que el importe mal cargado no se
             * corrige con un papel, así que un ajuste puede ser una de dos cosas:
             *
             * - **con documento**: lo dispone una resolución, con un motivo de la lista
             *   del ajuste;
             * - **sin documento**: es una corrección del importe, con un motivo de su
             *   propia lista y siempre con el detalle, que es lo único que la explica.
             *
             * Cuál de las dos es lo dice el documento, no una columna nueva: un ajuste
             * sin resolución ya no se confunde con uno que la perdió, porque los motivos
             * de cada lista no se cruzan. El resto de la regla no cambia.
             */
            // Migración original: 2026_10_28_020000_add_amount_corrections_to_fine_debt_entries.php
            new class extends Migration
            {
                private const AJUSTE = "'appeal_admitted', 'court_ruling', 'resolution_correction', 'other'";

                private const CORRECCION_DE_IMPORTE = "'data_entry_error', 'misread_paper', 'other'";

                private const REVERSION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

                public function up(): void
                {
                    $ajuste = self::AJUSTE;
                    $correccion = self::CORRECCION_DE_IMPORTE;
                    $reversion = self::REVERSION;

                    DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction')
                        OR (fine_document_id IS NOT NULL AND reason_code IN ({$ajuste}))
                        OR (fine_document_id IS NULL AND reason_code IN ({$correccion}) AND reason IS NOT NULL))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
        SQL);
                }

                public function down(): void
                {
                    $ajuste = self::AJUSTE;
                    $reversion = self::REVERSION;

                    DB::unprepared(<<<SQL
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction') OR reason_code IN ({$ajuste}))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                ) NOT VALID;
        SQL);
                }
            },

            /**
             * El término de Multas se corta en el fin del mes, no en su cierre manual.
             *
             * Decisión del usuario (29/10/2026): el período es el mes calendario y lo
             * que pasa después de su último día ya es del mes siguiente, se haya
             * cerrado la caja o no. El cierre manual sigue existiendo para la caja
             * —arqueos, días cerrados, totales congelados— y deja de decidir el término.
             *
             * - **Término del pago (M08):** se guarda el último día del mes de registro
             *   de la multa contra el que se juzgó (`term_ends_on`), en lugar de la
             *   evidencia del cierre y su hora. Un pago con término decidido lo tiene
             *   siempre; «a revisar» puede no tenerlo (multa sin fecha de registro).
             *   Lo ya aplicado conserva su clasificación: se completa el día con el fin
             *   del mes de registro de la multa.
             * - **Rendición:** se clasifica por la fecha del reconocimiento, que ya está
             *   en el asiento. La marca del último asiento que vio cada cierre
             *   (`covers_through_event_id`), que existía solo para eso, se retira.
             */
            // Migración original: 2026_10_29_000000_judge_fine_terms_by_month_end.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_applications ADD COLUMN term_ends_on date;

            ALTER TABLE fine_payment_applications DISABLE TRIGGER fine_payment_applications_append_only;
            UPDATE fine_payment_applications a
               SET term_ends_on = (date_trunc('month', f.registered_on) + interval '1 month - 1 day')::date
              FROM fines f
             WHERE f.id = a.fine_id
               AND f.registered_on IS NOT NULL
               AND f.registered_precision <> 'unknown';
            ALTER TABLE fine_payment_applications ENABLE TRIGGER fine_payment_applications_append_only;

            ALTER TABLE fine_payment_applications
                DROP CONSTRAINT fine_payment_applications_timeliness_check,
                DROP COLUMN origin_closing_evidence_id,
                DROP COLUMN origin_closed_at,
                ADD CONSTRAINT fine_payment_applications_timeliness_check CHECK (
                    timeliness IN ('on_time', 'late', 'undetermined')
                    AND (timeliness = 'undetermined' OR term_ends_on IS NOT NULL)
                );

            DROP TRIGGER IF EXISTS closing_evidence_watermark ON period_closing_evidence;
            DROP FUNCTION IF EXISTS closing_evidence_watermark();
            ALTER TABLE period_closing_evidence
                DROP CONSTRAINT IF EXISTS period_closing_evidence_watermark_check,
                DROP COLUMN IF EXISTS covers_through_event_id;
        SQL);
                }

                /**
                 * Vuelve las columnas, sin datos: el día del término no dice contra qué
                 * evidencia de cierre se habría juzgado, y la marca de cada cierre no se
                 * puede reconstruir. Por eso la guarda vieja vuelve sin validar.
                 */
                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_applications
                DROP CONSTRAINT fine_payment_applications_timeliness_check,
                ADD COLUMN origin_closing_evidence_id bigint REFERENCES period_closing_evidence (id) ON DELETE RESTRICT,
                ADD COLUMN origin_closed_at timestamptz(0),
                DROP COLUMN term_ends_on,
                ADD CONSTRAINT fine_payment_applications_timeliness_check CHECK (
                    timeliness IN ('on_time', 'late', 'undetermined')
                    AND (origin_closing_evidence_id IS NULL) = (origin_closed_at IS NULL)
                    AND (timeliness <> 'late' OR origin_closing_evidence_id IS NOT NULL)
                ) NOT VALID;

            ALTER TABLE period_closing_evidence
                ADD COLUMN covers_through_event_id bigint NOT NULL DEFAULT 0,
                ADD CONSTRAINT period_closing_evidence_watermark_check CHECK (covers_through_event_id >= 0);
            ALTER TABLE period_closing_evidence ALTER COLUMN covers_through_event_id DROP DEFAULT;

            CREATE OR REPLACE FUNCTION closing_evidence_watermark() RETURNS trigger AS $$
            BEGIN
                NEW.covers_through_event_id := COALESCE((
                    SELECT max(f.id)
                      FROM financial_events f
                     WHERE f.cash_box_id = (SELECT cash_box_id FROM period_closings WHERE id = NEW.period_closing_id)
                ), 0);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER closing_evidence_watermark BEFORE INSERT ON period_closing_evidence
                FOR EACH ROW EXECUTE FUNCTION closing_evidence_watermark();
        SQL);
                }
            },
        ];
    }
};
