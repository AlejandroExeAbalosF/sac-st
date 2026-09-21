<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maestro único de personas y organizaciones.
 *
 * Una misma persona puede intervenir en más de un contexto. Los roles se
 * guardan aparte para evitar duplicarla como empleador y beneficiaria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 20);
            $table->string('name', 160);
            // Puede faltar: hay expedientes que traen solo el nombre de la
            // empresa. La unicidad se resuelve más abajo, con dos índices
            // parciales, porque cambia según haya documento o no.
            $table->string('document', 20)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('person_roles', function (Blueprint $table): void {
            $table->foreignId('person_id');
            $table->string('role', 24);
            // Copia del tipo de la persona. Es redundante a propósito: sin
            // ella, «un beneficiario es siempre persona física» no se puede
            // expresar como CHECK, porque un CHECK no mira otra tabla.
            $table->string('person_type', 20);
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['person_id', 'role']);
            $table->index(['role', 'person_id']);
        });

        /*
         * `search_name` la calcula la base, no PHP.
         *
         * Empezó siendo una columna común que escribía el controlador, y
         * eso la deja a merced de quien se olvide: un `saveQuietly`, un
         * `insert` masivo o un seeder con `WithoutModelEvents` la dejan
         * vacía, y con ella se cae la búsqueda y el índice único parcial que
         * evita empleadores duplicados sin documento.
         *
         * `unaccent` no es IMMUTABLE —depende de un diccionario— así que no
         * se puede usar directo en una columna generada. El envoltorio la
         * fija al diccionario `unaccent` y la declara inmutable, que es la
         * receta habitual de PostgreSQL para esto.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text
                LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
                AS $$ SELECT unaccent('unaccent', $1) $$;
        SQL);

        DB::statement('ALTER TABLE people
            ADD COLUMN search_name text
            GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');

        DB::statement("ALTER TABLE people ADD CONSTRAINT people_type_check CHECK (type IN ('individual', 'company'))");
        DB::statement("ALTER TABLE person_roles ADD CONSTRAINT person_roles_role_check CHECK (role IN ('employer', 'beneficiary'))");

        /*
         * El invariante «un beneficiario es siempre persona física» vivía
         * solo en el FormRequest, y `reuse()` lo esquivaba: al reutilizar
         * una ficha existente se le adjunta el rol sin volver a mirar el
         * tipo. Que hoy no se pueda llegar ahí depende de que los rangos de
         * longitud no se solapen —DNI de 6 a 8 dígitos, CUIT de 11—, que es
         * una defensa accidental, no una regla.
         *
         * La FK compuesta contra (id, type) hace dos cosas: obliga a que
         * `person_type` sea el tipo real de esa persona, y bloquea el
         * cambio de tipo de alguien que ya tiene roles. El CHECK, apoyado
         * en esa columna, cierra la regla.
         */
        DB::statement('ALTER TABLE people ADD CONSTRAINT people_id_type_unique UNIQUE (id, type)');
        DB::statement('ALTER TABLE person_roles ADD CONSTRAINT person_roles_person_fk
            FOREIGN KEY (person_id, person_type) REFERENCES people (id, type) ON DELETE CASCADE');
        DB::statement("ALTER TABLE person_roles ADD CONSTRAINT person_roles_beneficiary_is_individual_check
            CHECK (role <> 'beneficiary' OR person_type = 'individual')");

        /*
         * Sin documento obligatorio, el documento deja de ser por sí solo
         * la defensa contra duplicados: «CIACSA» cargada tres veces serían
         * tres CIACSA. Los dos índices se reparten el trabajo según el dato
         * que haya.
         *
         * El segundo puede rechazar a dos homónimos legítimos, y es a
         * propósito: la salida es cargarle el documento a uno de los dos,
         * que es justamente el dato que los distingue.
         */
        DB::statement('CREATE UNIQUE INDEX people_document_unique
            ON people (document) WHERE document IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX people_search_name_unique
            ON people (search_name) WHERE document IS NULL');

        /*
         * El picker busca con `like '%texto%'`, y un btree no sirve para un
         * comodín adelante: PostgreSQL recorre la tabla entera en cada
         * tecleo. Con el maestro real eso es un recorrido completo por
         * pulsación. Los trigramas sí resuelven el comodín de ambos lados.
         *
         * Un trigrama necesita tres caracteres, así que una consulta de una
         * o dos letras vuelve al recorrido secuencial. Es aceptable: con dos
         * letras el resultado se corta igual en los primeros veinte.
         */
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX people_search_name_trgm ON people USING gin (search_name gin_trgm_ops)');
        DB::statement('CREATE INDEX people_document_trgm ON people USING gin (document gin_trgm_ops)');
    }

    /**
     * El catálogo de muestra no vive acá.
     *
     * Esta migración insertaba dieciséis contrapartes inventadas --CIACSA,
     * García, Transporte Andino-- sin condición de entorno, así que una
     * instalación de producción nacía con ellas mezcladas en el maestro de
     * personas. Crear la tabla es tarea de la migración; llenarla con datos
     * falsos es otra cosa, y ahora la hace `PersonaDemoSeeder`, que se pide
     * por nombre.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_roles');
        Schema::dropIfExists('people');
    }
};
