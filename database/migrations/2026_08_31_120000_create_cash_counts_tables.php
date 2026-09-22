<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arqueos de caja — §9.9 del DER.
 *
 * El reverso de la planilla, que la contadora escribe a mano desde
 * siempre: se cuentan los billetes por denominación y el total se compara
 * con lo que dice el libro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();

            /*
             * ─── Tres columnas donde el DER puso una ─────────────────────
             *
             * El DER define `counted_at` con `UNIQUE (cash_box_id,
             * counted_at)`, que solo funciona si es una fecha. Pero un
             * `timestamptz` en su lugar no habilita el arqueo por turno:
             * habilita arqueos ilimitados separados por microsegundos, y
             * el índice deja de imponer nada.
             *
             * Separados, cada uno hace un trabajo:
             *
             * - `counted_on` es la **fecha operativa**, la que decide a qué
             *   día pertenece el conteo. Un arqueo hecho a las 23:50 que
             *   cierra el lunes es del lunes aunque el reloj diga otra cosa.
             * - `sequence` es el turno. Hoy siempre 1; el día que el área
             *   cierre dos veces por día es una fila con 2, sin migración.
             * - `counted_at` es el instante real, que con una fecha sola se
             *   perdía. Es auditoría, no clasificación.
             */
            $table->date('counted_on');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestampTz('counted_at');

            /*
             * El arqueo se cuenta **por moneda**: billetes de pesos y de
             * dólares son dos conteos con dos totales, y sumarlos no
             * significa nada. §4.4 del DER, corrección 23.
             */
            $table->char('currency', 3)->default('ARS');

            /** Saldo del libro al cierre de `counted_on`, congelado acá. */
            $table->decimal('expected_amount', 19, 2);

            /** Suma de `cash_count_lines`. Nunca se tipea: se deriva. */
            $table->decimal('counted_amount', 19, 2)->default(0);

            /*
             * ─── El fajo que no se recontó ───────────────────────────────
             *
             * La planilla de junio de 2026 lo llama «SALDO DIA ANTERIOR» y
             * no es el saldo del día anterior: el 10/06 el saldo inicial
             * era 2.900.000 y la fila dice 1.900.000. Es el número que
             * falta para que el conteo dé el del libro. Los veinte días del
             * mes cierran con diferencia cero, y cierran por construcción.
             *
             * Modelarlo explícito es lo que salva a `difference_amount` de
             * volverse ilegible. Sin esta columna, «no lo conté» y «no está»
             * producen exactamente la misma cifra y el contador no tiene
             * cómo distinguirlas.
             *
             * Nace en cero, que es el arqueo correcto: contar el cajón
             * entero. Si el área sigue contando solo lo del día, la
             * práctica queda representada —con nombre, justificación y
             * responsable— en vez de escondida en una fila que dice otra cosa.
             */
            $table->decimal('uncounted_amount', 19, 2)->default(0);
            $table->string('uncounted_reason', 300)->nullable();

            $table->string('status', 20)->default('draft');
            $table->string('explanation', 500)->nullable();

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            /*
             * El asiento que imputa la diferencia a `CASH_DIFFERENCE`,
             * cuando además de documentarla se la regulariza. Nulo si solo
             * se documenta — cuál de las dos vías corresponde sigue siendo
             * pregunta abierta del §4.1.
             */
            $table->foreignId('adjustment_event_id')->nullable()
                ->constrained('financial_events')->restrictOnDelete();

            $table->timestampsTz();

            $table->unique(['cash_box_id', 'counted_on', 'currency', 'sequence']);
            $table->index(['cash_box_id', 'counted_on']);
            $table->index(['status']);
        });

        /*
         * La diferencia no es un dato que alguien escribe: es una resta.
         * Columna generada y no columna común, porque un importe tipeado
         * puede contradecir a sus propios sumandos y este no puede.
         */
        DB::statement('ALTER TABLE cash_counts
            ADD COLUMN difference_amount NUMERIC(19,2)
            GENERATED ALWAYS AS (counted_amount + uncounted_amount - expected_amount) STORED');

        DB::statement("ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_currency_check
            CHECK (currency IN ('ARS', 'USD'))");
        DB::statement("ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_status_check
            CHECK (status IN ('draft', 'reviewed', 'adjusted', 'closed'))");
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_sequence_check
            CHECK (sequence >= 1)');
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_counted_amount_check
            CHECK (counted_amount >= 0)');
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_uncounted_amount_check
            CHECK (uncounted_amount >= 0)');

        /*
         * Invariante 28: *«Una diferencia de arqueo distinta de cero exige
         * explicación»*. Escrito sobre las columnas base y no sobre la
         * generada, que es lo mismo y no depende de qué versión de
         * PostgreSQL admite referenciarlas en un CHECK.
         */
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount + uncounted_amount = expected_amount
                OR explanation IS NOT NULL
            )');

        /** No haber contado algo también exige decir por qué. */
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_uncounted_reason_check
            CHECK (uncounted_amount = 0 OR uncounted_reason IS NOT NULL)');

        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_reviewer_check
            CHECK ((reviewed_by IS NULL) = (reviewed_at IS NULL))');

        /** Un arqueo que salió de borrador lo miró alguien. */
        DB::statement("ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_reviewed_check
            CHECK (status = 'draft' OR reviewed_by IS NOT NULL)");

        /** El asiento de ajuste solo existe si la diferencia se imputó. */
        DB::statement("ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_adjustment_check
            CHECK (adjustment_event_id IS NULL OR status IN ('adjusted', 'closed'))");

        Schema::create('cash_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_count_id')->constrained('cash_counts')->cascadeOnDelete();

            /*
             * Las denominaciones **no son un catálogo**: en la planilla de
             * junio de 2026 varían de una hoja a otra según qué billetes
             * había ese día —el 01/06 no tiene 500 ni 200, el 10/06 tampoco—.
             * Se cargan como dato del arqueo. La UI sugiere las diez que el
             * área usa; el dato admite cualquiera.
             */
            $table->decimal('denomination', 19, 2);
            $table->unsignedInteger('quantity');

            $table->timestampsTz();

            $table->unique(['cash_count_id', 'denomination']);
        });

        DB::statement('ALTER TABLE cash_count_lines
            ADD COLUMN subtotal NUMERIC(19,2)
            GENERATED ALWAYS AS (denomination * quantity) STORED');

        DB::statement('ALTER TABLE cash_count_lines ADD CONSTRAINT cash_count_lines_denomination_check
            CHECK (denomination > 0)');
        DB::statement('ALTER TABLE cash_count_lines ADD CONSTRAINT cash_count_lines_quantity_check
            CHECK (quantity > 0)');

        $this->totalContadoEsLaSumaDeLasLineas();
        $this->arqueoCerradoNoSeEdita();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_counts_frozen ON cash_counts');
        DB::statement('DROP TRIGGER IF EXISTS cash_count_lines_frozen ON cash_count_lines');
        DB::statement('DROP FUNCTION IF EXISTS cash_counts_frozen()');
        DB::statement('DROP FUNCTION IF EXISTS cash_count_lines_frozen()');

        DB::statement('DROP TRIGGER IF EXISTS cash_count_lines_total ON cash_count_lines');
        DB::statement('DROP TRIGGER IF EXISTS cash_counts_total ON cash_counts');
        DB::statement('DROP FUNCTION IF EXISTS cash_count_total_matches_lines()');

        Schema::dropIfExists('cash_count_lines');
        Schema::dropIfExists('cash_counts');
    }

    /**
     * `counted_amount` es la suma de las líneas, siempre.
     *
     * El DER lo dice sin rodeos: *«un total tipeado no se puede auditar; un
     * desglose por denominación sí»*. Va como `CONSTRAINT TRIGGER …
     * DEFERRABLE`, por lo mismo que el balance del asiento: la cabecera se
     * inserta antes que las líneas y en el medio el total no cuadra. La
     * comprobación corre al confirmar la transacción, con el arqueo entero
     * escrito.
     */
    private function totalContadoEsLaSumaDeLasLineas(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_count_total_matches_lines() RETURNS trigger AS $$
            DECLARE
                arqueo_id BIGINT;
                declarado NUMERIC(19,2);
                sumado NUMERIC(19,2);
            BEGIN
                IF TG_TABLE_NAME = 'cash_counts' THEN
                    arqueo_id := NEW.id;
                ELSE
                    arqueo_id := COALESCE(NEW.cash_count_id, OLD.cash_count_id);
                END IF;

                SELECT counted_amount INTO declarado FROM cash_counts WHERE id = arqueo_id;

                -- El arqueo pudo borrarse en esta misma transaccion.
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(subtotal), 0) INTO sumado
                  FROM cash_count_lines WHERE cash_count_id = arqueo_id;

                IF declarado <> sumado THEN
                    RAISE EXCEPTION
                        'El arqueo % declara % contados y sus lineas suman %.',
                        arqueo_id, declarado, sumado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER cash_count_lines_total
            AFTER INSERT OR UPDATE OR DELETE ON cash_count_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION cash_count_total_matches_lines()');

        DB::statement('CREATE CONSTRAINT TRIGGER cash_counts_total
            AFTER INSERT OR UPDATE ON cash_counts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION cash_count_total_matches_lines()');
    }

    /**
     * Un arqueo cerrado es evidencia, no un formulario.
     *
     * Mientras está en borrador se corrige libremente —contar billetes es
     * cargar veinte números y equivocarse en uno es normal—. Cerrado, el
     * conteo es el respaldo del cierre del día y de la planilla que se
     * archiva: si pudiera editarse, el papel firmado y la fila de la base
     * dejarían de decir lo mismo.
     */
    private function arqueoCerradoNoSeEdita(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_counts_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'draft' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Un arqueo que salio de borrador no se borra.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status = 'closed' THEN
                    RAISE EXCEPTION 'El arqueo del % ya esta cerrado y no admite cambios.', OLD.counted_on
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER cash_counts_frozen
            BEFORE UPDATE OR DELETE ON cash_counts
            FOR EACH ROW EXECUTE FUNCTION cash_counts_frozen()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_count_lines_frozen() RETURNS trigger AS $$
            DECLARE
                estado TEXT;
            BEGIN
                SELECT status INTO estado FROM cash_counts
                 WHERE id = COALESCE(NEW.cash_count_id, OLD.cash_count_id);

                -- Borrado en cascada desde un arqueo en borrador.
                IF estado IS NULL THEN
                    RETURN COALESCE(NEW, OLD);
                END IF;

                IF estado <> 'draft' THEN
                    RAISE EXCEPTION 'El detalle de un arqueo solo se edita mientras esta en borrador.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER cash_count_lines_frozen
            BEFORE INSERT OR UPDATE OR DELETE ON cash_count_lines
            FOR EACH ROW EXECUTE FUNCTION cash_count_lines_frozen()');
    }
};
