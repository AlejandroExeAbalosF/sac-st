<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abrir el fajo del día anterior pasa a ser un hecho registrado.
 *
 * Hasta acá el recuento vivía solo en el navegador: sumaba sus billetes a
 * los del día, ponía el saldo anterior en cero y desaparecía. El arqueo que
 * quedaba guardado era indistinguible de uno donde el cajero contó todo.
 *
 * Eso se lleva puestas tres cosas:
 *
 * - **Que el fajo se abrió.** Si está intacto no queda rastro de que
 *   alguien verificó dos millones de plata vieja, que es justamente el
 *   control que después hay que poder mostrar.
 * - **De dónde sale el faltante.** Si el fajo está corto, la diferencia cae
 *   en el arqueo del día mezclada con lo de hoy, y seis meses después nadie
 *   puede decir si faltó de la recaudación o del fondo histórico.
 * - **La composición del fajo.** Las líneas se fundían con las del día, así
 *   que se perdía con qué billetes estaba armado — que es lo que se guarda
 *   al abrir los libros para poder compararlo.
 *
 * ─── Qué **no** cambia ────────────────────────────────────────────────────
 *
 * La aritmética del arqueo. El efectivo del fajo está físicamente en el
 * cajón, así que sus billetes entran en `counted_amount` y el trigger que
 * exige que el total sea la suma de las líneas sigue valiendo sobre todas.
 * Las columnas nuevas son **atribución**, no un segundo cálculo: dicen qué
 * parte de lo contado salió del fajo y qué decía el libro que había ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_counts', function (Blueprint $table): void {
            /** Por qué se abrió el fajo. Recontar es excepcional: el motivo informa. */
            $table->string('carry_recount_reason', 300)->nullable();

            /** Lo que el libro decía que había en el fajo al momento de abrirlo. */
            $table->decimal('carry_expected_amount', 19, 2)->nullable();

            /** Lo que se encontró. La resta contra el anterior es el faltante del fajo. */
            $table->decimal('carry_counted_amount', 19, 2)->nullable();
        });

        /*
         * Las tres van juntas o no va ninguna. Un motivo sin importes no
         * dice nada, y un importe sin motivo es el campo que ya sacamos una
         * vez por no aportar.
         */
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_carry_recount_check
            CHECK (num_nonnulls(carry_recount_reason, carry_expected_amount, carry_counted_amount) IN (0, 3))');

        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_carry_amounts_check
            CHECK (
                (carry_expected_amount IS NULL OR carry_expected_amount >= 0)
                AND (carry_counted_amount IS NULL OR carry_counted_amount >= 0)
            )');

        /*
         * Recontar el fajo y declararlo sin recontar al mismo tiempo es una
         * contradicción: o se abrió o no se abrió.
         */
        DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_carry_excludes_uncounted_check
            CHECK (carry_counted_amount IS NULL OR uncounted_amount = 0)');

        Schema::table('cash_count_lines', function (Blueprint $table): void {
            /*
             * De qué conteo es la línea. `day` es el movimiento de la
             * jornada; `carry`, el fajo que venía de días anteriores.
             * Separadas, cada una conserva su composición.
             */
            $table->string('scope', 10)->default('day');

            $table->dropUnique(['cash_count_id', 'denomination']);
            $table->unique(['cash_count_id', 'scope', 'denomination']);
        });

        DB::statement("ALTER TABLE cash_count_lines ADD CONSTRAINT cash_count_lines_scope_check
            CHECK (scope IN ('day', 'carry'))");

        $this->elFajoContadoEsLaSumaDeSusLineas();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_count_lines_carry_total ON cash_count_lines');
        DB::statement('DROP TRIGGER IF EXISTS cash_counts_carry_total ON cash_counts');
        DB::statement('DROP FUNCTION IF EXISTS cash_count_carry_matches_lines()');

        DB::statement('ALTER TABLE cash_count_lines DROP CONSTRAINT IF EXISTS cash_count_lines_scope_check');

        Schema::table('cash_count_lines', function (Blueprint $table): void {
            $table->dropUnique(['cash_count_id', 'scope', 'denomination']);
            $table->dropColumn('scope');
            $table->unique(['cash_count_id', 'denomination']);
        });

        foreach ([
            'cash_counts_carry_recount_check',
            'cash_counts_carry_amounts_check',
            'cash_counts_carry_excludes_uncounted_check',
        ] as $restriccion) {
            DB::statement("ALTER TABLE cash_counts DROP CONSTRAINT IF EXISTS {$restriccion}");
        }

        Schema::table('cash_counts', function (Blueprint $table): void {
            $table->dropColumn([
                'carry_recount_reason',
                'carry_expected_amount',
                'carry_counted_amount',
            ]);
        });
    }

    /**
     * `carry_counted_amount` es la suma de las líneas del fajo, siempre.
     *
     * Misma regla que el total del arqueo y por el mismo motivo: un importe
     * tipeado puede contradecir a sus propios sumandos. Va diferido porque
     * la cabecera se escribe antes que las líneas y en el medio no cuadra.
     *
     * Un fajo vacío es un resultado legítimo —el día que no quede nada— y
     * cero contra cero pasa. Lo que no pasa es tener líneas del fajo sin
     * haber declarado el recuento, ni al revés.
     */
    private function elFajoContadoEsLaSumaDeSusLineas(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_count_carry_matches_lines() RETURNS trigger AS $$
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

                SELECT carry_counted_amount INTO declarado
                  FROM cash_counts WHERE id = arqueo_id;

                -- El arqueo pudo borrarse en esta misma transaccion.
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(subtotal), 0) INTO sumado
                  FROM cash_count_lines
                 WHERE cash_count_id = arqueo_id AND scope = 'carry';

                IF declarado IS NULL THEN
                    IF sumado <> 0 THEN
                        RAISE EXCEPTION
                            'El arqueo % tiene lineas del fajo por % sin declarar el recuento.',
                            arqueo_id, sumado
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NULL;
                END IF;

                IF declarado <> sumado THEN
                    RAISE EXCEPTION
                        'El arqueo % declara % recontados del fajo y sus lineas suman %.',
                        arqueo_id, declarado, sumado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER cash_count_lines_carry_total
            AFTER INSERT OR UPDATE OR DELETE ON cash_count_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION cash_count_carry_matches_lines()');

        DB::statement('CREATE CONSTRAINT TRIGGER cash_counts_carry_total
            AFTER INSERT OR UPDATE ON cash_counts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION cash_count_carry_matches_lines()');
    }
};
