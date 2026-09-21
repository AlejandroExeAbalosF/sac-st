<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Apellido y nombre en columnas propias, en el maestro y en los usuarios.
 *
 * Hasta acá la identidad de una persona vivía en una sola columna `name` y
 * la convención «Apellido, Nombre» existía solo en la cabeza del operador:
 * nada impedía cargar «Juan Pérez» donde el resto dice «Pérez, Juan», y una
 * vez tipeado así quedaba impreso en recibos y Órdenes de Pago.
 *
 * De paso vuelve honesta a `people`. `name` disimulaba que una razón social
 * y un apellido-y-nombre son cosas distintas; ahora cada tipo tiene sus
 * columnas y un CHECK impide mezclarlas.
 *
 * **`name` deja de escribirse y pasa a calcularse.** Es el mismo mecanismo
 * que ya sostiene `search_name`, y por la misma razón: de `name` dependen
 * los comprobantes impresos y un índice único parcial, así que no puede
 * depender de que nadie se olvide de mantenerlo al día.
 *
 * `search_name` hay que rehacerla aunque su definición no cambie de sentido:
 * PostgreSQL no permite que una columna generada referencie otra columna
 * generada, así que pasa a calcularse sobre las columnas base en lugar de
 * sobre `name`.
 */
return new class extends Migration
{
    /**
     * El nombre para mostrar, tal como lo arma la base.
     *
     * Se repite en las dos columnas generadas de `people` porque una no
     * puede leer a la otra. Vive acá para que sea evidente que son la misma
     * expresión y no dos criterios que se parecen.
     */
    private const PERSON_NAME = <<<'SQL'
        CASE WHEN type = 'individual'
             THEN last_name || ', ' || first_name
             ELSE legal_name
        END
        SQL;

    private const USER_NAME = "last_name || ', ' || first_name";

    public function up(): void
    {
        $this->splitPeople();
        $this->splitUsers();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE people DROP CONSTRAINT IF EXISTS people_name_by_type_check');

        // Al dropear una columna generada se van con ella sus índices.
        DB::statement('ALTER TABLE people DROP COLUMN search_name');
        DB::statement('ALTER TABLE people DROP COLUMN name');
        DB::statement('ALTER TABLE people ADD COLUMN name varchar(160)');
        DB::statement("UPDATE people SET name = coalesce(legal_name, last_name || ', ' || first_name)");
        DB::statement('ALTER TABLE people ALTER COLUMN name SET NOT NULL');
        DB::statement('ALTER TABLE people ADD COLUMN search_name text
            GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        $this->createPeopleNameIndexes();

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name', 'legal_name']);
        });

        DB::statement('ALTER TABLE users DROP COLUMN name');
        DB::statement('ALTER TABLE users ADD COLUMN name varchar(255)');
        DB::statement("UPDATE users SET name = last_name || ', ' || first_name");
        DB::statement('ALTER TABLE users ALTER COLUMN name SET NOT NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }

    private function splitPeople(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable()->after('type');
            $table->string('last_name', 80)->nullable()->after('first_name');
            $table->string('legal_name', 160)->nullable()->after('last_name');
        });

        DB::statement("UPDATE people SET legal_name = name WHERE type = 'company'");

        /*
         * Todo lo cargado hasta hoy usa «Apellido, Nombre» —el catálogo de
         * la migración que creó la tabla y los cuatro seeders de demo—, así
         * que el corte es por la primera coma.
         */
        DB::statement(<<<'SQL'
            UPDATE people SET
                last_name  = btrim(split_part(name, ',', 1)),
                first_name = nullif(btrim(substring(name from position(',' in name) + 1)), '')
            WHERE type = 'individual' AND position(',' in name) > 0
        SQL);

        // Red para una ficha cargada sin coma: el apellido va al final.
        DB::statement(<<<'SQL'
            UPDATE people SET
                first_name = regexp_replace(btrim(name), '\s+\S+$', ''),
                last_name  = regexp_replace(btrim(name), '^.*\s+', '')
            WHERE type = 'individual' AND last_name IS NULL AND btrim(name) ~ '\s'
        SQL);

        // Un solo token: no hay con qué separar, y el CHECK exige las dos.
        DB::statement("UPDATE people
            SET first_name = coalesce(first_name, btrim(name)),
                last_name  = coalesce(last_name, btrim(name))
            WHERE type = 'individual' AND (first_name IS NULL OR last_name IS NULL)");

        DB::statement('ALTER TABLE people DROP COLUMN search_name');
        DB::statement('ALTER TABLE people DROP COLUMN name');

        DB::statement('ALTER TABLE people ADD COLUMN name text
            GENERATED ALWAYS AS ('.self::PERSON_NAME.') STORED');
        DB::statement('ALTER TABLE people ADD COLUMN search_name text
            GENERATED ALWAYS AS (lower(f_unaccent('.self::PERSON_NAME.'))) STORED');

        $this->createPeopleNameIndexes();

        /*
         * Los dos juegos de columnas no se mezclan, y ninguno puede faltar:
         * `name` es columna generada sobre ellas, así que una persona física
         * sin apellido dejaría sin nombre a la ficha entera.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE people ADD CONSTRAINT people_name_by_type_check CHECK (
                (type = 'individual'
                    AND first_name IS NOT NULL AND last_name IS NOT NULL AND legal_name IS NULL)
             OR (type = 'company'
                    AND legal_name IS NOT NULL AND first_name IS NULL AND last_name IS NULL)
            )
        SQL);
    }

    private function splitUsers(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable()->after('id');
            $table->string('last_name', 80)->nullable()->after('first_name');
        });

        /*
         * Los usuarios sí venían en orden natural —«Nora Achad»—, así que
         * acá el apellido es la última palabra. Es heurística sobre datos de
         * desarrollo: los seeders y la factory pasan a escribir las dos
         * columnas, y sobre una base recreada esto no llega a correr.
         */
        DB::statement(<<<'SQL'
            UPDATE users SET
                first_name = regexp_replace(btrim(name), '\s+\S+$', ''),
                last_name  = regexp_replace(btrim(name), '^.*\s+', '')
            WHERE btrim(name) ~ '\s'
        SQL);

        DB::statement('UPDATE users
            SET first_name = coalesce(first_name, btrim(name)),
                last_name  = coalesce(last_name, btrim(name))
            WHERE first_name IS NULL OR last_name IS NULL');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name', 80)->nullable(false)->change();
            $table->string('last_name', 80)->nullable(false)->change();
        });

        DB::statement('ALTER TABLE users DROP COLUMN name');
        DB::statement('ALTER TABLE users ADD COLUMN name text
            GENERATED ALWAYS AS ('.self::USER_NAME.') STORED');
    }

    /**
     * Los índices que colgaban de `search_name`, idénticos a los originales.
     *
     * El único parcial evita organizaciones duplicadas cuando no hay CUIT
     * con el cual distinguirlas; el trigram es lo que hace que el buscador
     * del picker no recorra la tabla entera en cada tecleo.
     */
    private function createPeopleNameIndexes(): void
    {
        DB::statement('CREATE UNIQUE INDEX people_search_name_unique
            ON people (search_name) WHERE document IS NULL');
        DB::statement('CREATE INDEX people_search_name_trgm
            ON people USING gin (search_name gin_trgm_ops)');
    }
};
