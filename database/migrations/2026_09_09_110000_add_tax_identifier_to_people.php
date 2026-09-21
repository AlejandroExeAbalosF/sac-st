<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El CUIL de una persona física, cuando el expediente lo trajo.
 *
 * Hasta acá, pegar un CUIL en el campo del documento guardaba el DNI y
 * **tiraba el prefijo**. El verificador se calcula, pero el 20/23/24/27
 * depende del sexo y de las colisiones: no se puede reconstruir a partir
 * del DNI. Era información que el papel traía y que se perdía.
 *
 * No es una segunda identidad. `document` sigue siendo la única: es la que
 * deduplica, la que busca y la que se imprime. Esta columna guarda el
 * número completo y el CHECK la obliga a coincidir con ella, así que las
 * dos no pueden discrepar ni pueden dar lugar a dos fichas del mismo
 * humano.
 *
 * Se llama `tax_identifier` y no `cuil` porque no siempre es un CUIL: una
 * persona física monotributista tiene CUIT, con los mismos once dígitos y
 * los mismos prefijos. Es además el término que ya usa
 * `payment_orders.employer_tax_identifier_snapshot`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table): void {
            $table->string('tax_identifier', 11)->nullable()->after('document');
        });

        /*
         * El `ltrim` no es capricho: un DNI de siete cifras viaja dentro del
         * CUIL con un cero adelante —`20-01234567-x`—, y el maestro guarda
         * «1234567», sin él.
         *
         * El prefijo se impone acá y no solo en PHP porque es lo que hace
         * que la columna signifique algo: sin él, cualquier cadena de once
         * dígitos que empiece con los dos correctos pasaría.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE people ADD CONSTRAINT people_tax_identifier_check CHECK (
                tax_identifier IS NULL
                OR (
                    type = 'individual'
                    AND tax_identifier ~ '^(20|23|24|27)[0-9]{9}$'
                    AND document IS NOT NULL
                    AND ltrim(substring(tax_identifier from 3 for 8), '0') = document
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE people DROP CONSTRAINT IF EXISTS people_tax_identifier_check');

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('tax_identifier');
        });
    }
};
