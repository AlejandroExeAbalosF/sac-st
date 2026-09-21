<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El titular de una organización deja de ser texto y pasa a ser una ficha.
 *
 * Se guardaba copiado en tres columnas —apellido, nombre y documento—, y esa
 * copia se apoyaba en un supuesto que no se sostiene: que el titular es
 * alguien que solo existe ahí. **Puede estar ya en el maestro**, porque nada
 * impide que quien está al frente de una empresa sea además beneficiario de
 * otro expediente. Con dos copias del mismo humano, corregirle el apellido en
 * su ficha deja la del organismo diciendo el nombre viejo, y en un sistema
 * cuyos comprobantes se imprimen con estos datos eso no es aceptable.
 *
 * La objeción que había frenado esto era que darle ficha lo metería en el
 * buscador de empleadores y beneficiarios. **Es falsa:** el picker filtra por
 * `person_roles`, así que una persona sin rol no aparece en ninguno de los
 * dos. El titular vive en el maestro sin rol hasta el día que participe de
 * una operación.
 *
 * Las tres columnas viejas se van sin migrar datos: nacieron ayer y solo
 * llegaron a tener lo que se cargó probando.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE people DROP CONSTRAINT IF EXISTS people_owner_check');

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['owner_first_name', 'owner_last_name', 'owner_document']);
            $table->foreignId('owner_person_id')->nullable()->after('legal_name');
        });

        /*
         * La columna existe solo para que la FK pueda ser compuesta, que es
         * lo que hace cumplible «el titular es una persona física»: una FK
         * simple contra `people(id)` aceptaría cualquier fila, y un CHECK no
         * puede mirar otra tabla. Es constante y la calcula la base, así que
         * nadie tiene que acordarse de escribirla.
         */
        DB::statement("ALTER TABLE people ADD COLUMN owner_person_type varchar(20)
            GENERATED ALWAYS AS ('individual'::varchar(20)) STORED");

        DB::statement('ALTER TABLE people ADD CONSTRAINT people_owner_person_fk
            FOREIGN KEY (owner_person_id, owner_person_type)
            REFERENCES people (id, type) ON DELETE RESTRICT');

        // Una persona física no tiene titular: lo es.
        DB::statement("ALTER TABLE people ADD CONSTRAINT people_owner_person_check
            CHECK (owner_person_id IS NULL OR type = 'company')");

        DB::statement('CREATE INDEX people_owner_person_id_index ON people (owner_person_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE people DROP CONSTRAINT IF EXISTS people_owner_person_check');
        DB::statement('ALTER TABLE people DROP CONSTRAINT IF EXISTS people_owner_person_fk');
        DB::statement('DROP INDEX IF EXISTS people_owner_person_id_index');
        DB::statement('ALTER TABLE people DROP COLUMN IF EXISTS owner_person_type');

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('owner_person_id');
            $table->string('owner_first_name', 80)->nullable();
            $table->string('owner_last_name', 80)->nullable();
            $table->string('owner_document', 20)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE people ADD CONSTRAINT people_owner_check CHECK (
                (type = 'company' OR (
                    owner_first_name IS NULL AND owner_last_name IS NULL AND owner_document IS NULL))
                AND (owner_first_name IS NULL) = (owner_last_name IS NULL)
                AND (owner_document IS NULL OR owner_last_name IS NOT NULL)
                AND (owner_document IS NULL OR owner_document ~ '^[0-9]{6,8}$')
            )
        SQL);
    }
};
