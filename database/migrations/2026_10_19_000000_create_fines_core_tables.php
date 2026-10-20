<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El núcleo de Multas: expedientes, documentos, multas y su obligación.
 *
 * Todavía no hay plata: acá se registra **qué se debe y por qué papel**,
 * igual que el expediente de Haberes registra el derecho y no el dinero. Los
 * pagos llegan en la Etapa 4 y se apoyan en estas tablas sin cambiarlas.
 *
 * Tres ideas sostienen el diseño (DER físico v1.1, §3):
 *
 * - **Original y normalizado.** Cada dato que viene de un papel se guarda
 *   como se leyó —`original_text`, `caption_original`, que nunca cambian— y
 *   en su forma normalizada, que se puede completar o corregir. El histórico
 *   del Excel llega incompleto y tiene que poder completarse después.
 * - **Precisión de fecha.** El Excel da meses, no días. Una fecha es el par
 *   fecha + precisión (`day` / `month` / `unknown`); un mes se guarda como su
 *   primer día y nunca se inventa el día.
 * - **La obligación es un libro.** El importe de la multa no es una columna:
 *   es la suma de su constitución y sus ajustes, cada uno con su documento.
 *   Nada se edita; lo que estuvo mal se revierte entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createCases();
        $this->createDocuments();
        $this->createFines();
        $this->createDebtEntries();
        $this->createHistoricalMovements();
        $this->createRevisions();
        $this->appendOnly();
        $this->identityGuard();
        $this->revisionsApplyThemselves();
        $this->debtCoherence();
        $this->historicalReviewOnce();
        $this->balancesView();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS fine_balances;
            DROP TABLE IF EXISTS fine_identifier_revisions;
            DROP TABLE IF EXISTS fine_historical_movements;
            DROP TABLE IF EXISTS fine_debt_entries;
            DROP TABLE IF EXISTS fine_document_links;
            DROP TABLE IF EXISTS fines;
            DROP TABLE IF EXISTS fine_case_documents;
            DROP TABLE IF EXISTS fine_documents;
            DROP TABLE IF EXISTS fine_cases;
            DROP FUNCTION IF EXISTS fine_identity_guard();
            DROP FUNCTION IF EXISTS fine_identifier_revision_apply();
            DROP FUNCTION IF EXISTS fine_obligation(bigint);
            DROP FUNCTION IF EXISTS fine_debt_entries_coherent();
            DROP FUNCTION IF EXISTS fines_have_constitution();
            DROP FUNCTION IF EXISTS fine_historical_review_once();
            DROP FUNCTION IF EXISTS fine_historical_refund_covered();
            DROP FUNCTION IF EXISTS fine_debt_entries_date_only();
        SQL);
    }

    /**
     * Las tres reglas de una fecha con precisión, para la columna que se pida.
     */
    private function precisionChecks(string $table, string $date, string $precision): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$precision}_check
            CHECK ({$precision} IN ('day', 'month', 'unknown'))");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$date}_known_check
            CHECK (({$precision} = 'unknown') = ({$date} IS NULL))");
        // Un mes se guarda como su primer día: el día no se inventa.
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$date}_month_check
            CHECK ({$precision} <> 'month' OR {$date} = date_trunc('month', {$date})::date)");
    }

    private function createCases(): void
    {
        Schema::create('fine_cases', function (Blueprint $table): void {
            $table->id();
            // Como vino: «169607/24-0», «120276/25». No cambia nunca.
            $table->string('original_text', 60);
            $table->unsignedInteger('number')->nullable();
            // Siempre en cuatro dígitos: «/24» se guarda 2024.
            $table->unsignedSmallInteger('year')->nullable();
            /*
             * «-0» se guarda «0». Un sufijo ausente es NULL y no se equipara
             * a «0»: `120276/25` y `120276/25-0` son dos claves hasta que
             * el área diga que son la misma (P03).
             */
            $table->string('suffix', 10)->nullable();
            $table->string('provenance', 10)->default('manual');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['number', 'year']);
        });

        DB::statement("ALTER TABLE fine_cases ADD CONSTRAINT fine_cases_original_check
            CHECK (btrim(original_text) <> '')");
        DB::statement('ALTER TABLE fine_cases ADD CONSTRAINT fine_cases_number_check
            CHECK (number IS NULL OR number > 0)');
        DB::statement('ALTER TABLE fine_cases ADD CONSTRAINT fine_cases_year_check
            CHECK (year IS NULL OR year BETWEEN 1990 AND 2100)');
        DB::statement("ALTER TABLE fine_cases ADD CONSTRAINT fine_cases_suffix_check
            CHECK (suffix IS NULL OR suffix ~ '^[0-9A-Za-z]{1,10}$')");
        DB::statement("ALTER TABLE fine_cases ADD CONSTRAINT fine_cases_provenance_check
            CHECK (provenance IN ('manual', 'import'))");
        // Una carga manual sabe número y año; un importado puede no saberlos.
        DB::statement("ALTER TABLE fine_cases ADD CONSTRAINT fine_cases_manual_identity_check
            CHECK (provenance = 'import' OR (number IS NOT NULL AND year IS NOT NULL))");

        /*
         * La identidad confirmada: número, año y sufijo exactos.
         *
         * `NULLS NOT DISTINCT` hace que dos `120276/25` sin sufijo choquen
         * entre sí, que es lo que se busca. Completar o corregir hacia una
         * clave que ya existe choca igual: no hay fusión por la puerta de
         * atrás.
         */
        DB::statement('CREATE UNIQUE INDEX fine_cases_identity_unique
            ON fine_cases (number, year, suffix) NULLS NOT DISTINCT
            WHERE number IS NOT NULL AND year IS NOT NULL');
    }

    private function createDocuments(): void
    {
        Schema::create('fine_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 12);
            $table->string('act_type', 4)->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            // «N°0905/26», «HYS N° 1025288/25», «RATIFICA RES. N° 1264/23».
            $table->string('original_text', 120);
            $table->date('issued_on')->nullable();
            $table->string('issued_precision', 8)->default('unknown');
            $table->string('provenance', 10)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // Sin UNIQUE (P03): «0701/26» aparece en dos expedientes. El
            // índice sirve para buscar y avisar coincidencias.
            $table->index(['kind', 'act_type', 'number', 'year']);
        });

        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_kind_check
            CHECK (kind IN ('resolution', 'act', 'other'))");
        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_act_type_check
            CHECK (act_type IS NULL OR act_type IN ('LAB', 'HYS'))");
        // Solo un acta tiene tipo.
        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_act_type_kind_check
            CHECK (kind = 'act' OR act_type IS NULL)");
        /*
         * Un acta cargada a mano dice su tipo; una importada puede llegar
         * sin él —«celda vacía» en el Excel— y completarse después.
         */
        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_manual_act_type_check
            CHECK (kind <> 'act' OR act_type IS NOT NULL OR provenance = 'import')");
        /*
         * A mano, una resolución trae número y año, y un acta su número.
         * El año de un acta es opcional también a mano: el Excel de 2026
         * tiene actas sin año («H Y S N°169607»), y exigirlo bloquearía
         * cargar el papel tal como llega.
         */
        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_manual_identity_check
            CHECK (
                provenance = 'import'
                OR kind = 'other'
                OR (number IS NOT NULL AND (kind <> 'resolution' OR year IS NOT NULL))
            )");
        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_original_check
            CHECK (btrim(original_text) <> '')");
        DB::statement('ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_number_check
            CHECK (number IS NULL OR number > 0)');
        DB::statement('ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_year_check
            CHECK (year IS NULL OR year BETWEEN 1990 AND 2100)');
        DB::statement("ALTER TABLE fine_documents ADD CONSTRAINT fine_documents_provenance_check
            CHECK (provenance IN ('manual', 'import'))");
        $this->precisionChecks('fine_documents', 'issued_on', 'issued_precision');

        /*
         * El documento pertenece al expediente.
         *
         * Va en una tabla puente y no como FK en el documento: si una misma
         * resolución alcanzara a dos expedientes —«2538/25» aparece en dos—
         * no habría que duplicarla. Que eso pase de verdad lo dirá P03; la
         * forma no lo prohíbe ni lo impone.
         */
        Schema::create('fine_case_documents', function (Blueprint $table): void {
            $table->foreignId('fine_case_id')->constrained('fine_cases')->restrictOnDelete();
            $table->foreignId('fine_document_id')->constrained('fine_documents')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['fine_case_id', 'fine_document_id']);
            $table->index('fine_document_id');
        });
    }

    private function createFines(): void
    {
        Schema::create('fines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_case_id')->constrained('fine_cases')->restrictOnDelete();
            // Opcional siempre: ni la multa nueva ni la histórica obligan a
            // crear una persona en el maestro (M01).
            $table->foreignId('person_id')->nullable()->constrained('people')->restrictOnDelete();
            // La carátula como figura en el papel. No cambia nunca.
            $table->string('caption_original', 300);
            // La denominación vigente: precarga el «recibí de» (M09).
            $table->string('caption', 300);
            $table->char('currency', 3)->default('ARS');
            // El mes de esta fecha es el período de origen (M08).
            $table->date('registered_on')->nullable();
            $table->string('registered_precision', 8)->default('unknown');
            $table->string('provenance', 10)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'fine_case_id']);
            $table->index('fine_case_id');
            $table->index('person_id');
            $table->index('registered_on');
        });

        DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_caption_original_check
            CHECK (btrim(caption_original) <> '')");
        DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_caption_check
            CHECK (btrim(caption) <> '')");
        DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_currency_check
            CHECK (currency IN ('ARS', 'USD'))");
        DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_provenance_check
            CHECK (provenance IN ('manual', 'import'))");
        $this->precisionChecks('fines', 'registered_on', 'registered_precision');
        // A mano se sabe el día en que se registró la deuda.
        DB::statement("ALTER TABLE fines ADD CONSTRAINT fines_manual_registered_check
            CHECK (provenance = 'import' OR registered_precision = 'day')");

        /*
         * Qué papel cumple cada documento en la multa.
         *
         * Las dos FK compuestas impiden **por esquema** que una multa cite
         * un documento de otro expediente: el par (multa, expediente) tiene
         * que existir en `fines`, y el par (expediente, documento) en
         * `fine_case_documents`.
         */
        Schema::create('fine_document_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('fine_id');
            $table->unsignedBigInteger('fine_case_id');
            $table->unsignedBigInteger('fine_document_id');
            $table->string('role', 14);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['fine_id', 'fine_document_id', 'role']);
            $table->index('fine_document_id');
        });

        DB::statement('ALTER TABLE fine_document_links ADD CONSTRAINT fine_document_links_fine_fk
            FOREIGN KEY (fine_id, fine_case_id) REFERENCES fines (id, fine_case_id)');
        DB::statement('ALTER TABLE fine_document_links ADD CONSTRAINT fine_document_links_case_document_fk
            FOREIGN KEY (fine_case_id, fine_document_id) REFERENCES fine_case_documents (fine_case_id, fine_document_id)');
        DB::statement("ALTER TABLE fine_document_links ADD CONSTRAINT fine_document_links_role_check
            CHECK (role IN ('origin', 'modification', 'ratification', 'support'))");
    }

    private function createDebtEntries(): void
    {
        Schema::create('fine_debt_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->string('kind', 14);
            $table->decimal('amount', 19, 2);
            // La fecha de la resolución que lo produce.
            $table->date('effective_on')->nullable();
            $table->string('effective_precision', 8)->default('unknown');
            $table->foreignId('fine_document_id')->nullable()->constrained('fine_documents')->restrictOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('fine_debt_entries')->restrictOnDelete();
            $table->string('provenance', 10)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('fine_id');
        });

        DB::statement("ALTER TABLE fine_debt_entries ADD CONSTRAINT fine_debt_entries_kind_check
            CHECK (kind IN ('constitution', 'increase', 'reduction', 'reversal'))");
        DB::statement('ALTER TABLE fine_debt_entries ADD CONSTRAINT fine_debt_entries_amount_check
            CHECK (amount > 0)');
        DB::statement("ALTER TABLE fine_debt_entries ADD CONSTRAINT fine_debt_entries_reason_check
            CHECK (kind = 'constitution' OR (reason IS NOT NULL AND btrim(reason) <> ''))");
        DB::statement("ALTER TABLE fine_debt_entries ADD CONSTRAINT fine_debt_entries_reversal_check
            CHECK ((kind = 'reversal') = (reversal_of_id IS NOT NULL))");
        DB::statement("ALTER TABLE fine_debt_entries ADD CONSTRAINT fine_debt_entries_provenance_check
            CHECK (provenance IN ('manual', 'import'))");
        $this->precisionChecks('fine_debt_entries', 'effective_on', 'effective_precision');
        DB::statement("ALTER TABLE fine_debt_entries ADD CONSTRAINT fine_debt_entries_manual_date_check
            CHECK (provenance = 'import' OR effective_precision = 'day')");
        DB::statement("CREATE UNIQUE INDEX fine_debt_entries_one_constitution
            ON fine_debt_entries (fine_id) WHERE kind = 'constitution'");
    }

    /**
     * Antecedentes: lo que se pagó o se devolvió sin pasar por el libro.
     *
     * Se adelanta de la Etapa 9 en su forma mínima (DER físico §3.8): un pago
     * del Excel o un recibo de papel que aparece durante la operación se
     * registra sin esperar al importador y **sin generar otra entrada de
     * dinero**. El vínculo con un crédito del extracto llega en la Etapa 3,
     * con el corte de la cuenta compartida.
     */
    private function createHistoricalMovements(): void
    {
        Schema::create('fine_historical_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->string('kind', 10);
            $table->decimal('amount', 19, 2);
            $table->date('occurred_on')->nullable();
            $table->string('occurred_precision', 8)->default('unknown');
            // Qué respalda el dato: «Excel 2025, julio», «recibo de papel 64685».
            $table->string('evidence_text', 500);
            $table->string('status', 16)->default('pending_review');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('provenance', 10)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['fine_id', 'status']);
        });

        DB::statement("ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_kind_check
            CHECK (kind IN ('payment', 'refund'))");
        DB::statement('ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_amount_check
            CHECK (amount > 0)');
        DB::statement("ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_evidence_check
            CHECK (btrim(evidence_text) <> '')");
        DB::statement("ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_status_check
            CHECK (status IN ('pending_review', 'validated', 'rejected'))");
        DB::statement("ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_reviewed_check
            CHECK ((status = 'pending_review') = (reviewed_at IS NULL))");
        // Descartar un antecedente dice por qué.
        DB::statement("ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_rejected_note_check
            CHECK (status <> 'rejected' OR (review_note IS NOT NULL AND btrim(review_note) <> ''))");
        DB::statement("ALTER TABLE fine_historical_movements ADD CONSTRAINT fine_historical_movements_provenance_check
            CHECK (provenance IN ('manual', 'import'))");
        $this->precisionChecks('fine_historical_movements', 'occurred_on', 'occurred_precision');
    }

    /**
     * Completar o corregir un dato normalizado deja su propia fila.
     *
     * La revisión **se aplica sola** (`fine_identifier_revision_apply`) y es
     * la única vía para cambiar esos datos: la guarda de las tablas
     * (`fine_identity_guard`) solo los deja cambiar desde el trigger de la
     * revisión, con `pg_trigger_depth() > 1`, que la app no puede fingir.
     *
     * No se usa una variable de sesión a propósito: la migración
     * `close_append_only_escape_hatches` ya explica por qué cualquier rol,
     * la app incluida, puede fijarla.
     */
    private function createRevisions(): void
    {
        Schema::create('fine_identifier_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_case_id')->nullable()->constrained('fine_cases')->restrictOnDelete();
            $table->foreignId('fine_document_id')->nullable()->constrained('fine_documents')->restrictOnDelete();
            $table->foreignId('fine_id')->nullable()->constrained('fines')->restrictOnDelete();
            /*
             * La fecha de un movimiento de deuda o de un antecedente importado
             * puede llegar desconocida y completarse cuando aparece el papel
             * (revisión del 2026-10-20, R4). Solo la fecha: importe, tipo y
             * efecto no se revisan nunca.
             */
            $table->foreignId('fine_debt_entry_id')->nullable()->constrained('fine_debt_entries')->restrictOnDelete();
            $table->foreignId('fine_historical_movement_id')->nullable()->constrained('fine_historical_movements')->restrictOnDelete();
            $table->string('kind', 12);
            // Solo las columnas que cambian, con el valor que tenían y el nuevo.
            $table->jsonb('old_values');
            $table->jsonb('new_values');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('fine_case_id');
            $table->index('fine_document_id');
            $table->index('fine_id');
            $table->index('fine_debt_entry_id');
            $table->index('fine_historical_movement_id');
        });

        DB::statement('ALTER TABLE fine_identifier_revisions ADD CONSTRAINT fine_identifier_revisions_target_check
            CHECK (num_nonnulls(fine_case_id, fine_document_id, fine_id, fine_debt_entry_id, fine_historical_movement_id) = 1)');
        DB::statement("ALTER TABLE fine_identifier_revisions ADD CONSTRAINT fine_identifier_revisions_kind_check
            CHECK (kind IN ('completion', 'correction'))");
        // Corregir algo que ya tenía valor dice por qué; completar un vacío no.
        DB::statement("ALTER TABLE fine_identifier_revisions ADD CONSTRAINT fine_identifier_revisions_reason_check
            CHECK (kind = 'completion' OR (reason IS NOT NULL AND btrim(reason) <> ''))");
        DB::statement("ALTER TABLE fine_identifier_revisions ADD CONSTRAINT fine_identifier_revisions_values_check
            CHECK (jsonb_typeof(old_values) = 'object' AND jsonb_typeof(new_values) = 'object')");
    }

    private function appendOnly(): void
    {
        foreach (['fine_case_documents', 'fine_document_links', 'fine_identifier_revisions'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_append_only
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION forbid_mutation()");
        }

        /*
         * Un movimiento de deuda no se edita ni se borra. La única excepción
         * es completar o corregir su fecha desde una revisión: importe, tipo,
         * documento y reversión quedan como nacieron.
         */
        DB::unprepared(<<<'SQL'
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

            CREATE TRIGGER fine_debt_entries_date_only
                BEFORE UPDATE OR DELETE ON fine_debt_entries
                FOR EACH ROW EXECUTE FUNCTION fine_debt_entries_date_only();
        SQL);

        // Expedientes, documentos y multas no se borran nunca.
        foreach (['fine_cases', 'fine_documents', 'fines', 'fine_historical_movements'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_no_delete
                BEFORE DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION forbid_mutation()");
        }
    }

    /**
     * Qué se puede cambiar en un expediente, un documento o una multa.
     *
     * - El texto original, la carátula original y la procedencia, nunca.
     * - Las observaciones del expediente, libremente.
     * - Los datos normalizados, solo desde una revisión.
     * - Nada más.
     */
    private function identityGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_identity_guard() RETURNS trigger AS $$
            DECLARE
                v_anterior jsonb := to_jsonb(OLD);
                v_nuevo jsonb := to_jsonb(NEW);
                v_libres text[];
                v_revisables text[];
                v_campo text;
            BEGIN
                IF TG_TABLE_NAME = 'fine_cases' THEN
                    v_libres := ARRAY['notes', 'updated_at'];
                    v_revisables := ARRAY['number', 'year', 'suffix'];
                ELSIF TG_TABLE_NAME = 'fine_documents' THEN
                    v_libres := ARRAY['updated_at'];
                    v_revisables := ARRAY['act_type', 'number', 'year', 'issued_on', 'issued_precision'];
                ELSE
                    v_libres := ARRAY['updated_at'];
                    v_revisables := ARRAY['caption', 'person_id', 'registered_on', 'registered_precision'];
                END IF;

                FOR v_campo IN SELECT jsonb_object_keys(v_nuevo) LOOP
                    IF (v_nuevo -> v_campo) IS NOT DISTINCT FROM (v_anterior -> v_campo)
                       OR v_campo = ANY (v_libres) THEN
                        CONTINUE;
                    END IF;

                    IF v_campo = ANY (v_revisables) THEN
                        IF pg_trigger_depth() < 2 THEN
                            RAISE EXCEPTION 'El dato % de % se completa o corrige con una revision, no se edita.', v_campo, TG_TABLE_NAME
                                USING ERRCODE = 'restrict_violation';
                        END IF;
                    ELSE
                        RAISE EXCEPTION 'El dato % de % no se modifica.', v_campo, TG_TABLE_NAME
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END LOOP;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_cases_identity_guard BEFORE UPDATE ON fine_cases
                FOR EACH ROW EXECUTE FUNCTION fine_identity_guard();
            CREATE TRIGGER fine_documents_identity_guard BEFORE UPDATE ON fine_documents
                FOR EACH ROW EXECUTE FUNCTION fine_identity_guard();
            CREATE TRIGGER fines_identity_guard BEFORE UPDATE ON fines
                FOR EACH ROW EXECUTE FUNCTION fine_identity_guard();
        SQL);
    }

    /**
     * La revisión se aplica a sí misma.
     *
     * Comprueba que lo que dice el formulario como valor anterior siga
     * siendo el actual —si otro operador corrigió primero, no lo pisa— y que
     * una «completion» solo llene vacíos o precise una fecha. Recién ahí
     * escribe el cambio en la tabla, que su guarda acepta por la
     * profundidad del trigger. Las CHECK de la tabla validan el resultado.
     */
    private function revisionsApplyThemselves(): void
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

            CREATE TRIGGER fine_identifier_revisions_apply
                AFTER INSERT ON fine_identifier_revisions
                FOR EACH ROW EXECUTE FUNCTION fine_identifier_revision_apply();
        SQL);
    }

    /**
     * La obligación: constitución, ajustes y reversiones, coherentes.
     */
    private function debtCoherence(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_obligation(p_fine_id bigint) RETURNS numeric(19,2) AS $$
                SELECT COALESCE(SUM(
                    CASE e.kind
                        WHEN 'constitution' THEN e.amount
                        WHEN 'increase' THEN e.amount
                        WHEN 'reduction' THEN -e.amount
                        -- Una reversion deshace el efecto del movimiento que apunta.
                        ELSE CASE o.kind WHEN 'increase' THEN -e.amount ELSE e.amount END
                    END
                ), 0)::numeric(19,2)
                  FROM fine_debt_entries e
                  LEFT JOIN fine_debt_entries o ON o.id = e.reversal_of_id
                 WHERE e.fine_id = p_fine_id
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION fine_debt_entries_coherent() RETURNS trigger AS $$
            DECLARE
                v_multa RECORD;
                v_objetivo RECORD;
                v_efecto numeric(19,2);
                v_resultante numeric(19,2);
            BEGIN
                -- Dos ajustes simultaneos no leen la misma obligacion.
                SELECT id, provenance INTO v_multa FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                IF NEW.kind = 'constitution' THEN
                    IF v_multa.provenance = 'manual' AND NEW.fine_document_id IS NULL THEN
                        RAISE EXCEPTION 'Una multa cargada a mano nace citando la resolucion que la origina.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF NOT EXISTS (
                    SELECT 1 FROM fine_debt_entries WHERE fine_id = NEW.fine_id AND kind = 'constitution'
                ) THEN
                    RAISE EXCEPTION 'La multa todavia no tiene su importe original: no admite ajustes.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.fine_document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = NEW.fine_id AND fine_document_id = NEW.fine_document_id
                ) THEN
                    RAISE EXCEPTION 'El documento que respalda el movimiento tiene que estar vinculado a la multa.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.kind = 'reversal' THEN
                    SELECT fine_id, kind, amount INTO v_objetivo
                      FROM fine_debt_entries WHERE id = NEW.reversal_of_id;

                    IF v_objetivo.fine_id <> NEW.fine_id THEN
                        RAISE EXCEPTION 'Una reversion deshace un movimiento de la misma multa.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_objetivo.kind = 'constitution' THEN
                        RAISE EXCEPTION 'El importe original no se revierte: una multa anulada se reduce a cero con su resolucion.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_objetivo.kind = 'reversal' THEN
                        RAISE EXCEPTION 'Una reversion no se revierte: se registra el ajuste que corresponda.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_objetivo.amount <> NEW.amount THEN
                        RAISE EXCEPTION 'Una reversion deshace el movimiento entero: % y no %. Una parte se corrige con otro ajuste.',
                            v_objetivo.amount, NEW.amount
                            USING ERRCODE = 'check_violation';
                    END IF;

                    v_efecto := CASE v_objetivo.kind WHEN 'increase' THEN -NEW.amount ELSE NEW.amount END;
                ELSE
                    v_efecto := CASE NEW.kind WHEN 'reduction' THEN -NEW.amount ELSE NEW.amount END;
                END IF;

                v_resultante := fine_obligation(NEW.fine_id) + v_efecto;

                IF v_resultante < 0 THEN
                    RAISE EXCEPTION 'La obligacion quedaria en %: no puede ser negativa.', v_resultante
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_debt_entries_coherent
                BEFORE INSERT ON fine_debt_entries
                FOR EACH ROW EXECUTE FUNCTION fine_debt_entries_coherent();

            /*
             * Toda multa confirma con su importe original. Diferido porque la
             * multa se inserta antes que su constitucion, en la misma
             * transaccion.
             */
            CREATE OR REPLACE FUNCTION fines_have_constitution() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM fine_debt_entries WHERE fine_id = NEW.id AND kind = 'constitution'
                ) THEN
                    RAISE EXCEPTION 'La multa % no tiene importe original.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fines_have_constitution
                AFTER INSERT ON fines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fines_have_constitution();
        SQL);
    }

    /**
     * Un antecedente se revisa una vez: se valida o se descarta, y no vuelve.
     */
    private function historicalReviewOnce(): void
    {
        DB::unprepared(<<<'SQL'
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

            CREATE TRIGGER fine_historical_movements_review_once
                BEFORE UPDATE ON fine_historical_movements
                FOR EACH ROW EXECUTE FUNCTION fine_historical_review_once();

            /*
             * Una devolucion devuelve algo que se pago (revision del
             * 2026-10-20, R1). Validarla sin pagos anteriores validados que
             * la cubran haria que la multa deba mas que su obligacion, sin
             * ninguna resolucion que la aumente. Una devolucion asi queda
             * pendiente hasta que se valide el pago que devuelve.
             *
             * Con bloqueo de la multa: dos devoluciones validadas a la vez
             * no leen el mismo neto.
             */
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

            CREATE TRIGGER fine_historical_movements_refund_covered
                BEFORE INSERT OR UPDATE ON fine_historical_movements
                FOR EACH ROW EXECUTE FUNCTION fine_historical_refund_covered();
        SQL);
    }

    /**
     * El saldo de cada multa, calculado.
     *
     * En esta etapa no hay pagos operativos: `operational_paid` es cero y la
     * Etapa 4 reemplaza la vista sumando las aplicaciones. Las columnas ya
     * tienen su nombre definitivo para que las pantallas no cambien.
     *
     * `operational_excess` es la parte del excedente hecha de plata que está
     * en el libro: el histórico consume la obligación primero porque es
     * anterior (DER físico §3.7).
     */
    private function balancesView(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       0::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess
              FROM base;
        SQL);
    }
};
