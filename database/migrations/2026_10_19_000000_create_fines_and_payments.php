<?php

declare(strict_types=1);

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
            // Migración original: 2026_10_19_000000_create_fines_core_tables.php
            new class extends Migration
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
            },

            /**
             * Los adjuntos de Multas: la foto de la resolución o del acta en el
             * expediente, el papel que respalda la multa y el comprobante de un pago
             * anterior al sistema.
             *
             * Solo se amplía el catálogo de sujetos. Lo demás de `attachments` —disco
             * privado, huella, validación por contenido, inmutabilidad— ya vale igual
             * para cualquier sujeto.
             */
            // Migración original: 2026_10_20_000000_add_fine_attachment_subjects.php
            new class extends Migration
            {
                private const ANTERIORES = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document'";

                public function up(): void
                {
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN (
                '.self::ANTERIORES.",
                'fine_case', 'fine', 'fine_historical_movement'
            ))");
                }

                public function down(): void
                {
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ANTERIORES.'))');
                }
            },

            /**
             * El corte de una cuenta bancaria compartida.
             *
             * Hasta acá el corte del banco era la apertura de **la caja**: lo anterior
             * a esa fecha ya estaba en el saldo de «depósitos directos» que la caja
             * declaró. Con una cuenta que usan dos cajas —Multas y, mañana,
             * Aranceles— ese saldo no se puede atribuir a ninguna: se declara una vez,
             * para la cuenta, tal como lo informa el banco y sin repartir (M08, M12).
             *
             * Lo que agrega:
             *
             * - `bank_account_openings`: una por cuenta, append-only. `cutoff_on` es
             *   el último día incluido en `statement_balance`.
             * - `cash_book_openings.bank_account_id`: la cuenta con la que opera cada
             *   libro, con la moneda atada por FK compuesta. Haberes se completa desde
             *   la pata bancaria de su apertura.
             * - `bank_account_openings_guard`: no hay corte sobre una cuenta cuyo saldo
             *   ya declaró una caja, ni sobre una con créditos imputados hasta esa
             *   fecha, ni posterior a la apertura de un libro que opera con ella.
             * - `cash_book_openings_after_bank_cutoff`: lo mismo desde la apertura.
             * - `journal_lines_opening_without_shared_bank`: una apertura no declara
             *   saldo bancario sobre una cuenta con corte.
             * - `bank_allocation_after_opening`, redefinida: un movimiento hasta el
             *   corte de su cuenta tampoco entra al libro como dinero nuevo.
             *
             * Todas toman el candado de la cuenta (`bank_accounts`), así el corte y lo
             * que lo contradice no pasan a la vez.
             */
            // Migración original: 2026_10_21_000000_create_bank_account_openings.php
            new class extends Migration
            {
                private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document',
                'fine_case', 'fine', 'fine_historical_movement'";

                public function up(): void
                {
                    Schema::create('bank_account_openings', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('bank_account_id')->unique()->constrained('bank_accounts')->restrictOnDelete();

                        /* El último día que el saldo informado incluye. */
                        $table->date('cutoff_on');

                        /*
                         * El saldo según el banco al cierre de `cutoff_on`, sin repartir.
                         * Puede ser negativo: es lo que dice el banco, no lo que el
                         * sistema querría.
                         */
                        $table->decimal('statement_balance', 19, 2);

                        $table->foreignId('declared_by')->constrained('users')->restrictOnDelete();
                        $table->string('notes', 300)->nullable();
                        $table->timestampTz('created_at')->useCurrent();
                    });

                    DB::statement('CREATE TRIGGER bank_account_openings_append_only
            BEFORE UPDATE OR DELETE ON bank_account_openings
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');

                    DB::statement('ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_id_currency_unique UNIQUE (id, currency)');

                    Schema::table('cash_book_openings', function (Blueprint $table): void {
                        $table->unsignedBigInteger('bank_account_id')->nullable();
                    });

                    DB::statement('ALTER TABLE cash_book_openings ADD CONSTRAINT cash_book_openings_bank_account_fk
            FOREIGN KEY (bank_account_id, currency) REFERENCES bank_accounts (id, currency) ON DELETE RESTRICT');

                    $this->backfillBookAccounts();
                    $this->guards();

                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'bank_account_opening'))");
                }

                public function down(): void
                {
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');

                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS journal_lines_opening_without_shared_bank ON journal_lines;
            DROP FUNCTION IF EXISTS journal_lines_opening_without_shared_bank();
            DROP TRIGGER IF EXISTS cash_book_openings_after_bank_cutoff ON cash_book_openings;
            DROP FUNCTION IF EXISTS cash_book_openings_after_bank_cutoff();
            DROP TRIGGER IF EXISTS bank_account_openings_guard ON bank_account_openings;
            DROP FUNCTION IF EXISTS bank_account_openings_guard();

            CREATE OR REPLACE FUNCTION bank_allocation_after_opening() RETURNS trigger AS $$
            DECLARE caja BIGINT; moneda CHAR(3); fecha DATE; apertura DATE;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;
                SELECT cash_box_id INTO caja FROM financial_events WHERE id = NEW.financial_event_id;
                IF caja IS NULL THEN RETURN NEW; END IF;
                PERFORM 1 FROM cash_boxes WHERE id = caja FOR UPDATE;
                SELECT b.currency, t.transaction_date INTO moneda, fecha
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id;
                SELECT opened_on INTO apertura FROM cash_book_openings
                 WHERE cash_box_id = caja AND currency = moneda;
                IF fecha < apertura THEN
                    RAISE EXCEPTION 'El movimiento bancario del % es anterior a la apertura en % del %: corresponde al circuito historico.', fecha, moneda, apertura
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

                    DB::statement('ALTER TABLE cash_book_openings DROP CONSTRAINT cash_book_openings_bank_account_fk');
                    Schema::table('cash_book_openings', function (Blueprint $table): void {
                        $table->dropColumn('bank_account_id');
                    });
                    DB::statement('ALTER TABLE bank_accounts DROP CONSTRAINT bank_accounts_id_currency_unique');

                    Schema::dropIfExists('bank_account_openings');
                }

                /**
                 * La cuenta de los libros ya abiertos, tal como la dijo su apertura.
                 *
                 * Sale de la pata `BANK_ACCOUNT` de los asientos de apertura: es la
                 * cuenta donde la caja declaró sus depósitos directos. Si un libro no
                 * declaró saldo bancario, o lo declaró en más de una cuenta, queda sin
                 * cuenta: no se adivina.
                 *
                 * La tabla es append-only; el candado se levanta solo para esta carga,
                 * dentro de la migración, y lo único que se escribe es un dato que ya
                 * estaba en el libro.
                 */
                private function backfillBookAccounts(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE cash_book_openings DISABLE TRIGGER cash_book_openings_append_only;

            UPDATE cash_book_openings o
               SET bank_account_id = c.bank_account_id
              FROM (
                    SELECT fe.cash_box_id, jl.currency, MIN(jl.bank_account_id) AS bank_account_id
                      FROM journal_lines jl
                      JOIN financial_events fe ON fe.id = jl.financial_event_id
                     WHERE fe.event_type = 'opening_balance'
                       AND fe.status IN ('posted', 'reversed')
                       AND jl.account_code = 'BANK_ACCOUNT'
                       AND jl.bank_account_id IS NOT NULL
                     GROUP BY fe.cash_box_id, jl.currency
                    HAVING COUNT(DISTINCT jl.bank_account_id) = 1
                   ) c
             WHERE c.cash_box_id = o.cash_box_id
               AND c.currency = o.currency;

            ALTER TABLE cash_book_openings ENABLE TRIGGER cash_book_openings_append_only;
        SQL);
                }

                private function guards(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_account_openings_guard() RETURNS trigger AS $$
            DECLARE v_fecha DATE;
            BEGIN
                PERFORM 1 FROM bank_accounts WHERE id = NEW.bank_account_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1
                      FROM journal_lines jl
                      JOIN financial_events fe ON fe.id = jl.financial_event_id
                     WHERE fe.event_type = 'opening_balance'
                       AND fe.status IN ('posted', 'reversed')
                       AND jl.account_code = 'BANK_ACCOUNT'
                       AND jl.bank_account_id = NEW.bank_account_id
                ) THEN
                    RAISE EXCEPTION 'La apertura de una caja ya declaro el saldo de esta cuenta: no se le declara un corte compartido.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT MIN(opened_on) INTO v_fecha
                  FROM cash_book_openings
                 WHERE bank_account_id = NEW.bank_account_id;

                IF v_fecha IS NOT NULL AND v_fecha <= NEW.cutoff_on THEN
                    RAISE EXCEPTION 'Un libro opera con esta cuenta desde el %: el corte tiene que ser anterior.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT MIN(t.transaction_date) INTO v_fecha
                  FROM bank_transactions t
                 WHERE t.bank_account_id = NEW.bank_account_id
                   AND t.transaction_date <= NEW.cutoff_on
                   AND (
                        SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                          FROM bank_transaction_allocations a
                         WHERE a.bank_transaction_id = t.id
                       ) > 0;

                IF v_fecha IS NOT NULL THEN
                    RAISE EXCEPTION 'Hay un movimiento del % ya imputado en un libro: el corte no puede incluirlo.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_account_openings_guard BEFORE INSERT ON bank_account_openings
                FOR EACH ROW EXECUTE FUNCTION bank_account_openings_guard();

            CREATE OR REPLACE FUNCTION cash_book_openings_after_bank_cutoff() RETURNS trigger AS $$
            DECLARE v_corte DATE;
            BEGIN
                IF NEW.bank_account_id IS NULL THEN RETURN NEW; END IF;

                PERFORM 1 FROM bank_accounts WHERE id = NEW.bank_account_id FOR UPDATE;

                SELECT cutoff_on INTO v_corte FROM bank_account_openings WHERE bank_account_id = NEW.bank_account_id;

                IF v_corte IS NOT NULL AND v_corte >= NEW.opened_on THEN
                    RAISE EXCEPTION 'La cuenta tiene corte al %: el libro se abre despues de esa fecha.', v_corte
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_book_openings_after_bank_cutoff BEFORE INSERT ON cash_book_openings
                FOR EACH ROW EXECUTE FUNCTION cash_book_openings_after_bank_cutoff();

            CREATE OR REPLACE FUNCTION journal_lines_opening_without_shared_bank() RETURNS trigger AS $$
            BEGIN
                IF NEW.account_code <> 'BANK_ACCOUNT' OR NEW.bank_account_id IS NULL THEN RETURN NEW; END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM financial_events
                     WHERE id = NEW.financial_event_id AND event_type = 'opening_balance'
                ) THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM bank_accounts WHERE id = NEW.bank_account_id FOR UPDATE;

                IF EXISTS (SELECT 1 FROM bank_account_openings WHERE bank_account_id = NEW.bank_account_id) THEN
                    RAISE EXCEPTION 'La cuenta tiene un corte compartido: su saldo no se declara en la apertura de una caja.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_opening_without_shared_bank BEFORE INSERT ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION journal_lines_opening_without_shared_bank();

            CREATE OR REPLACE FUNCTION bank_allocation_after_opening() RETURNS trigger AS $$
            DECLARE caja BIGINT; cuenta BIGINT; moneda CHAR(3); fecha DATE; apertura DATE; corte DATE;
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                -- Primero la caja y después la cuenta: el mismo orden que la apertura.
                SELECT cash_box_id INTO caja FROM financial_events WHERE id = NEW.financial_event_id;
                IF caja IS NOT NULL THEN
                    PERFORM 1 FROM cash_boxes WHERE id = caja FOR UPDATE;
                END IF;

                SELECT t.bank_account_id, b.currency, t.transaction_date INTO cuenta, moneda, fecha
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id;

                PERFORM 1 FROM bank_accounts WHERE id = cuenta FOR SHARE;
                SELECT cutoff_on INTO corte FROM bank_account_openings WHERE bank_account_id = cuenta;

                IF fecha <= corte THEN
                    RAISE EXCEPTION 'El movimiento bancario del % esta dentro del saldo informado al corte de la cuenta (%): no entra como dinero nuevo.', fecha, corte
                        USING ERRCODE = 'check_violation';
                END IF;

                IF caja IS NULL THEN RETURN NEW; END IF;
                SELECT opened_on INTO apertura FROM cash_book_openings
                 WHERE cash_box_id = caja AND currency = moneda;
                IF fecha < apertura THEN
                    RAISE EXCEPTION 'El movimiento bancario del % es anterior a la apertura en % del %: corresponde al circuito historico.', fecha, moneda, apertura
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }
            },

            /**
             * Un crédito viejo de la cuenta de Multas que se identifica como pago.
             *
             * Lo que entró al banco antes de la apertura de la caja de Multas está en
             * el saldo informado al corte, sin repartir: no entra al libro como dinero
             * nuevo. Si después se sabe que fue el pago de una multa, se registra como
             * pago anterior al sistema con el movimiento que lo respalda (DER físico
             * §3.8 y §4). No genera asientos.
             *
             * - Un crédito respalda un solo pago vigente, por su importe entero: un
             *   pago no se reparte en partes (confirmado por el usuario).
             * - Tiene que ser de la cuenta con la que opera la caja de Multas, anterior
             *   a su apertura, y esa apertura tiene que existir: así la frontera entre
             *   lo histórico y lo cobrado ya no se mueve.
             * - No puede estar imputado en un libro, y una vez vinculado no se imputa.
             * - La fecha del pago es la del banco y no se revisa después.
             */
            // Migración original: 2026_10_21_010000_link_fine_historical_movements_to_bank.php
            new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('fine_historical_movements', function (Blueprint $table): void {
                        /*
                         * `NO ACTION` y no `RESTRICT`: protege igual, pero responde con
                         * el código de clave foránea (23503) y no con el de las guardas
                         * propias (23001), y así la reversión de un extracto puede decir
                         * por qué no borra el movimiento.
                         */
                        $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions');
                    });

                    DB::statement("CREATE UNIQUE INDEX fine_historical_movements_bank_credit_unique
            ON fine_historical_movements (bank_transaction_id)
            WHERE bank_transaction_id IS NOT NULL AND status <> 'rejected'");

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_historical_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_tx RECORD;
                v_apertura DATE;
                v_imputado NUMERIC(19,2);
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.bank_transaction_id IS NOT NULL
                       AND (NEW.occurred_on IS DISTINCT FROM OLD.occurred_on
                            OR NEW.occurred_precision IS DISTINCT FROM OLD.occurred_precision) THEN
                        RAISE EXCEPTION 'La fecha de un pago respaldado por un credito del banco es la del banco: no se revisa.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.bank_transaction_id IS NULL THEN RETURN NEW; END IF;

                SELECT t.*, b.currency INTO v_tx
                  FROM bank_transactions t JOIN bank_accounts b ON b.id = t.bank_account_id
                 WHERE t.id = NEW.bank_transaction_id
                   FOR UPDATE OF t;

                IF v_tx.direction <> 'credit' OR NEW.kind <> 'payment' THEN
                    RAISE EXCEPTION 'Un movimiento del banco respalda un pago, y tiene que ser un credito.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.amount <> v_tx.amount THEN
                    RAISE EXCEPTION 'El pago es por el credito entero (%), no por una parte.', v_tx.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.occurred_precision <> 'day' OR NEW.occurred_on <> v_tx.transaction_date THEN
                    RAISE EXCEPTION 'La fecha del pago es la del credito: %.', v_tx.transaction_date
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT o.opened_on INTO v_apertura
                  FROM cash_book_openings o
                  JOIN cash_boxes c ON c.id = o.cash_box_id
                 WHERE c.code = 'multas'
                   AND o.currency = v_tx.currency
                   AND o.bank_account_id = v_tx.bank_account_id;

                IF v_apertura IS NULL THEN
                    RAISE EXCEPTION 'El credito no es de la cuenta con la que opera la caja de Multas, o la caja todavia no se abrio.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_tx.transaction_date >= v_apertura THEN
                    RAISE EXCEPTION 'El credito del % es posterior a la apertura de la caja de Multas (%): se cobra por el circuito, no como pago anterior.',
                        v_tx.transaction_date, v_apertura
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0) INTO v_imputado
                  FROM bank_transaction_allocations
                 WHERE bank_transaction_id = NEW.bank_transaction_id;

                IF v_imputado > 0 THEN
                    RAISE EXCEPTION 'El credito ya esta imputado en un libro: no respalda ademas un pago anterior.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_historical_movements_bank_credit
                BEFORE INSERT OR UPDATE ON fine_historical_movements
                FOR EACH ROW EXECUTE FUNCTION fine_historical_bank_credit();

            CREATE OR REPLACE FUNCTION bank_allocation_not_fine_history() RETURNS trigger AS $$
            BEGIN
                IF NEW.reversal_of_id IS NOT NULL THEN RETURN NEW; END IF;

                PERFORM 1 FROM bank_transactions WHERE id = NEW.bank_transaction_id FOR UPDATE;

                IF EXISTS (
                    SELECT 1 FROM fine_historical_movements
                     WHERE bank_transaction_id = NEW.bank_transaction_id
                       AND status <> 'rejected'
                ) THEN
                    RAISE EXCEPTION 'El movimiento respalda un pago anterior de una multa: no se imputa en un libro.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER bank_allocation_not_fine_history
                BEFORE INSERT ON bank_transaction_allocations
                FOR EACH ROW EXECUTE FUNCTION bank_allocation_not_fine_history();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS bank_allocation_not_fine_history ON bank_transaction_allocations;
            DROP FUNCTION IF EXISTS bank_allocation_not_fine_history();
            DROP TRIGGER IF EXISTS fine_historical_movements_bank_credit ON fine_historical_movements;
            DROP FUNCTION IF EXISTS fine_historical_bank_credit();
            DROP INDEX IF EXISTS fine_historical_movements_bank_credit_unique;
        SQL);

                    Schema::table('fine_historical_movements', function (Blueprint $table): void {
                        $table->dropConstrainedForeignId('bank_transaction_id');
                    });
                }
            },

            /**
             * Series y recibos por caja — Multas, DER físico §5.
             *
             * Hasta acá había una sola caja que emitía: una serie activa por tipo de
             * documento, un talonario único por tipo y un recibo que siempre nombraba a
             * una Persona. Multas numera aparte —su propio talonario de papel, su propio
             * prefijo— y extiende el recibo a la carátula del expediente, que no siempre
             * es una Persona del maestro.
             *
             * Haberes no cambia: su caja sigue exigiendo la persona, y sus series y
             * recibos quedan asignados a ella.
             */
            // Migración original: 2026_10_22_000000_scope_receipts_by_cash_box.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            -- Por defecto se exige: una caja nueva no relaja el recibo sin decidirlo.
            ALTER TABLE cash_boxes ADD COLUMN requires_receipt_person boolean NOT NULL DEFAULT true;

            -- Los dos primeros dígitos del código ya decían la caja (00, 01, 02).
            UPDATE document_series s SET cash_box_id = b.id
              FROM cash_boxes b
             WHERE s.cash_box_id IS NULL
               AND b.code = CASE substr(s.code, 1, 2)
                    WHEN '00' THEN 'haberes' WHEN '01' THEN 'aranceles' WHEN '02' THEN 'multas' END;
            ALTER TABLE document_series ALTER COLUMN cash_box_id SET NOT NULL;
            ALTER TABLE document_series ADD CONSTRAINT document_series_cash_box_fk
                FOREIGN KEY (cash_box_id) REFERENCES cash_boxes(id) ON DELETE RESTRICT;
            ALTER TABLE document_series ADD CONSTRAINT document_series_id_cash_box_unique UNIQUE (id, cash_box_id);
            DROP INDEX document_series_active_type_unique;
            CREATE UNIQUE INDEX document_series_active_type_unique ON document_series(cash_box_id, document_type)
                WHERE is_active AND origin = 'system';

            ALTER TABLE receipts ADD COLUMN cash_box_id bigint;
            UPDATE receipts r SET cash_box_id = s.cash_box_id FROM document_series s WHERE s.id = r.document_series_id;
            ALTER TABLE receipts ALTER COLUMN cash_box_id SET NOT NULL;
            ALTER TABLE receipts ADD CONSTRAINT receipts_series_cash_box_fk
                FOREIGN KEY (document_series_id, cash_box_id) REFERENCES document_series(id, cash_box_id);
            CREATE INDEX receipts_cash_box_issue_date_index ON receipts(cash_box_id, issue_date);

            -- Cada caja tiene su talonario de papel: el mismo número puede existir en dos.
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(cash_box_id, receipt_type, talonario_number)
                WHERE talonario_number IS NOT NULL;

            ALTER TABLE receipts ALTER COLUMN person_id DROP NOT NULL;

            CREATE OR REPLACE FUNCTION receipts_belong_to_cash_box() RETURNS trigger AS $$
            DECLARE v_requires boolean;
            BEGIN
                IF NEW.cash_box_id IS NULL THEN
                    SELECT cash_box_id INTO NEW.cash_box_id FROM document_series WHERE id = NEW.document_series_id;
                END IF;

                SELECT requires_receipt_person INTO v_requires FROM cash_boxes WHERE id = NEW.cash_box_id;

                IF v_requires AND NEW.person_id IS NULL THEN
                    RAISE EXCEPTION 'Los recibos de esta caja se extienden a una persona del maestro.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.person_id IS NULL AND btrim(coalesce(NEW.counterparty_name_snapshot, '')) = '' THEN
                    RAISE EXCEPTION 'Un recibo sin persona tiene que decir a quién se le extendió.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER receipts_belong_to_cash_box BEFORE INSERT ON receipts
                FOR EACH ROW EXECUTE FUNCTION receipts_belong_to_cash_box();

            CREATE OR REPLACE FUNCTION receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un comprobante no se borra: se anula y se emite el reemplazante.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.talonario_number IS DISTINCT FROM OLD.talonario_number
                    OR NEW.receipt_type IS DISTINCT FROM OLD.receipt_type
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.counterparty_name_snapshot IS DISTINCT FROM OLD.counterparty_name_snapshot
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
                    OR NEW.medium_snapshot IS DISTINCT FROM OLD.medium_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos de un comprobante no se editan: se anula y se emite otro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION document_series_preserve_history() RETURNS trigger AS $$
            DECLARE used_number bigint;
            BEGIN
                SELECT max(number) INTO used_number FROM (
                    SELECT number FROM receipts WHERE document_series_id = OLD.id
                    UNION ALL SELECT number FROM payment_orders WHERE document_series_id = OLD.id
                ) numbers;
                IF used_number IS NOT NULL AND (
                    NEW.code IS DISTINCT FROM OLD.code OR NEW.display_prefix IS DISTINCT FROM OLD.display_prefix
                    OR NEW.format_pattern IS DISTINCT FROM OLD.format_pattern
                    OR NEW.code_padding IS DISTINCT FROM OLD.code_padding OR NEW.number_padding IS DISTINCT FROM OLD.number_padding
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.origin IS DISTINCT FROM OLD.origin
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                ) THEN
                    RAISE EXCEPTION 'Una serie utilizada conserva su formato; crea una nueva version.' USING ERRCODE = 'check_violation';
                END IF;
                IF used_number IS NOT NULL AND NEW.next_number <= used_number THEN
                    RAISE EXCEPTION 'El proximo numero debe superar los comprobantes emitidos.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                public function down(): void
                {
                    if (DB::table('receipts')->whereNull('person_id')->exists()) {
                        throw new RuntimeException('Hay recibos sin persona. No se puede retirar esta migración sin perderlos.');
                    }

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_series_preserve_history() RETURNS trigger AS $$
            DECLARE used_number bigint;
            BEGIN
                SELECT max(number) INTO used_number FROM (
                    SELECT number FROM receipts WHERE document_series_id = OLD.id
                    UNION ALL SELECT number FROM payment_orders WHERE document_series_id = OLD.id
                ) numbers;
                IF used_number IS NOT NULL AND (
                    NEW.code IS DISTINCT FROM OLD.code OR NEW.display_prefix IS DISTINCT FROM OLD.display_prefix
                    OR NEW.format_pattern IS DISTINCT FROM OLD.format_pattern
                    OR NEW.code_padding IS DISTINCT FROM OLD.code_padding OR NEW.number_padding IS DISTINCT FROM OLD.number_padding
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.origin IS DISTINCT FROM OLD.origin
                ) THEN
                    RAISE EXCEPTION 'Una serie utilizada conserva su formato; crea una nueva version.' USING ERRCODE = 'check_violation';
                END IF;
                IF used_number IS NOT NULL AND NEW.next_number <= used_number THEN
                    RAISE EXCEPTION 'El proximo numero debe superar los comprobantes emitidos.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un comprobante no se borra: se anula y se emite el reemplazante.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.talonario_number IS DISTINCT FROM OLD.talonario_number
                    OR NEW.receipt_type IS DISTINCT FROM OLD.receipt_type
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
                    OR NEW.medium_snapshot IS DISTINCT FROM OLD.medium_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos de un comprobante no se editan: se anula y se emite otro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER receipts_belong_to_cash_box ON receipts;
            DROP FUNCTION receipts_belong_to_cash_box();
            ALTER TABLE receipts ALTER COLUMN person_id SET NOT NULL;
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(receipt_type, talonario_number)
                WHERE talonario_number IS NOT NULL;
            DROP INDEX receipts_cash_box_issue_date_index;
            ALTER TABLE receipts DROP CONSTRAINT receipts_series_cash_box_fk;
            ALTER TABLE receipts DROP COLUMN cash_box_id;

            DROP INDEX document_series_active_type_unique;
            CREATE UNIQUE INDEX document_series_active_type_unique ON document_series(document_type)
                WHERE is_active AND origin = 'system';
            ALTER TABLE document_series DROP CONSTRAINT document_series_id_cash_box_unique;
            ALTER TABLE document_series DROP CONSTRAINT document_series_cash_box_fk;
            ALTER TABLE document_series ALTER COLUMN cash_box_id DROP NOT NULL;

            ALTER TABLE cash_boxes DROP COLUMN requires_receipt_person;
        SQL);
                }
            },

            /**
             * Lo cobrado y aplicado a una obligación, y lo que la excede — DER físico de
             * Multas §6.4.
             *
             * Dos cuentas de atribución, no de ubicación: el dinero sigue donde estaba
             * —caja, custodia, banco— y lo que cambia es de quién se sabe que es. Por
             * eso ningún cierre las mira para cuadrar la caja.
             *
             * - `COLLECTED_FUNDS`: recaudado y aplicado a una obligación.
             * - `PENDING_EXCESS`: recibido de más; no se devuelve ni se acredita solo
             *   (M05).
             *
             * Y dos hechos: `funds_applied` (lo sin identificar pasa a una obligación) y
             * `excess_reclassified` (un ajuste de la obligación cambia cuánto de lo ya
             * cobrado es excedente). `funds_applied` y no `funds_allocated`: varios
             * triggers de Haberes leen ese tipo suponiendo una cuota detrás.
             */
            // Migración original: 2026_10_22_010000_add_collection_accounts.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_account_check;
            ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
                CHECK (account_code IN (
                    'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                    'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE',
                    'COLLECTED_FUNDS', 'PENDING_EXCESS'
                ));

            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN (
                    'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated',
                    'funds_applied', 'excess_reclassified'
                ));
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE financial_events DROP CONSTRAINT financial_events_type_check;
            ALTER TABLE financial_events ADD CONSTRAINT financial_events_type_check
                CHECK (event_type IN (
                    'funds_received', 'funds_allocated', 'cash_disbursement',
                    'cash_deposited_to_bank', 'bank_disbursement', 'cash_deposit_credited',
                    'cash_adjustment', 'opening_balance',
                    'reversal', 'authorized_adjustment', 'legacy_funds_allocated'
                ));

            ALTER TABLE journal_lines DROP CONSTRAINT journal_lines_account_check;
            ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
                CHECK (account_code IN (
                    'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                    'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE'
                ));
        SQL);
                }
            },

            /**
             * Pagos de multas, su aplicación y su recibo — DER físico de Multas §6.1–6.5.
             *
             * - `fine_payments`: el pago, presentado o recibido. Solo el aplicado toca
             *   la deuda (M02).
             * - `fine_payment_applications`: el pago tocó dinero real. Una recepción
             *   entera por pago; lo que excede la deuda es excedente, no queda sin
             *   aplicar (M05, M06).
             * - `fine_payment_receipts`: el recibo de Multas que lo documenta, con lo que
             *   decía la multa en ese momento congelado (M09).
             * - `fine_excess_reclassifications`: cuánto de lo ya cobrado pasó a ser
             *   excedente, o dejó de serlo, por un ajuste de la obligación (M05).
             *
             * El excedente asentado de cada multa —aplicaciones más reclasificaciones—
             * tiene que ser el que dice `fine_balances`. Lo impone la base al confirmar
             * cada transacción que lo pueda mover.
             */
            // Migración original: 2026_10_22_020000_create_fine_payments.php
            new class extends Migration
            {
                public function up(): void
                {
                    $this->payments();
                    $this->applications();
                    $this->receipts();
                    $this->reclassifications();
                    $this->balancesView();
                    $this->settlement();
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fund_receipts_keep_fine_applications ON fund_receipts;
            DROP TRIGGER IF EXISTS funding_allocations_not_from_multas ON funding_allocations;
            DROP FUNCTION IF EXISTS fund_receipts_keep_fine_applications();
            DROP FUNCTION IF EXISTS funding_allocations_not_from_multas();
            DROP TRIGGER IF EXISTS receipts_multas_belong_to_a_payment ON receipts;
            DROP TRIGGER IF EXISTS financial_events_fine_events_linked ON financial_events;
            DROP TRIGGER IF EXISTS fine_historical_movements_excess_settled ON fine_historical_movements;
            DROP TRIGGER IF EXISTS fine_debt_entries_excess_settled ON fine_debt_entries;
            DROP FUNCTION IF EXISTS receipts_multas_belong_to_a_payment();
            DROP FUNCTION IF EXISTS financial_events_fine_events_linked();

            CREATE OR REPLACE VIEW fine_balances AS
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

                    Schema::dropIfExists('fine_excess_reclassifications');
                    Schema::dropIfExists('fine_payment_receipts');
                    Schema::dropIfExists('fine_payment_applications');
                    Schema::dropIfExists('fine_payments');

                    DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fine_excess_settled();
            DROP FUNCTION IF EXISTS fine_payment_settled();
            DROP FUNCTION IF EXISTS fine_excess_reclassification_coherent();
            DROP FUNCTION IF EXISTS fine_payment_receipt_coherent();
            DROP FUNCTION IF EXISTS fine_payment_application_coherent();
            DROP FUNCTION IF EXISTS fine_payments_guard();
            DROP FUNCTION IF EXISTS fine_payment_rows_append_only();
            DROP FUNCTION IF EXISTS multas_cash_box_id();
        SQL);
                }

                private function payments(): void
                {
                    Schema::create('fine_payments', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
                        $table->string('medium', 20);

                        /*
                         * El origen judicial no es un medio (M02): una orden de pago
                         * judicial llega como transferencia o como cheque.
                         */
                        $table->boolean('judicial_origin')->default(false);
                        $table->string('judicial_reference', 120)->nullable();

                        $table->string('status', 20);
                        /** Para un cheque, el importe del instrumento entero. */
                        $table->decimal('amount', 19, 2);

                        /*
                         * Cuándo se presentó el comprobante, con la precisión que tenga, y
                         * cuándo se cargó. El término se juzga por la presentación (M08);
                         * la carga es evidencia. Una presentación anterior a la carga
                         * lleva su respaldo.
                         */
                        $table->timestampTz('presented_at')->nullable();
                        $table->string('presented_precision', 10);
                        $table->string('presentation_evidence', 300)->nullable();
                        $table->timestampTz('reported_at')->useCurrent();
                        /** El día en que el sancionado dice haber pagado por el banco. */
                        $table->date('declared_paid_on')->nullable();

                        /*
                         * «Recibí de»: la carátula de la multa (M09). La persona es un
                         * vínculo opcional, no una condición para cobrar.
                         */
                        $table->foreignId('payer_person_id')->nullable()->constrained('people')->restrictOnDelete();
                        $table->string('payer_name', 300);

                        /*
                         * Pago por recurso (M07): el 20 % de la obligación vigente al
                         * registrarlo, sin centavos, con la base congelada. El recibo
                         * externo que lo acompaña dice su propio importe.
                         */
                        $table->string('appeal_kind', 20)->nullable();
                        $table->decimal('appeal_base_amount', 19, 2)->nullable();
                        $table->decimal('appeal_required_amount', 19, 2)->nullable();
                        $table->string('external_receipt_reference', 120)->nullable();
                        $table->decimal('external_receipt_amount', 19, 2)->nullable();

                        /** Quien resolvió la diferencia de un recurso, y por qué. */
                        $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('reviewed_at')->nullable();
                        $table->string('review_reason', 500)->nullable();

                        $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('discarded_at')->nullable();
                        $table->string('discard_reason', 500)->nullable();

                        $table->string('idempotency_key', 100)->unique();
                        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampsTz();

                        $table->unique(['id', 'fine_id']);
                        $table->index(['fine_id', 'status']);
                        $table->index('status');
                    });

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_medium_check
                    CHECK (medium IN ('cash', 'cheque', 'bank_transfer', 'direct_deposit', 'echeq')),
                ADD CONSTRAINT fine_payments_status_check
                    CHECK (status IN ('reported', 'applied', 'discarded', 'under_review')),
                ADD CONSTRAINT fine_payments_amount_check CHECK (amount > 0),
                ADD CONSTRAINT fine_payments_payer_check CHECK (btrim(payer_name) <> ''),
                ADD CONSTRAINT fine_payments_judicial_check CHECK (
                    judicial_origin = (judicial_reference IS NOT NULL)
                    AND (judicial_reference IS NULL OR btrim(judicial_reference) <> '')
                    AND (NOT judicial_origin OR medium IN ('bank_transfer', 'cheque'))
                ),
                ADD CONSTRAINT fine_payments_presentation_check CHECK (
                    presented_precision IN ('instant', 'day', 'unknown')
                    AND (presented_precision = 'unknown') = (presented_at IS NULL)
                    AND (presented_at IS NULL OR presented_at <= reported_at)
                    -- Presentado en el momento de cargarlo, o con respaldo.
                    AND ((presented_precision = 'instant' AND presented_at = reported_at)
                         OR btrim(coalesce(presentation_evidence, '')) <> '')
                ),
                ADD CONSTRAINT fine_payments_declared_paid_check
                    CHECK (declared_paid_on IS NULL OR medium NOT IN ('cash', 'cheque')),
                ADD CONSTRAINT fine_payments_appeal_check CHECK (
                    num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount,
                        external_receipt_reference, external_receipt_amount) IN (0, 5)
                    AND (appeal_kind IS NULL OR (
                        appeal_kind IN ('hierarchical', 'appeal')
                        AND medium IN ('cash', 'direct_deposit')
                        AND NOT judicial_origin
                        AND btrim(external_receipt_reference) <> ''
                        AND external_receipt_amount > 0
                        -- Pesos enteros, a favor del deudor (M07).
                        AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                        AND amount = appeal_required_amount
                    ))
                ),
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    -- Solo un recurso con diferencia queda en revisión, y no sale sin resolver.
                    AND (status <> 'under_review' OR (appeal_kind IS NOT NULL AND external_receipt_amount <> amount))
                    AND (appeal_kind IS NULL OR external_receipt_amount = amount
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                ),
                ADD CONSTRAINT fine_payments_discard_check CHECK (
                    num_nonnulls(discarded_by, discarded_at, discard_reason) IN (0, 3)
                    AND (status = 'discarded') = (discard_reason IS NOT NULL)
                    AND (discard_reason IS NULL OR btrim(discard_reason) <> '')
                );

            CREATE OR REPLACE FUNCTION multas_cash_box_id() RETURNS bigint AS $$
                SELECT id FROM cash_boxes WHERE code = 'multas'
            $$ LANGUAGE sql STABLE;

            /*
             * Un pago nace aplicado si se cobró en la caja, o informado si
             * espera un crédito del banco. Después solo avanza: lo aplicado y
             * lo descartado ya no cambian (P13).
             */
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
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
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payments_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_payments
                FOR EACH ROW EXECUTE FUNCTION fine_payments_guard();

            CREATE OR REPLACE FUNCTION fine_payment_rows_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Esta fila documenta un cobro: no se edita ni se borra.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                private function applications(): void
                {
                    Schema::create('fine_payment_applications', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('fine_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
                        $table->unsignedBigInteger('fine_id');
                        $table->foreignId('fund_receipt_id')->unique()->constrained('fund_receipts')->restrictOnDelete();
                        $table->foreignId('application_event_id')->unique()->constrained('financial_events')->restrictOnDelete();

                        /** La recepción entera: lo que cubre la deuda y lo que la excede. */
                        $table->decimal('amount', 19, 2);
                        $table->decimal('applied_amount', 19, 2);
                        $table->decimal('excess_amount', 19, 2);

                        /*
                         * El término, juzgado una vez al aplicar contra el cierre mensual
                         * del mes de registro de la multa (M08, DER §6.5). Cita la versión
                         * de la evidencia del cierre: una reapertura posterior no lo cambia.
                         */
                        $table->string('timeliness', 20);
                        $table->foreignId('origin_closing_evidence_id')->nullable()
                            ->constrained('period_closing_evidence')->restrictOnDelete();
                        $table->timestampTz('origin_closed_at')->nullable();

                        $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('applied_at')->useCurrent();

                        $table->foreign(['fine_payment_id', 'fine_id'])->references(['id', 'fine_id'])->on('fine_payments');
                        $table->index('fine_id');
                    });

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_applications
                ADD CONSTRAINT fine_payment_applications_amounts_check CHECK (
                    amount > 0 AND applied_amount >= 0 AND excess_amount >= 0
                    AND applied_amount + excess_amount = amount
                ),
                ADD CONSTRAINT fine_payment_applications_timeliness_check CHECK (
                    timeliness IN ('on_time', 'late', 'undetermined')
                    AND (origin_closing_evidence_id IS NULL) = (origin_closed_at IS NULL)
                    AND (timeliness <> 'late' OR origin_closing_evidence_id IS NOT NULL)
                );

            /*
             * El pago, la recepción y el asiento cuentan lo mismo: la recepción
             * entera, de la caja de Multas, en la moneda de la multa; y el
             * asiento la pasa de sin identificar a aplicado y excedente.
             */
            CREATE OR REPLACE FUNCTION fine_payment_application_coherent() RETURNS trigger AS $$
            DECLARE
                v_pago RECORD;
                v_recepcion RECORD;
                v_evento RECORD;
                v_moneda char(3);
                v_medio text;
                v_lineas RECORD;
            BEGIN
                SELECT status, amount, medium INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;
                IF v_pago.status <> 'applied' THEN
                    RAISE EXCEPTION 'Solo se aplica un pago marcado como aplicado.' USING ERRCODE = 'check_violation';
                END IF;
                IF v_pago.amount <> NEW.amount THEN
                    RAISE EXCEPTION 'La aplicación es por el pago entero: % y no %.', v_pago.amount, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT currency INTO v_moneda FROM fines WHERE id = NEW.fine_id;
                SELECT amount, origin, currency, cash_box_id, medium INTO v_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                v_medio := CASE WHEN v_pago.medium IN ('cash', 'cheque') THEN v_pago.medium ELSE 'bank' END;

                IF v_recepcion.cash_box_id IS DISTINCT FROM multas_cash_box_id()
                    OR v_recepcion.origin <> 'received'
                    OR v_recepcion.currency <> v_moneda
                    OR v_recepcion.medium <> v_medio
                    OR v_recepcion.amount <> NEW.amount THEN
                    RAISE EXCEPTION 'La recepción no es la de este pago: tiene que ser de la caja de Multas, en la moneda de la multa, por el mismo medio y el importe entero.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT event_type, status, cash_box_id INTO v_evento FROM financial_events WHERE id = NEW.application_event_id;
                IF v_evento.event_type <> 'funds_applied' OR v_evento.status <> 'posted'
                    OR v_evento.cash_box_id IS DISTINCT FROM multas_cash_box_id() THEN
                    RAISE EXCEPTION 'La aplicación se asienta como fondos aplicados de la caja de Multas.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(debit) FILTER (WHERE account_code = 'UNASSIGNED_FUNDS'), 0) AS sin_identificar,
                       COALESCE(SUM(credit) FILTER (WHERE account_code = 'COLLECTED_FUNDS'), 0) AS aplicado,
                       COALESCE(SUM(credit) FILTER (WHERE account_code = 'PENDING_EXCESS'), 0) AS excedente,
                       COUNT(*) FILTER (WHERE currency <> v_moneda
                           OR account_code NOT IN ('UNASSIGNED_FUNDS', 'COLLECTED_FUNDS', 'PENDING_EXCESS')
                           OR (account_code = 'UNASSIGNED_FUNDS' AND credit > 0)
                           OR (account_code <> 'UNASSIGNED_FUNDS' AND debit > 0)) AS ajenas
                  INTO v_lineas
                  FROM journal_lines WHERE financial_event_id = NEW.application_event_id;

                IF v_lineas.ajenas > 0 OR v_lineas.sin_identificar <> NEW.amount
                    OR v_lineas.aplicado <> NEW.applied_amount OR v_lineas.excedente <> NEW.excess_amount THEN
                    RAISE EXCEPTION 'El asiento no coincide con la aplicación: debita lo sin identificar y acredita lo aplicado y el excedente.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_applications_coherent BEFORE INSERT ON fine_payment_applications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_application_coherent();
            CREATE TRIGGER fine_payment_applications_append_only BEFORE UPDATE OR DELETE ON fine_payment_applications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);
                }

                private function receipts(): void
                {
                    Schema::create('fine_payment_receipts', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('fine_payment_id')->unique()->constrained('fine_payments')->restrictOnDelete();
                        $table->foreignId('receipt_id')->unique()->constrained('receipts')->restrictOnDelete();
                        /** El del pago que dejó la deuda en cero (M09). */
                        $table->boolean('is_cancellation');
                        /** Lo que el recibo dice de la multa, congelado al emitirlo. */
                        $table->jsonb('snapshot');
                        $table->timestampTz('created_at')->useCurrent();
                    });

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_receipts ADD CONSTRAINT fine_payment_receipts_snapshot_check
                CHECK (jsonb_typeof(snapshot) = 'object');

            CREATE OR REPLACE FUNCTION fine_payment_receipt_coherent() RETURNS trigger AS $$
            DECLARE
                v_pago RECORD;
                v_recibo RECORD;
            BEGIN
                SELECT status, amount INTO v_pago FROM fine_payments WHERE id = NEW.fine_payment_id;
                SELECT cash_box_id, receipt_type, amount, status INTO v_recibo FROM receipts WHERE id = NEW.receipt_id;

                IF v_pago.status <> 'applied' THEN
                    RAISE EXCEPTION 'Solo un pago aplicado lleva recibo.' USING ERRCODE = 'check_violation';
                END IF;
                IF v_recibo.cash_box_id IS DISTINCT FROM multas_cash_box_id() OR v_recibo.receipt_type <> 'income'
                    OR v_recibo.status <> 'issued' OR v_recibo.amount <> v_pago.amount THEN
                    RAISE EXCEPTION 'El recibo de un pago es un recibo de ingreso de Multas, vigente y por el importe del pago.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_receipts_coherent BEFORE INSERT ON fine_payment_receipts
                FOR EACH ROW EXECUTE FUNCTION fine_payment_receipt_coherent();
            CREATE TRIGGER fine_payment_receipts_append_only BEFORE UPDATE OR DELETE ON fine_payment_receipts
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);
                }

                private function reclassifications(): void
                {
                    Schema::create('fine_excess_reclassifications', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
                        $table->foreignId('event_id')->unique()->constrained('financial_events')->restrictOnDelete();
                        /** Positivo: lo aplicado pasa a excedente. Negativo: al revés. */
                        $table->decimal('amount', 19, 2);
                        $table->string('reason', 300);
                        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                        $table->timestampTz('created_at')->useCurrent();

                        $table->index('fine_id');
                    });

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_excess_reclassifications
                ADD CONSTRAINT fine_excess_reclassifications_amount_check CHECK (amount <> 0),
                ADD CONSTRAINT fine_excess_reclassifications_reason_check CHECK (btrim(reason) <> '');

            CREATE OR REPLACE FUNCTION fine_excess_reclassification_coherent() RETURNS trigger AS $$
            DECLARE
                v_evento RECORD;
                v_moneda char(3);
                v_desde text;
                v_hacia text;
                v_lineas RECORD;
            BEGIN
                SELECT currency INTO v_moneda FROM fines WHERE id = NEW.fine_id;
                SELECT event_type, status, cash_box_id INTO v_evento FROM financial_events WHERE id = NEW.event_id;

                IF v_evento.event_type <> 'excess_reclassified' OR v_evento.status <> 'posted'
                    OR v_evento.cash_box_id IS DISTINCT FROM multas_cash_box_id() THEN
                    RAISE EXCEPTION 'La reclasificación se asienta como excedente reclasificado de la caja de Multas.'
                        USING ERRCODE = 'check_violation';
                END IF;

                v_desde := CASE WHEN NEW.amount > 0 THEN 'COLLECTED_FUNDS' ELSE 'PENDING_EXCESS' END;
                v_hacia := CASE WHEN NEW.amount > 0 THEN 'PENDING_EXCESS' ELSE 'COLLECTED_FUNDS' END;

                SELECT COALESCE(SUM(debit) FILTER (WHERE account_code = v_desde), 0) AS debito,
                       COALESCE(SUM(credit) FILTER (WHERE account_code = v_hacia), 0) AS credito,
                       COUNT(*) FILTER (WHERE currency <> v_moneda
                           OR NOT ((account_code = v_desde AND debit > 0) OR (account_code = v_hacia AND credit > 0))) AS ajenas
                  INTO v_lineas
                  FROM journal_lines WHERE financial_event_id = NEW.event_id;

                IF v_lineas.ajenas > 0 OR v_lineas.debito <> abs(NEW.amount) OR v_lineas.credito <> abs(NEW.amount) THEN
                    RAISE EXCEPTION 'El asiento no coincide con la reclasificación: pasa % de % a %.', abs(NEW.amount), v_desde, v_hacia
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_excess_reclassifications_coherent BEFORE INSERT ON fine_excess_reclassifications
                FOR EACH ROW EXECUTE FUNCTION fine_excess_reclassification_coherent();
            CREATE TRIGGER fine_excess_reclassifications_append_only BEFORE UPDATE OR DELETE ON fine_excess_reclassifications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_rows_append_only();
        SQL);
                }

                /** Lo pagado en el sistema es lo aplicado: cada recepción entera. */
                private function balancesView(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                       ), 0)::numeric(19,2) AS operational_paid,
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

                /**
                 * Lo que se verifica al confirmar, cuando ya está todo escrito.
                 */
                private function settlement(): void
                {
                    DB::unprepared(<<<'SQL'
            /*
             * Un pago aplicado tiene su aplicación y su recibo; uno que no lo
             * está no tiene ninguno de los dos.
             */
            CREATE OR REPLACE FUNCTION fine_payment_settled() RETURNS trigger AS $$
            DECLARE
                v_aplicado boolean;
                v_aplicacion boolean;
                v_recibo boolean;
            BEGIN
                SELECT status = 'applied' INTO v_aplicado FROM fine_payments WHERE id = NEW.id;
                v_aplicacion := EXISTS (SELECT 1 FROM fine_payment_applications WHERE fine_payment_id = NEW.id);
                v_recibo := EXISTS (SELECT 1 FROM fine_payment_receipts WHERE fine_payment_id = NEW.id);

                IF v_aplicado AND NOT (v_aplicacion AND v_recibo) THEN
                    RAISE EXCEPTION 'El pago % quedó aplicado sin su aplicación o sin su recibo.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NOT v_aplicado AND (v_aplicacion OR v_recibo) THEN
                    RAISE EXCEPTION 'El pago % tiene aplicación o recibo sin estar aplicado.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_payments_settled AFTER INSERT OR UPDATE ON fine_payments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_payment_settled();

            /*
             * El excedente asentado es el que dice el saldo (M05).
             *
             * Asentado: lo que cada aplicación mandó a excedente más lo que las
             * reclasificaciones movieron. Calculado: el `operational_excess` de
             * `fine_balances`. Cualquier hecho que cambie la obligación, lo
             * pagado o los antecedentes tiene que llegar al cierre de la
             * transacción con los dos iguales.
             */
            CREATE OR REPLACE FUNCTION fine_excess_settled() RETURNS trigger AS $$
            DECLARE
                v_asentado numeric(19,2);
                v_calculado numeric(19,2);
            BEGIN
                v_asentado := COALESCE((SELECT SUM(excess_amount) FROM fine_payment_applications WHERE fine_id = NEW.fine_id), 0)
                    + COALESCE((SELECT SUM(amount) FROM fine_excess_reclassifications WHERE fine_id = NEW.fine_id), 0);
                SELECT operational_excess INTO v_calculado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF v_asentado <> v_calculado THEN
                    RAISE EXCEPTION 'El excedente asentado de la multa % es % y su saldo dice %: falta reclasificar.',
                        NEW.fine_id, v_asentado, v_calculado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_payment_applications_excess_settled AFTER INSERT ON fine_payment_applications
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();
            CREATE CONSTRAINT TRIGGER fine_excess_reclassifications_excess_settled AFTER INSERT ON fine_excess_reclassifications
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();
            CREATE CONSTRAINT TRIGGER fine_debt_entries_excess_settled AFTER INSERT ON fine_debt_entries
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();
            CREATE CONSTRAINT TRIGGER fine_historical_movements_excess_settled AFTER INSERT OR UPDATE ON fine_historical_movements
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_excess_settled();

            /*
             * Los dos hechos nuevos del libro son de Multas: ninguno queda
             * asentado sin la fila que dice a qué multa corresponde.
             */
            CREATE OR REPLACE FUNCTION financial_events_fine_events_linked() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'funds_applied' AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE application_event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Los fondos aplicados del evento % no corresponden a ningún pago.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.event_type = 'excess_reclassified' AND NOT EXISTS (
                    SELECT 1 FROM fine_excess_reclassifications WHERE event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La reclasificación del evento % no corresponde a ninguna multa.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER financial_events_fine_events_linked AFTER INSERT ON financial_events
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                WHEN (NEW.event_type IN ('funds_applied', 'excess_reclassified'))
                EXECUTE FUNCTION financial_events_fine_events_linked();

            /* Un recibo de la caja de Multas documenta un pago de una multa. */
            CREATE OR REPLACE FUNCTION receipts_multas_belong_to_a_payment() RETURNS trigger AS $$
            BEGIN
                IF NEW.cash_box_id = multas_cash_box_id() AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_receipts WHERE receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'El recibo % de Multas no documenta ningún pago.', NEW.formatted_number
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER receipts_multas_belong_to_a_payment AFTER INSERT ON receipts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
                EXECUTE FUNCTION receipts_multas_belong_to_a_payment();

            /*
             * Lo cobrado para una multa no financia una cuota de Haberes: es
             * otra caja y otra obligación.
             */
            CREATE OR REPLACE FUNCTION funding_allocations_not_from_multas() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts
                     WHERE id = NEW.fund_receipt_id AND cash_box_id = multas_cash_box_id()
                ) THEN
                    RAISE EXCEPTION 'Una recepción de la caja de Multas no se asigna a una cuota de Haberes.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER funding_allocations_not_from_multas BEFORE INSERT ON funding_allocations
                FOR EACH ROW EXECUTE FUNCTION funding_allocations_not_from_multas();

            /*
             * Un cobro ya aplicado a una multa no se revierte por su cuenta:
             * dejaría la aplicación y el recibo sin dinero detrás. Anular un
             * pago es un circuito todavía sin definir (P13).
             */
            CREATE OR REPLACE FUNCTION fund_receipts_keep_fine_applications() RETURNS trigger AS $$
            BEGIN
                IF OLD.reversal_event_id IS NULL AND NEW.reversal_event_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE fund_receipt_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La recepción % está aplicada al pago de una multa: no se revierte.', NEW.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fund_receipts_keep_fine_applications BEFORE UPDATE OF reversal_event_id ON fund_receipts
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_keep_fine_applications();
        SQL);
                }
            },

            /**
             * Pagos de multas por el banco y recurso del 20 % — DER físico de Multas §6.2 y §8.
             *
             * - Un pago bancario se aplica con **un crédito** del extracto, por su
             *   importe entero, y ese crédito no respalda el pago de otra multa (M06,
             *   P14 provisorio).
             * - El comprobante de un pago se adjunta al pago (`fine_payment`).
             * - `multas.resolver-recurso` no se delega (M07): la guarda de la base suma
             *   el permiso a su lista.
             */
            // Migración original: 2026_10_22_030000_add_fine_bank_payments_and_appeals.php
            new class extends Migration
            {
                private const ADJUNTOS_ANTERIORES = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'bank_account_opening'";

                private const NO_DELEGABLES_ANTERIORES = [
                    'roles.gestionar',
                    'usuarios.crear',
                    'usuarios.editar',
                    'usuarios.desactivar',
                    'usuarios.restablecer-password',
                ];

                private const ROLES_PERMITIDOS = ['administrador', 'super-admin'];

                public function up(): void
                {
                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ADJUNTOS_ANTERIORES.", 'fine_payment'))");

                    DB::unprepared(<<<'SQL'
            /*
             * Una transferencia, una multa (M06), con el crédito entero (P14).
             *
             * La recepción del pago está respaldada por una sola imputación
             * vigente del extracto, por el importe completo del crédito, y ese
             * crédito no respalda la aplicación de otro pago. El crédito se
             * bloquea para que dos verificaciones no lo tomen a la vez.
             */
            CREATE OR REPLACE FUNCTION fine_payment_application_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_medio text;
                v_evento bigint;
                v_imputaciones int;
                v_credito bigint;
                v_importe numeric(19,2);
            BEGIN
                SELECT medium INTO v_medio FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF v_medio NOT IN ('bank_transfer', 'direct_deposit', 'echeq') THEN
                    RETURN NEW;
                END IF;

                SELECT financial_event_id INTO v_evento FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT count(*), min(bank_transaction_id) INTO v_imputaciones, v_credito
                  FROM bank_transaction_allocations
                 WHERE financial_event_id = v_evento AND reversal_of_id IS NULL;

                IF v_imputaciones <> 1 THEN
                    RAISE EXCEPTION 'Un pago por el banco se aplica con un solo crédito del extracto.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT amount INTO v_importe FROM bank_transactions WHERE id = v_credito FOR UPDATE;

                IF v_importe <> NEW.amount THEN
                    RAISE EXCEPTION 'El pago es por el crédito entero: % y no %.', v_importe, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM fine_payment_applications a
                      JOIN fund_receipts r ON r.id = a.fund_receipt_id
                      JOIN bank_transaction_allocations b
                        ON b.financial_event_id = r.financial_event_id AND b.reversal_of_id IS NULL
                     WHERE b.bank_transaction_id = v_credito
                ) THEN
                    RAISE EXCEPTION 'Ese crédito ya respalda el pago de una multa: una transferencia es de una sola multa.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_applications_bank_credit BEFORE INSERT ON fine_payment_applications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_application_bank_credit();
        SQL);

                    $this->guardarNoDelegables([...self::NO_DELEGABLES_ANTERIORES, 'multas.resolver-recurso']);
                }

                public function down(): void
                {
                    $this->guardarNoDelegables(self::NO_DELEGABLES_ANTERIORES);

                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fine_payment_applications_bank_credit ON fine_payment_applications;
            DROP FUNCTION IF EXISTS fine_payment_application_bank_credit();
        SQL);

                    if (DB::table('attachments')->where('subject_type', 'fine_payment')->exists()) {
                        throw new RuntimeException('Hay comprobantes adjuntos a pagos de multas. No se puede retirar esta migración sin perderlos.');
                    }

                    DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
                    DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ADJUNTOS_ANTERIORES.'))');
                }

                /**
                 * Redefine la guarda de 2026_10_12_010000 con otra lista. Como allá, si un
                 * rol de la matriz ya tiene alguno, frena en vez de quitárselo en silencio.
                 *
                 * @param  list<string>  $reservados
                 */
                private function guardarNoDelegables(array $reservados): void
                {
                    $infractores = DB::table('role_has_permissions as rp')
                        ->join('roles as r', 'r.id', '=', 'rp.role_id')
                        ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                        ->whereIn('p.name', $reservados)
                        ->whereNotIn('r.name', self::ROLES_PERMITIDOS)
                        ->orderBy('r.name')
                        ->selectRaw("r.name || ' (' || p.name || ')' AS detalle")
                        ->pluck('detalle')
                        ->implode(', ');

                    if ($infractores !== '') {
                        throw new RuntimeException(
                            "Estos roles tienen permisos que ya no se delegan: {$infractores}. "
                            .'Quitáselos desde Configuración → Roles —queda en la auditoría— y volvé a migrar.'
                        );
                    }

                    $lista = fn (array $valores): string => implode(', ', array_map(fn (string $v): string => "'{$v}'", $valores));

                    DB::statement(
                        'CREATE OR REPLACE FUNCTION role_has_permissions_guard_non_delegable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name IN ('.$lista($reservados).'))
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name IN ('.$lista(self::ROLES_PERMITIDOS).')) THEN
                    RAISE EXCEPTION \'Este permiso no se delega: lo tiene solo el administrador.\'
                        USING ERRCODE = \'check_violation\';
                END IF;

                RETURN NEW;
            END;
            $$'
                    );
                }
            },

            /**
             * Un recurso repetido va a revisión — P05 provisorio (DER físico de Multas §10).
             *
             * Hasta que el área defina si se admiten recursos repetidos sobre la misma
             * multa, el segundo no se cobra solo aunque su recibo externo coincida: queda
             * `under_review` y lo resuelve el administrador, como una diferencia de
             * importe. Un intento descartado no cuenta como recurso anterior.
             *
             * `appeal_repeated` lo dice la fila y lo comprueba la base al insertar, con la
             * multa bloqueada: no depende de que el Action haya mirado.
             */
            // Migración original: 2026_10_22_040000_review_repeated_fine_appeals.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments ADD COLUMN appeal_repeated boolean NOT NULL DEFAULT false;

            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_appeal_repeated_check CHECK (NOT appeal_repeated OR appeal_kind IS NOT NULL),
                DROP CONSTRAINT fine_payments_review_check,
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    -- En revisión queda un recurso con diferencia o repetido, y no sale sin resolver.
                    AND (status <> 'under_review'
                         OR (appeal_kind IS NOT NULL AND (external_receipt_amount <> amount OR appeal_repeated)))
                    AND (appeal_kind IS NULL OR (external_receipt_amount = amount AND NOT appeal_repeated)
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                );

            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
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
                        -- P05 provisorio: un recurso sobre una multa que ya tiene otro
                        -- vigente va a revisión. Un intento descartado no cuenta.
                        IF NEW.appeal_repeated IS DISTINCT FROM EXISTS (
                            SELECT 1 FROM fine_payments
                             WHERE fine_id = NEW.fine_id AND appeal_kind IS NOT NULL AND status <> 'discarded'
                        ) THEN
                            RAISE EXCEPTION 'Un recurso es repetido si la multa ya tiene otro que no se descartó, y entonces va a revisión.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                public function down(): void
                {
                    if (DB::table('fine_payments')->where('appeal_repeated', true)->exists()) {
                        throw new RuntimeException('Hay recursos marcados como repetidos. No se puede retirar esta migración sin perder esa marca.');
                    }

                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
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
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_review_check,
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    AND (status <> 'under_review' OR (appeal_kind IS NOT NULL AND external_receipt_amount <> amount))
                    AND (appeal_kind IS NULL OR external_receipt_amount = amount
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                ),
                DROP CONSTRAINT fine_payments_appeal_repeated_check;
            ALTER TABLE fine_payments DROP COLUMN appeal_repeated;
        SQL);
                }
            },

            /**
             * El recurso del 20 %, como lo definió el usuario el 21/10/2026.
             *
             * - **Se cobra lo que dice el recibo externo.** La liquidación del 20 % la
             *   hace otra oficina: el importe del pago es el de su recibo, y el 20 %
             *   calculado sobre el importe vigente queda al lado, como referencia. Una
             *   diferencia no frena el cobro. Reemplaza la regla anterior de M07
             *   («exactamente el 20 %», revisión del administrador si difiere).
             * - **Una multa admite un solo recurso.** Un segundo se rechaza; un intento
             *   descartado no cuenta. Cierra P05.
             *
             * Sin diferencia que revisar ni recurso repetido, ya nada queda en revisión:
             * se retiran el estado `under_review`, la resolución y sus columnas, y la
             * marca de recurso repetido.
             *
             * Y un pago informado que se cargó mal se **corrige**: se descarta con motivo
             * y el corregido apunta al que reemplaza (`replaces_fine_payment_id`).
             */
            // Migración original: 2026_10_22_050000_take_fine_appeal_amount_from_external_receipt.php
            new class extends Migration
            {
                private const NO_DELEGABLES = [
                    'roles.gestionar',
                    'usuarios.crear',
                    'usuarios.editar',
                    'usuarios.desactivar',
                    'usuarios.restablecer-password',
                ];

                public function up(): void
                {
                    if (DB::table('fine_payments')->where('status', 'under_review')->exists()) {
                        throw new RuntimeException('Hay recursos en revisión. Resolvelos o descartalos antes de migrar.');
                    }

                    DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_review_check,
                DROP CONSTRAINT fine_payments_appeal_repeated_check,
                DROP CONSTRAINT fine_payments_appeal_check,
                DROP CONSTRAINT fine_payments_status_check;
            ALTER TABLE fine_payments
                DROP COLUMN appeal_repeated,
                DROP COLUMN external_receipt_amount,
                DROP COLUMN reviewed_by,
                DROP COLUMN reviewed_at,
                DROP COLUMN review_reason,
                ADD COLUMN replaces_fine_payment_id bigint UNIQUE REFERENCES fine_payments(id) ON DELETE RESTRICT;

            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_status_check CHECK (status IN ('reported', 'applied', 'discarded')),
                ADD CONSTRAINT fine_payments_appeal_check CHECK (
                    num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount, external_receipt_reference) IN (0, 4)
                    AND (appeal_kind IS NULL OR (
                        appeal_kind IN ('hierarchical', 'appeal')
                        AND medium IN ('cash', 'direct_deposit')
                        AND NOT judicial_origin
                        AND btrim(external_receipt_reference) <> ''
                        -- El 20 % calculado, pesos enteros a favor del deudor: la referencia.
                        AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                    ))
                );

            -- Una multa, un recurso vigente (U, 21/10/2026).
            CREATE UNIQUE INDEX fine_payments_one_appeal_per_fine ON fine_payments (fine_id)
                WHERE appeal_kind IS NOT NULL AND status <> 'discarded';

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
        SQL);

                    // Sin revisión del recurso, su permiso sale del catálogo y de la guarda.
                    $this->guardarNoDelegables(self::NO_DELEGABLES);
                }

                public function down(): void
                {
                    $this->guardarNoDelegables([...self::NO_DELEGABLES, 'multas.resolver-recurso']);

                    DB::unprepared(<<<'SQL'
            DROP INDEX fine_payments_one_appeal_per_fine;
            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_appeal_check,
                DROP CONSTRAINT fine_payments_status_check;
            ALTER TABLE fine_payments
                DROP COLUMN replaces_fine_payment_id,
                ADD COLUMN appeal_repeated boolean NOT NULL DEFAULT false,
                ADD COLUMN external_receipt_amount numeric(19,2),
                ADD COLUMN reviewed_by bigint REFERENCES users(id) ON DELETE SET NULL,
                ADD COLUMN reviewed_at timestamptz,
                ADD COLUMN review_reason varchar(500);
            UPDATE fine_payments SET external_receipt_amount = amount WHERE appeal_kind IS NOT NULL;
            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_status_check
                    CHECK (status IN ('reported', 'applied', 'discarded', 'under_review')),
                ADD CONSTRAINT fine_payments_appeal_check CHECK (
                    num_nonnulls(appeal_kind, appeal_base_amount, appeal_required_amount,
                        external_receipt_reference, external_receipt_amount) IN (0, 5)
                    AND (appeal_kind IS NULL OR (
                        appeal_kind IN ('hierarchical', 'appeal')
                        AND medium IN ('cash', 'direct_deposit')
                        AND NOT judicial_origin
                        AND btrim(external_receipt_reference) <> ''
                        AND external_receipt_amount > 0
                        AND appeal_required_amount = trunc(appeal_base_amount * 0.20, 0)
                        AND amount = appeal_required_amount
                    ))
                ) NOT VALID,
                ADD CONSTRAINT fine_payments_appeal_repeated_check CHECK (NOT appeal_repeated OR appeal_kind IS NOT NULL),
                ADD CONSTRAINT fine_payments_review_check CHECK (
                    num_nonnulls(reviewed_by, reviewed_at, review_reason) IN (0, 3)
                    AND (review_reason IS NULL OR btrim(review_reason) <> '')
                    AND (status <> 'under_review'
                         OR (appeal_kind IS NOT NULL AND (external_receipt_amount <> amount OR appeal_repeated)))
                    AND (appeal_kind IS NULL OR (external_receipt_amount = amount AND NOT appeal_repeated)
                         OR status IN ('under_review', 'discarded') OR reviewed_by IS NOT NULL)
                ) NOT VALID;

            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS $$
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
                        -- P05 provisorio: un recurso sobre una multa que ya tiene otro
                        -- vigente va a revisión. Un intento descartado no cuenta.
                        IF NEW.appeal_repeated IS DISTINCT FROM EXISTS (
                            SELECT 1 FROM fine_payments
                             WHERE fine_id = NEW.fine_id AND appeal_kind IS NOT NULL AND status <> 'discarded'
                        ) THEN
                            RAISE EXCEPTION 'Un recurso es repetido si la multa ya tiene otro que no se descartó, y entonces va a revisión.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at'])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY['status', 'reviewed_by', 'reviewed_at', 'review_reason',
                        'discarded_by', 'discarded_at', 'discard_reason', 'updated_at']) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.reviewed_by IS NOT NULL AND (NEW.reviewed_by, NEW.reviewed_at, NEW.review_reason)
                        IS DISTINCT FROM (OLD.reviewed_by, OLD.reviewed_at, OLD.review_reason) THEN
                    RAISE EXCEPTION 'Una resolución ya registrada no se cambia.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                    OR (OLD.status = 'under_review' AND NEW.status = 'discarded')
                    OR (OLD.status = 'under_review' AND NEW.status = 'applied' AND NEW.medium = 'cash')
                    OR (OLD.status = 'under_review' AND NEW.status = 'reported' AND NEW.medium = 'direct_deposit')
                ) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
                }

                /**
                 * La guarda de 2026_10_12_010000 con la lista de permisos no delegables.
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
            },
        ];
    }
};
