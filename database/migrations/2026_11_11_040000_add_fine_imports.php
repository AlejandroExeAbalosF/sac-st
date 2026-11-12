<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La importación del Excel histórico de Multas (Etapa 9; decisiones del
 * usuario, 11/11/2026; M11 y M12).
 *
 * **Leer no es consolidar.** Un lote guarda cada fila de la planilla con sus
 * celdas originales —fórmula y resultado guardado— y los problemas que se le
 * encontraron, cada uno con su columna. Recién al consolidar se crean
 * expedientes, documentos, multas y pagos anteriores al sistema, sin asientos
 * ni recibos.
 *
 * **Los pagos importados entran como `provisional`**: cuentan para el saldo
 * desde el primer día y se revisan cuando se pueda, una sola vez. Solo existen
 * con origen `import`.
 *
 * **El corte es la apertura de la caja de Multas**: lo importado no puede tener
 * fecha desde el mes de la apertura. Esos pagos quedan como celdas pendientes
 * de identificar. Solo vale para lo importado: extenderlo a los antecedentes
 * cargados a mano es otra decisión.
 *
 * Una fila con problemas que bloquean solo se consolida cuando cada uno tiene
 * su resolución, y ninguna la excluye. La base lo repite.
 */
return new class extends Migration
{
    /** Los problemas que bloquean y lo que se puede hacer con cada uno. */
    private const ACCIONES = <<<'SQL'
            CREATE OR REPLACE FUNCTION fine_import_action_allowed(p_code text, p_action text) RETURNS boolean AS $$
                SELECT p_action = 'exclude' OR CASE p_code
                    WHEN 'duplicate_manual' THEN p_action = 'link'
                    WHEN 'changed' THEN p_action = 'add_months'
                    WHEN 'possible_match' THEN p_action IN ('same_case', 'new_case')
                    WHEN 'duplicate_in_file' THEN p_action = 'keep'
                    WHEN 'total_mismatch' THEN p_action IN ('accept', 'without_payments')
                    WHEN 'total_missing' THEN p_action IN ('accept', 'without_payments')
                    WHEN 'balance_mismatch' THEN p_action IN ('accept', 'without_payments')
                    WHEN 'balance_missing' THEN p_action IN ('accept', 'without_payments')
                    WHEN 'formula_without_result' THEN p_action IN ('accept', 'without_payments')
                    WHEN 'payment_exceeds_amount' THEN p_action IN ('accept', 'without_payments')
                    WHEN 'negative_payment' THEN p_action = 'without_payments'
                    WHEN 'note_in_payment' THEN p_action = 'without_payments'
                    WHEN 'plan_column' THEN p_action = 'ignore_columns'
                    WHEN 'refund_column' THEN p_action = 'ignore_columns'
                    WHEN 'unknown_column' THEN p_action = 'ignore_columns'
                    ELSE false
                END;
            $$ LANGUAGE sql IMMUTABLE;
        SQL;

    public function up(): void
    {
        if (DB::table('fine_historical_movements')->where('provenance', 'import')->exists()) {
            throw new RuntimeException('Hay pagos anteriores al sistema marcados como importados sin una fila de importación que los respalde. Revisalos antes de migrar.');
        }

        Schema::create('fine_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('original_filename', 255);
            $table->char('file_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('reader_version', 20);
            $table->foreignId('attachment_id')->nullable()->unique()->constrained('attachments')->restrictOnDelete();
            // El primer día del mes de la apertura: desde ahí, nada se importa como pago anterior.
            $table->date('cutoff_month');
            $table->string('status', 16)->default('reading');
            // Por hoja: detalle, pies de control, en revisión y consolidado.
            $table->jsonb('reconciliation')->default(DB::raw("'[]'::jsonb"));
            $table->text('failure')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('consolidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('consolidated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('fine_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_import_batch_id')->constrained('fine_import_batches')->restrictOnDelete();
            $table->string('sheet', 10);
            $table->unsignedInteger('row_number');
            $table->string('kind', 8);
            // Por letra de columna: valor, fórmula y encabezado. Lo que dijo la planilla, sin tocar.
            $table->jsonb('cells');
            $table->jsonb('normalized');
            // Solo con expediente y resolución completos, con número y año.
            $table->string('identity_key', 80)->nullable();
            $table->jsonb('issues')->default(DB::raw("'[]'::jsonb"));
            // La fila de un lote anterior que ya trajo esta multa.
            $table->foreignId('previous_row_id')->nullable()->constrained('fine_import_rows')->restrictOnDelete();
            $table->foreignId('fine_id')->nullable()->constrained('fines')->restrictOnDelete();
            $table->foreignId('consolidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('consolidated_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['fine_import_batch_id', 'sheet', 'row_number']);
            $table->unique(['id', 'fine_id']);
            $table->index(['fine_import_batch_id', 'kind']);
            $table->index('identity_key');
        });

        Schema::create('fine_import_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_import_row_id')->constrained('fine_import_rows')->restrictOnDelete();
            $table->string('issue_code', 40);
            $table->string('action', 20);
            $table->foreignId('fine_case_id')->nullable()->constrained('fine_cases')->restrictOnDelete();
            $table->foreignId('fine_id')->nullable()->constrained('fines')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->useCurrent();

            $table->unique(['fine_import_row_id', 'issue_code']);
        });

        Schema::create('fine_import_cells', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_import_row_id')->constrained('fine_import_rows')->restrictOnDelete();
            $table->string('column', 3);
            $table->date('month');
            $table->decimal('amount', 19, 2);
            $table->string('status', 12)->default('pending');
            $table->string('note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();

            $table->unique(['fine_import_row_id', 'column']);
            $table->index('status');
        });

        Schema::table('fine_historical_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('import_row_id')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_import_batches
                ADD CONSTRAINT fine_import_batches_status_check
                    CHECK (status IN ('reading', 'ready', 'consolidating', 'consolidated', 'failed')),
                ADD CONSTRAINT fine_import_batches_cutoff_check
                    CHECK (cutoff_month = date_trunc('month', cutoff_month)::date),
                ADD CONSTRAINT fine_import_batches_sha_check CHECK (file_sha256 ~ '^[0-9a-f]{64}$');

            ALTER TABLE fine_import_rows
                ADD CONSTRAINT fine_import_rows_kind_check CHECK (kind IN ('fine', 'footer')),
                ADD CONSTRAINT fine_import_rows_issues_check CHECK (jsonb_typeof(issues) = 'array'),
                ADD CONSTRAINT fine_import_rows_cells_check CHECK (jsonb_typeof(cells) = 'object'),
                ADD CONSTRAINT fine_import_rows_footer_check
                    CHECK (kind = 'fine' OR (fine_id IS NULL AND identity_key IS NULL AND previous_row_id IS NULL)),
                ADD CONSTRAINT fine_import_rows_consolidated_check
                    CHECK ((fine_id IS NULL) = (consolidated_at IS NULL));

            -- Dos lotes que consolidan la misma multa a la vez chocan acá, aunque no tenga pagos.
            CREATE UNIQUE INDEX fine_import_rows_one_fine_per_identity ON fine_import_rows (identity_key)
                WHERE fine_id IS NOT NULL AND previous_row_id IS NULL;

            -- Un expediente sin año se reconoce por su texto dentro del lote: sin este
            -- índice, cada fila recorrería todas las ya consolidadas.
            CREATE INDEX fine_import_rows_case_text ON fine_import_rows (fine_import_batch_id, (normalized->>'case_text'))
                WHERE fine_id IS NOT NULL;

            ALTER TABLE fine_import_resolutions
                ADD CONSTRAINT fine_import_resolutions_action_check CHECK (action IN (
                    'exclude', 'accept', 'without_payments', 'ignore_columns', 'link', 'add_months', 'same_case', 'new_case', 'keep'
                )),
                ADD CONSTRAINT fine_import_resolutions_case_check CHECK ((action = 'same_case') = (fine_case_id IS NOT NULL)),
                ADD CONSTRAINT fine_import_resolutions_link_check CHECK ((action = 'link') = (fine_id IS NOT NULL)),
                ADD CONSTRAINT fine_import_resolutions_reason_check CHECK (reason IS NULL OR btrim(reason) <> '');

            ALTER TABLE fine_import_cells
                ADD CONSTRAINT fine_import_cells_status_check CHECK (status IN ('pending', 'identified', 'dismissed')),
                ADD CONSTRAINT fine_import_cells_amount_check CHECK (amount > 0),
                ADD CONSTRAINT fine_import_cells_month_check CHECK (month = date_trunc('month', month)::date),
                ADD CONSTRAINT fine_import_cells_resolved_check CHECK (
                    (status = 'pending') = (resolved_at IS NULL)
                    AND (status = 'pending' OR (note IS NOT NULL AND btrim(note) <> ''))
                );

            -- ── Pagos anteriores al sistema ───────────────────────────────────
            ALTER TABLE fine_historical_movements
                DROP CONSTRAINT fine_historical_movements_status_check,
                ADD CONSTRAINT fine_historical_movements_status_check
                    CHECK (status IN ('pending_review', 'provisional', 'validated', 'rejected')),
                DROP CONSTRAINT fine_historical_movements_reviewed_check,
                ADD CONSTRAINT fine_historical_movements_reviewed_check
                    CHECK ((status IN ('pending_review', 'provisional')) = (reviewed_at IS NULL)),
                ADD CONSTRAINT fine_historical_movements_provisional_check
                    CHECK (status <> 'provisional' OR provenance = 'import'),
                ADD CONSTRAINT fine_historical_movements_import_row_check
                    CHECK ((provenance = 'import') = (import_row_id IS NOT NULL)),
                ADD CONSTRAINT fine_historical_movements_import_row_fk FOREIGN KEY (import_row_id, fine_id)
                    REFERENCES fine_import_rows (id, fine_id) ON DELETE RESTRICT;

            -- Un mes de la planilla entra una vez por multa.
            CREATE UNIQUE INDEX fine_historical_movements_one_import_per_month
                ON fine_historical_movements (fine_id, kind, occurred_on)
                WHERE provenance = 'import' AND status <> 'rejected';

            /*
             * Lo importado entra provisional, por mes, y antes del mes de la
             * apertura de la caja de Multas. Sin apertura no hay corte: no se importa.
             */
            CREATE OR REPLACE FUNCTION fine_historical_import_guard() RETURNS trigger AS $$
            DECLARE
                v_corte date;
            BEGIN
                IF NEW.provenance <> 'import' THEN
                    RETURN NEW;
                END IF;

                IF NEW.status <> 'provisional' OR NEW.occurred_precision <> 'month' OR NEW.occurred_on IS NULL THEN
                    RAISE EXCEPTION 'Un pago importado entra provisional, con el mes de la planilla.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT date_trunc('month', o.opened_on)::date INTO v_corte
                  FROM cash_book_openings o
                  JOIN fines f ON f.id = NEW.fine_id
                 WHERE o.cash_box_id = multas_cash_box_id() AND o.currency = f.currency;

                IF v_corte IS NULL THEN
                    RAISE EXCEPTION 'Sin la apertura de la caja de Multas no hay corte: no se importan pagos.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.occurred_on >= v_corte THEN
                    RAISE EXCEPTION 'El pago de % es del mes de la apertura o posterior: se identifica con su crédito o su cobro, no se importa.',
                        to_char(NEW.occurred_on, 'MM/YYYY')
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_historical_import_guard BEFORE INSERT ON fine_historical_movements
                FOR EACH ROW EXECUTE FUNCTION fine_historical_import_guard();

            -- Revisar: de pendiente o provisional a validado o rechazado, una vez.
            CREATE OR REPLACE FUNCTION fine_historical_review_once() RETURNS trigger AS $$
            BEGIN
                -- Completar o corregir la fecha desde una revision, en cualquier
                -- estado: no cambia lo que el antecedente aporta al saldo.
                IF pg_trigger_depth() > 1
                   AND (to_jsonb(NEW) - 'occurred_on' - 'occurred_precision')
                       IS NOT DISTINCT FROM (to_jsonb(OLD) - 'occurred_on' - 'occurred_precision') THEN
                    RETURN NEW;
                END IF;

                IF OLD.status NOT IN ('pending_review', 'provisional') THEN
                    RAISE EXCEPTION 'El antecedente ya se reviso: queda como esta.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status NOT IN ('validated', 'rejected') THEN
                    RAISE EXCEPTION 'Revisar un antecedente lo valida o lo rechaza.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF (to_jsonb(NEW) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note' - 'review_reason_code')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'reviewed_by' - 'reviewed_at' - 'review_note' - 'review_reason_code') THEN
                    RAISE EXCEPTION 'Revisar un antecedente no modifica sus datos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            -- Una devolución que cuenta necesita pagos que cuenten: validados o provisionales.
            CREATE OR REPLACE FUNCTION fine_historical_refund_covered() RETURNS trigger AS $$
            DECLARE
                v_neto numeric(19,2);
            BEGIN
                IF NEW.kind <> 'refund' OR NEW.status NOT IN ('validated', 'provisional')
                   OR (TG_OP = 'UPDATE' AND OLD.status IN ('validated', 'provisional')) THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                SELECT COALESCE(SUM(CASE kind WHEN 'payment' THEN amount ELSE -amount END), 0)
                  INTO v_neto
                  FROM fine_historical_movements
                 WHERE fine_id = NEW.fine_id
                   AND status IN ('validated', 'provisional')
                   AND id IS DISTINCT FROM NEW.id;

                IF v_neto < NEW.amount THEN
                    RAISE EXCEPTION 'La devolucion de % supera lo pagado antes del sistema que cuenta (%): queda pendiente hasta validar el pago que devuelve.',
                        NEW.amount, v_neto
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            -- Rechazar un pago que cuenta no puede dejar sin cobertura una devolución que cuenta.
            CREATE OR REPLACE FUNCTION fine_historical_rejection_keeps_refunds() RETURNS trigger AS $$
            DECLARE
                v_neto numeric(19,2);
            BEGIN
                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                SELECT COALESCE(SUM(CASE kind WHEN 'payment' THEN amount ELSE -amount END), 0)
                  INTO v_neto
                  FROM fine_historical_movements
                 WHERE fine_id = NEW.fine_id
                   AND status IN ('validated', 'provisional')
                   AND id <> NEW.id;

                IF v_neto < 0 THEN
                    RAISE EXCEPTION 'Rechazar este pago deja sin cobertura una devolucion que cuenta: rechaza primero la devolucion.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_historical_rejection_keeps_refunds BEFORE UPDATE ON fine_historical_movements
                FOR EACH ROW
                WHEN (NEW.kind = 'payment' AND NEW.status = 'rejected' AND OLD.status IN ('validated', 'provisional'))
                EXECUTE FUNCTION fine_historical_rejection_keeps_refunds();

            -- ── Lotes ─────────────────────────────────────────────────────────
            CREATE OR REPLACE FUNCTION fine_import_batches_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un lote de importacion no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.original_filename, NEW.file_sha256, NEW.file_size, NEW.reader_version, NEW.cutoff_month, NEW.uploaded_by)
                   IS DISTINCT FROM (OLD.original_filename, OLD.file_sha256, OLD.file_size, OLD.reader_version, OLD.cutoff_month, OLD.uploaded_by)
                   OR (OLD.attachment_id IS NOT NULL AND NEW.attachment_id IS DISTINCT FROM OLD.attachment_id) THEN
                    RAISE EXCEPTION 'Lo que se subio en un lote no se edita.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reading' AND NEW.status IN ('ready', 'failed'))
                    OR (OLD.status IN ('ready', 'consolidated') AND NEW.status = 'consolidating')
                    OR (OLD.status = 'consolidating' AND NEW.status IN ('consolidated', 'ready'))
                ) THEN
                    RAISE EXCEPTION 'Un lote % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_import_batches_guard BEFORE UPDATE OR DELETE ON fine_import_batches
                FOR EACH ROW EXECUTE FUNCTION fine_import_batches_guard();

            -- ── Filas ─────────────────────────────────────────────────────────
            CREATE OR REPLACE FUNCTION fine_import_rows_guard() RETURNS trigger AS $$
            DECLARE
                v_enlace bigint;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una fila importada no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.fine_id IS NOT NULL THEN
                        RAISE EXCEPTION 'Una fila nace sin consolidar.' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - 'fine_id' - 'consolidated_by' - 'consolidated_at' - 'failure' - 'issues' - 'previous_row_id')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'fine_id' - 'consolidated_by' - 'consolidated_at' - 'failure' - 'issues' - 'previous_row_id') THEN
                    RAISE EXCEPTION 'Lo que dijo la planilla no se edita.' USING ERRCODE = 'restrict_violation';
                END IF;

                -- Los cruces entre hojas y con la base se agregan al terminar de leer, y solo agregan.
                IF (NEW.issues, NEW.previous_row_id) IS DISTINCT FROM (OLD.issues, OLD.previous_row_id) THEN
                    IF (SELECT status FROM fine_import_batches WHERE id = NEW.fine_import_batch_id) <> 'reading'
                       OR OLD.previous_row_id IS NOT NULL
                       OR jsonb_array_length(NEW.issues) < jsonb_array_length(OLD.issues)
                       OR COALESCE((
                              SELECT jsonb_agg(e ORDER BY i) FROM jsonb_array_elements(NEW.issues) WITH ORDINALITY t(e, i)
                               WHERE i <= jsonb_array_length(OLD.issues)
                          ), '[]'::jsonb) IS DISTINCT FROM OLD.issues THEN
                        RAISE EXCEPTION 'Los problemas de una fila se agregan mientras se lee el lote, y no se borran.' USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                IF OLD.fine_id IS NOT NULL THEN
                    IF (NEW.fine_id, NEW.consolidated_at, NEW.consolidated_by, NEW.failure)
                       IS DISTINCT FROM (OLD.fine_id, OLD.consolidated_at, OLD.consolidated_by, OLD.failure) THEN
                        RAISE EXCEPTION 'Una fila consolidada queda como esta.' USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.fine_id IS NULL THEN
                    RETURN NEW;
                END IF;

                -- Se consolida con el lote consolidando, sin bloqueos pendientes y sin exclusiones.
                IF (SELECT status FROM fine_import_batches WHERE id = NEW.fine_import_batch_id) <> 'consolidating' THEN
                    RAISE EXCEPTION 'Una fila se consolida mientras su lote se consolida.' USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (SELECT 1 FROM fine_import_resolutions WHERE fine_import_row_id = NEW.id AND action = 'exclude') THEN
                    RAISE EXCEPTION 'La fila % de % se excluyo: no se consolida.', NEW.row_number, NEW.sheet USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM jsonb_array_elements(NEW.issues) i
                     WHERE (i->>'blocking')::boolean
                       AND NOT EXISTS (
                           SELECT 1 FROM fine_import_resolutions r
                            WHERE r.fine_import_row_id = NEW.id AND r.issue_code = i->>'code'
                       )
                ) THEN
                    RAISE EXCEPTION 'La fila % de % tiene problemas sin resolver: no se consolida.', NEW.row_number, NEW.sheet
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT fine_id INTO v_enlace FROM fine_import_resolutions WHERE fine_import_row_id = NEW.id AND action = 'link';

                IF v_enlace IS NOT NULL AND v_enlace <> NEW.fine_id THEN
                    RAISE EXCEPTION 'La fila se vinculo a otra multa.' USING ERRCODE = 'check_violation';
                END IF;

                -- Lo que se crea desde una fila es una multa importada; vincular no crea nada.
                IF v_enlace IS NULL AND NEW.previous_row_id IS NULL
                   AND (SELECT provenance FROM fines WHERE id = NEW.fine_id) <> 'import' THEN
                    RAISE EXCEPTION 'Una fila consolida una multa importada, o se vincula a la que ya estaba.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_import_rows_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_import_rows
                FOR EACH ROW EXECUTE FUNCTION fine_import_rows_guard();

            -- ── Resoluciones ──────────────────────────────────────────────────
            CREATE OR REPLACE FUNCTION fine_import_resolutions_guard() RETURNS trigger AS $$
            DECLARE
                v_fila RECORD;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Una resolucion de importacion no se edita ni se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT fine_id, issues INTO v_fila FROM fine_import_rows WHERE id = NEW.fine_import_row_id FOR UPDATE;

                IF v_fila.fine_id IS NOT NULL THEN
                    RAISE EXCEPTION 'La fila ya se consolido: no admite resoluciones.' USING ERRCODE = 'check_violation';
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM jsonb_array_elements(v_fila.issues) i
                     WHERE i->>'code' = NEW.issue_code AND (i->>'blocking')::boolean
                ) THEN
                    RAISE EXCEPTION 'La fila no tiene el problema % que se quiere resolver.', NEW.issue_code USING ERRCODE = 'check_violation';
                END IF;

                IF NOT fine_import_action_allowed(NEW.issue_code, NEW.action) THEN
                    RAISE EXCEPTION 'El problema % no se resuelve con %.', NEW.issue_code, NEW.action USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_import_resolutions_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_import_resolutions
                FOR EACH ROW EXECUTE FUNCTION fine_import_resolutions_guard();

            -- ── Celdas pendientes de identificar ──────────────────────────────
            CREATE OR REPLACE FUNCTION fine_import_cells_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una celda pendiente no se borra: se marca.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending' THEN
                        RAISE EXCEPTION 'Una celda nace pendiente de identificar.' USING ERRCODE = 'check_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'La celda ya se resolvio.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (NEW.fine_import_row_id, NEW.column, NEW.month, NEW.amount)
                   IS DISTINCT FROM (OLD.fine_import_row_id, OLD.column, OLD.month, OLD.amount) THEN
                    RAISE EXCEPTION 'Lo que dijo la planilla no se edita.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF (SELECT fine_id FROM fine_import_rows WHERE id = NEW.fine_import_row_id) IS NULL THEN
                    RAISE EXCEPTION 'La celda se resuelve con su multa ya consolidada.' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_import_cells_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_import_cells
                FOR EACH ROW EXECUTE FUNCTION fine_import_cells_guard();
        SQL);

        DB::unprepared(self::ACCIONES);
        DB::statement(self::saldos(true));

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'fine_import'))");
    }

    public function down(): void
    {
        if (DB::table('fine_import_batches')->exists()) {
            throw new RuntimeException('Hay lotes de importación de Multas: sin estas tablas, lo importado perdería su origen.');
        }

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');

        DB::statement(self::saldos(false));

        DB::unprepared(<<<'SQL'
            DROP TRIGGER fine_historical_rejection_keeps_refunds ON fine_historical_movements;
            DROP FUNCTION fine_historical_rejection_keeps_refunds();
            DROP TRIGGER fine_historical_import_guard ON fine_historical_movements;
            DROP FUNCTION fine_historical_import_guard();
            DROP INDEX fine_historical_movements_one_import_per_month;

            ALTER TABLE fine_historical_movements
                DROP CONSTRAINT fine_historical_movements_import_row_fk,
                DROP CONSTRAINT fine_historical_movements_import_row_check,
                DROP CONSTRAINT fine_historical_movements_provisional_check,
                DROP CONSTRAINT fine_historical_movements_reviewed_check,
                ADD CONSTRAINT fine_historical_movements_reviewed_check
                    CHECK ((status = 'pending_review') = (reviewed_at IS NULL)),
                DROP CONSTRAINT fine_historical_movements_status_check,
                ADD CONSTRAINT fine_historical_movements_status_check
                    CHECK (status IN ('pending_review', 'validated', 'rejected'));
            ALTER TABLE fine_historical_movements DROP COLUMN import_row_id;

            CREATE OR REPLACE FUNCTION fine_historical_review_once() RETURNS trigger AS $$
            BEGIN
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

            CREATE OR REPLACE FUNCTION fine_historical_refund_covered() RETURNS trigger AS $$
            DECLARE
                v_neto numeric(19,2);
            BEGIN
                IF NEW.kind <> 'refund' OR NEW.status <> 'validated'
                   OR (TG_OP = 'UPDATE' AND OLD.status = 'validated') THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                SELECT COALESCE(SUM(CASE kind WHEN 'payment' THEN amount ELSE -amount END), 0)
                  INTO v_neto
                  FROM fine_historical_movements
                 WHERE fine_id = NEW.fine_id
                   AND status = 'validated'
                   AND id IS DISTINCT FROM NEW.id;

                IF v_neto < NEW.amount THEN
                    RAISE EXCEPTION 'La devolucion de % supera lo pagado antes del sistema y validado (%): queda pendiente hasta validar el pago que devuelve.',
                        NEW.amount, v_neto
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION fine_import_action_allowed(text, text);
        SQL);

        Schema::drop('fine_import_cells');
        Schema::drop('fine_import_resolutions');
        Schema::drop('fine_import_rows');
        Schema::drop('fine_import_batches');

        DB::unprepared(<<<'SQL'
            DROP FUNCTION fine_import_cells_guard();
            DROP FUNCTION fine_import_resolutions_guard();
            DROP FUNCTION fine_import_rows_guard();
            DROP FUNCTION fine_import_batches_guard();
        SQL);
    }

    /** Los sujetos de adjunto vigentes, de `2026_11_06_010000_create_fee_charge_proofs_table`. */
    private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'fine_payment', 'cheque_rejection',
                'fine_document', 'fee_charge'";

    /**
     * `fine_balances`, la de `2026_11_04_000000`, con los estados de antecedente que cuentan.
     */
    private static function saldos(bool $conProvisionales): string
    {
        $vista = <<<'SQL'
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                              AND NOT EXISTS (SELECT 1 FROM fine_payment_voids v WHERE v.fine_payment_id = a.fine_payment_id)
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status IN ({cuentan})
                       ), 0)::numeric(19,2) AS historical_paid,
                       -- El instrumento entero —excedente incluido—, una vez por cadena, mientras su rechazo esté abierto (M03).
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a
                            WHERE a.fine_id = f.id
                              AND NOT EXISTS (SELECT 1 FROM fine_payment_voids v WHERE v.fine_payment_id = a.fine_payment_id)
                              AND EXISTS (
                                  SELECT 1 FROM cheque_rejections c
                                   WHERE c.replacement_fund_receipt_id IS NULL
                                     AND fund_receipt_root(c.fund_receipt_id) = a.fund_receipt_id
                              )
                       ), 0)::numeric(19,2) AS pending_regularization
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess,
                   pending_regularization,
                   -- El recurso que no cayó, si hay: uno solo por multa.
                   (SELECT r.status FROM fine_appeals r WHERE r.fine_id = base.fine_id AND r.status <> 'dropped')::varchar(10) AS appeal_status
              FROM base;
        SQL;

        return str_replace('{cuentan}', $conProvisionales ? "'validated', 'provisional'" : "'validated'", $vista);
    }
};
