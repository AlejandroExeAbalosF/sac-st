<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expediente, haber y cuota — §9.2 del DER.
 *
 * Es el derecho reconocido, no el dinero: acá no se registra ni un peso.
 * La recepción, la Orden y el egreso viven en sus propias tablas, y esa
 * separación es la primera regla del DER.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->createLabels();
        $this->createExpedientes();
        $this->createHaberes();
        $this->createInstallments();

        // Va al final: la guarda mira las dos tablas y necesita que ambas
        // existan.
        $this->sumaDeCuotasNoSuperaElHaber();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS beneficiary_installments_sum ON beneficiary_installments');
        DB::statement('DROP FUNCTION IF EXISTS check_installments_sum()');

        Schema::dropIfExists('beneficiary_installments');
        Schema::dropIfExists('haberes');
        Schema::dropIfExists('expedientes');
        Schema::dropIfExists('haber_management_labels');
    }

    /**
     * Etiquetas de gestión de la cuota.
     *
     * `blocks_payment` es lo que le da consecuencia a la etiqueta: cambiarla
     * es la acción que bloquea o libera el pago, sin dos campos que
     * mantener sincronizados. Hoy todas van en `false` porque el área
     * definió que por ahora son informativas.
     */
    private function createLabels(): void
    {
        Schema::create('haber_management_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('description', 160)->nullable();
            $table->boolean('blocks_payment')->default(false);
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
    }

    private function createExpedientes(): void
    {
        Schema::create('expedientes', function (Blueprint $table): void {
            $table->id();

            // Procedencia en SiCE v3.0. `external_id` es el «Cod.» del pie
            // de carátula, el identificador técnico que SiCE garantiza.
            $table->string('source_system', 40)->nullable();
            $table->string('external_id', 120)->nullable();

            // `0030064-125957/2026-0`, con el prefijo constante de esta
            // Secretaría. `display_number` es la forma corta de uso diario.
            $table->string('canonical_number', 40)->nullable();
            $table->string('display_number', 40);
            $table->string('external_reference', 80)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('received_date')->nullable();

            $table->foreignId('employer_id')->nullable();
            // Constante: existe para que la FK compuesta de abajo pueda
            // exigir que esa persona tenga efectivamente el rol.
            $table->string('employer_role', 24)->nullable();
            $table->string('employer_representative', 160)->nullable();

            $table->string('subject', 500)->nullable();
            $table->decimal('declared_total_amount', 19, 2)->nullable();
            $table->jsonb('extra_data')->nullable();
            $table->string('status', 20)->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index('received_date');
            $table->index('status');
        });

        DB::statement("ALTER TABLE expedientes ADD CONSTRAINT expedientes_status_check
            CHECK (status IN ('active', 'suspended', 'closed', 'cancelled'))");

        DB::statement('ALTER TABLE expedientes ADD CONSTRAINT expedientes_declared_total_check
            CHECK (declared_total_amount IS NULL OR declared_total_amount >= 0)');

        /*
         * El empleador tiene que ser alguien registrado como tal. La FK
         * apunta a `person_roles`, cuya clave primaria es (person_id, role),
         * así que la fila solo existe si esa persona tiene ese rol.
         */
        DB::statement("ALTER TABLE expedientes ADD CONSTRAINT expedientes_employer_role_check
            CHECK (employer_role IS NULL OR employer_role = 'employer')");
        DB::statement('ALTER TABLE expedientes ADD CONSTRAINT expedientes_employer_pair_check
            CHECK ((employer_id IS NULL) = (employer_role IS NULL))');
        DB::statement('ALTER TABLE expedientes ADD CONSTRAINT expedientes_employer_fk
            FOREIGN KEY (employer_id, employer_role) REFERENCES person_roles (person_id, role)');

        /*
         * Dos unicidades para dos preguntas distintas. El número canónico es
         * el que el operador tipea y por el que el alta avisa «este
         * expediente ya está cargado»; el par de SiCE es el que impide
         * importar dos veces la misma carátula.
         */
        DB::statement('CREATE UNIQUE INDEX expedientes_canonical_number_unique
            ON expedientes (canonical_number) WHERE canonical_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX expedientes_source_external_unique
            ON expedientes (source_system, external_id)
            WHERE source_system IS NOT NULL AND external_id IS NOT NULL');

        /*
         * Número, carátula y referencia en un solo campo, en minúsculas y
         * sin acentos. La calcula la base —igual que `people.search_name`—
         * para que ninguna vía de escritura pueda dejarla vacía.
         *
         * Existe porque el operador escribe «garcia» y la carátula dice
         * «GARCÍA», y porque un `like '%x%'` sobre cuatro columnas distintas
         * no puede apoyarse en ningún índice.
         */
        DB::statement("ALTER TABLE expedientes
            ADD COLUMN search_text text
            GENERATED ALWAYS AS (lower(f_unaccent(
                coalesce(display_number, '') || ' ' ||
                coalesce(canonical_number, '') || ' ' ||
                coalesce(external_reference, '') || ' ' ||
                coalesce(subject, '') || ' ' ||
                coalesce(employer_representative, '')
            ))) STORED");

        // El comodín va de los dos lados, así que btree no sirve.
        DB::statement('CREATE INDEX expedientes_search_text_trgm
            ON expedientes USING gin (search_text gin_trgm_ops)');

        /* Lo que el expediente dice y no entra en ningun campo. */
        Schema::table('expedientes', function (Blueprint $table): void {
            $table->text('notes')->nullable();
        });
    }

    private function createHaberes(): void
    {
        Schema::create('haberes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expediente_id')->constrained('expedientes')->restrictOnDelete();

            $table->foreignId('beneficiary_id');
            $table->string('beneficiary_role', 24);

            $table->decimal('assigned_amount', 19, 2);
            // Cuántas cuotas se esperan en total. El expediente suele
            // declarar la cantidad aunque traiga el importe de una sola.
            $table->unsignedSmallInteger('expected_installment_count')->nullable();
            $table->string('payment_terms', 20)->default('single');

            $table->date('legal_date')->nullable();
            $table->string('resolution_reference', 120)->nullable();
            $table->string('workflow_status', 20)->default('active');
            $table->string('block_reason', 300)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // Un beneficiario tiene un solo haber por expediente.
            $table->unique(['expediente_id', 'beneficiary_id']);
        });

        DB::statement("ALTER TABLE haberes ADD CONSTRAINT haberes_beneficiary_role_check
            CHECK (beneficiary_role = 'beneficiary')");
        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_beneficiary_fk
            FOREIGN KEY (beneficiary_id, beneficiary_role) REFERENCES person_roles (person_id, role)');

        /*
         * Importe estrictamente positivo. El DER trata el 0,00 histórico
         * como anomalía de migración y no como derecho válido (invariante
         * 33): cuando se importen los datos viejos, esos casos tienen que
         * saltar a la vista en vez de entrar en silencio.
         */
        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_assigned_amount_check
            CHECK (assigned_amount > 0)');
        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_expected_count_check
            CHECK (expected_installment_count IS NULL OR expected_installment_count > 0)');
        DB::statement("ALTER TABLE haberes ADD CONSTRAINT haberes_payment_terms_check
            CHECK (payment_terms IN ('single', 'installments'))");
        DB::statement("ALTER TABLE haberes ADD CONSTRAINT haberes_workflow_status_check
            CHECK (workflow_status IN ('active', 'suspended', 'blocked', 'cancelled', 'closed'))");
        // Un haber bloqueado sin motivo es un haber que nadie puede
        // desbloquear con criterio.
        DB::statement("ALTER TABLE haberes ADD CONSTRAINT haberes_block_reason_check
            CHECK ((workflow_status = 'blocked') = (block_reason IS NOT NULL))");

        /*
         * El concepto del haber. Las cuotas lo heredan: son fracciones del
         * mismo derecho, y repetirlo por cuota permitiria que dos digan
         * cosas distintas sobre lo mismo.
         */
        Schema::table('haberes', function (Blueprint $table): void {
            $table->string('concept', 255)->nullable();

            /*
             * La cuenta a la que se le paga por defecto. Va la columna sola:
             * su FK compuesta apunta a `person_bank_accounts`, que todavia no
             * existe, y la agrega esa migracion.
             */
            $table->unsignedBigInteger('default_bank_account_id')->nullable();
        });

        /*
         * La moneda del derecho, no de la cuota: sus cuotas son fracciones
         * del mismo derecho y la heredan. Una columna por cuota permitiria
         * un haber en pesos con una cuota en dolares.
         */
        Schema::table('haberes', function (Blueprint $table): void {
            $table->char('currency', 3)->default('ARS');
        });

        DB::statement("ALTER TABLE haberes ADD CONSTRAINT haberes_currency_check
            CHECK (currency IN ('ARS', 'USD'))");

        /*
         * El ordinal del haber dentro de su expediente: es como el area lo
         * nombra --«haber 2 del 125957»-- y por lo que las pantallas y las
         * rutas lo buscan, no por su id global.
         */
        Schema::table('haberes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('haber_number');
        });

        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_number_positive_check
            CHECK (haber_number >= 1)');

        DB::statement('CREATE UNIQUE INDEX haberes_expediente_number_unique
            ON haberes (expediente_id, haber_number)');
    }

    private function sumaDeCuotasNoSuperaElHaber(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_haber_amount_against_installments() RETURNS trigger AS $$
            DECLARE
                suma numeric(19,2);
            BEGIN
                SELECT COALESCE(SUM(expected_amount), 0) INTO suma
                  FROM beneficiary_installments
                 WHERE haber_id = NEW.id
                   AND workflow_status <> 'cancelled';

                IF suma > NEW.assigned_amount THEN
                    RAISE EXCEPTION
                        'La suma de las cuotas (%) supera el importe del haber (%).', suma, NEW.assigned_amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER haber_assigned_amount_sum
                AFTER UPDATE OF assigned_amount ON haberes
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION check_haber_amount_against_installments();
        SQL);

    }

    private function createInstallments(): void
    {
        Schema::create('beneficiary_installments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('haber_id')->constrained('haberes')->restrictOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->decimal('expected_amount', 19, 2);
            $table->foreignId('management_label_id')->nullable()
                ->constrained('haber_management_labels')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('description', 300)->nullable();
            /*
             * Previsión informativa, no una decisión: el DER es explícito
             * en que el medio efectivo lo fija la primera recepción
             * vinculada a la cuota. Sirve para anticipar el circuito —la
             * primera en efectivo por mostrador, el resto por
             * transferencia— y no para autorizar nada.
             */
            $table->string('expected_medium', 20);
            // Observaciones libres de esta cuota. Distinto de `description`,
            // que es el concepto que se imprime en el comprobante.
            $table->text('notes')->nullable();
            $table->string('workflow_status', 20)->default('active');
            $table->string('block_reason', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['haber_id', 'installment_number']);
        });

        DB::statement('ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_number_check
            CHECK (installment_number > 0)');
        DB::statement('ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_amount_check
            CHECK (expected_amount > 0)');
        DB::statement("ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_medium_check
            CHECK (expected_medium IN ('cash', 'cheque', 'bank'))");
        DB::statement("ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_workflow_status_check
            CHECK (workflow_status IN ('active', 'suspended', 'blocked', 'cancelled', 'paid'))");
        DB::statement("ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_block_reason_check
            CHECK ((workflow_status = 'blocked') = (block_reason IS NOT NULL))");

        /*
         * «La suma de las cuotas activas no supera el derecho del haber».
         *
         * No puede ser un CHECK: mira varias filas a la vez. Va como trigger
         * de restricción diferido, así que un alta que inserta las cuotas de
         * a una dentro de una transacción se valida recién al confirmar, y
         * no fila por fila.
         *
         * Solo impone el «no supera». Que la suma dé exacto es otra cosa:
         * mientras faltan cuotas por cargar, dar menos es el estado normal
         * del expediente, y eso lo resuelve el Action con un mensaje, no la
         * base con un rechazo.
         */
        // `OR REPLACE` y no `CREATE` a secas: `migrate:fresh` borra las
        // tablas pero no las funciones, así que sin esto la migración solo
        // corre la primera vez.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_installments_sum() RETURNS trigger AS $$
            DECLARE
                objetivo bigint;
                suma numeric(19,2);
                derecho numeric(19,2);
            BEGIN
                objetivo := COALESCE(NEW.haber_id, OLD.haber_id);

                SELECT assigned_amount INTO derecho FROM haberes WHERE id = objetivo;

                IF derecho IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(expected_amount), 0) INTO suma
                  FROM beneficiary_installments
                 WHERE haber_id = objetivo
                   AND workflow_status <> 'cancelled';

                IF suma > derecho THEN
                    RAISE EXCEPTION
                        'La suma de las cuotas (%) supera el importe del haber (%).', suma, derecho
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE CONSTRAINT TRIGGER beneficiary_installments_sum
                AFTER INSERT OR UPDATE OR DELETE ON beneficiary_installments
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION check_installments_sum();
        SQL);
        /*
         * La ventana para editar una cuota ya en circuito. Una ventana
         * abierta sin motivo no es una ventana justificada: es un bloqueo
         * apagado, y por eso los tres campos van juntos o no van.
         */
        Schema::table('beneficiary_installments', function (Blueprint $table): void {
            $table->timestampTz('edit_unlocked_at')->nullable();
            $table->string('edit_unlock_reason', 300)->nullable();
            $table->foreignId('edit_unlocked_by')->nullable()
                ->constrained('users')->nullOnDelete();
        });

        DB::statement('ALTER TABLE beneficiary_installments
            ADD CONSTRAINT beneficiary_installments_edit_unlock_check
            CHECK (
                (edit_unlocked_at IS NULL AND edit_unlock_reason IS NULL AND edit_unlocked_by IS NULL)
                OR (edit_unlocked_at IS NOT NULL AND edit_unlock_reason IS NOT NULL)
            )');
    }
};
