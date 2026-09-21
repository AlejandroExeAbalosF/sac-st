<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El haber se numera dentro de su expediente — desvío 45.
 *
 * Hasta acá el haber se identificaba en la dirección por su id, que es un
 * contador global: dos haberes del mismo expediente podían ser el 3 y el
 * 148, y el número no le decía nada a nadie. Con el ordinal la dirección
 * se lee como el papel: el primer haber del expediente, el segundo.
 *
 * **El número se guarda, no se calcula.** Derivarlo de la posición —el
 * enésimo por id— haría que anular el primer haber corriera a todos los
 * demás, y una dirección que alguien copió el mes pasado pasaría a apuntar
 * a otra persona. Se asigna una vez y no se mueve.
 *
 * **No se recicla, y eso sale solo.** Acá nada se borra: anular es un
 * estado. Un haber anulado sigue ocupando su número, así que `max + 1` no
 * puede chocar con nadie. No hizo falta inventar una regla.
 *
 * El índice único es la garantía; el Action solo propone el número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('haberes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('haber_number')->nullable()->after('expediente_id');
        });

        // Los ya cargados, por orden de carga: es el único orden que hay y
        // coincide con cómo los viene leyendo la pantalla.
        DB::statement('UPDATE haberes SET haber_number = numerados.posicion
            FROM (
                SELECT id, row_number() OVER (PARTITION BY expediente_id ORDER BY id) AS posicion
                FROM haberes
            ) AS numerados
            WHERE haberes.id = numerados.id');

        Schema::table('haberes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('haber_number')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_number_positive_check
            CHECK (haber_number >= 1)');

        DB::statement('CREATE UNIQUE INDEX haberes_expediente_number_unique
            ON haberes (expediente_id, haber_number)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS haberes_expediente_number_unique');
        DB::statement('ALTER TABLE haberes DROP CONSTRAINT IF EXISTS haberes_number_positive_check');

        Schema::table('haberes', function (Blueprint $table): void {
            $table->dropColumn('haber_number');
        });
    }
};
