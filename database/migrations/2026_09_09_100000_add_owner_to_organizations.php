<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El titular de una organización.
 *
 * Quién está al frente del organismo o de la empresa: el dato que el área
 * pide para saber con quién se habla cuando el expediente no lo dice.
 *
 * **Van como columnas y no como una ficha aparte del maestro.** Es la misma
 * razón del punto 10 de las correcciones al DER, la que dejó al
 * representante como texto en el expediente: el titular no cobra, no
 * deposita y no se elige de una lista. Darle una fila en `people` obligaría
 * a inventarle un rol en el circuito y lo metería en el buscador del picker,
 * donde estorba. El día que un titular tenga que participar de una
 * operación —cobrar, firmar un recibo— deja de ser un dato de contacto y
 * ahí sí corresponde su propia ficha.
 *
 * A diferencia del representante del expediente, este es **estable por
 * organización**: no cambia según el papel que llegó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('owner_first_name', 80)->nullable()->after('legal_name');
            $table->string('owner_last_name', 80)->nullable()->after('owner_first_name');
            $table->string('owner_document', 20)->nullable()->after('owner_last_name');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE people ADD CONSTRAINT people_owner_check CHECK (
                -- Una persona física no tiene titular: lo es.
                (type = 'company' OR (
                    owner_first_name IS NULL AND owner_last_name IS NULL AND owner_document IS NULL))
                -- El apellido y el nombre van juntos o no van.
                AND (owner_first_name IS NULL) = (owner_last_name IS NULL)
                -- Un documento suelto no identifica a nadie.
                AND (owner_document IS NULL OR owner_last_name IS NOT NULL)
                -- Es un DNI, igual que el de cualquier persona del maestro.
                AND (owner_document IS NULL OR owner_document ~ '^[0-9]{6,8}$')
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE people DROP CONSTRAINT IF EXISTS people_owner_check');

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn(['owner_first_name', 'owner_last_name', 'owner_document']);
        });
    }
};
