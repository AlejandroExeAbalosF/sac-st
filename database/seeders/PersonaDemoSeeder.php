<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Las contrapartes de los expedientes de muestra.
 *
 * Seis empleadores y diez beneficiarios, anonimizados de la planilla del
 * área. **Son datos de prueba y no se cargan solos.**
 *
 * Vivían dentro de `create_people_table`, insertados por la propia
 * migración y sin ninguna condición de entorno: una instalación de
 * producción nacía con dieciséis contrapartes inventadas en el maestro de
 * personas, mezcladas con las reales desde el primer día. La migración
 * crea la tabla; llenarla con datos falsos es otra cosa.
 *
 * **Los identificadores son explícitos y parte del contrato.** Los
 * expedientes de muestra y varios tests apuntan a `101` (CIACSA) y a `201`
 * (García) por número, así que no pueden salir de una secuencia. Después
 * de insertarlos hay que adelantar la secuencia a mano: PostgreSQL no la
 * mueve cuando el id viene puesto, y el siguiente alta chocaría contra el
 * 101.
 *
 * **El nombre no se escribe, se calcula.** `people.name` es una columna
 * generada —«Apellido, Nombre» para una persona, la razón social para una
 * organización—, así que acá van las partes y no el resultado. Mientras el
 * catálogo vivió dentro de la migración esto no se notaba: corría antes de
 * que esa columna existiera.
 *
 * Es idempotente: correrlo dos veces no duplica ni falla.
 */
final class PersonaDemoSeeder extends Seeder
{
    /**
     * Empleadores: la razón social es su nombre.
     *
     * @var list<array{0: int, 1: string, 2: string}>
     */
    private const EMPLEADORES = [
        [101, 'CIACSA', '30710442892'],
        [102, 'Transporte Andino SRL', '30698844120'],
        [103, 'Frigorífico del Norte SA', '30621190558'],
        [104, 'Distribuidora Sur SA', '30711900438'],
        [105, 'Metalúrgica Salta SRL', '30709981124'],
        [106, 'Servicios Integrales SRL', '30710022886'],
    ];

    /**
     * Beneficiarios: apellido y nombre por separado.
     *
     * @var list<array{0: int, 1: string, 2: string, 3: string}>
     */
    private const BENEFICIARIOS = [
        [201, 'García', 'Claudio Adrián', '28114902'],
        [202, 'Bulacio', 'Víctor Mario', '22908114'],
        [203, 'Guaymás', 'Nélida Rosa', '25114780'],
        [204, 'Cardozo', 'Luis Alberto', '20887431'],
        [205, 'Vega', 'Marta Susana', '24551209'],
        [206, 'Sandoval', 'Ramón Elías', '18774300'],
        [207, 'Tinte', 'Olga Isabel', '13998220'],
        [208, 'Zerpa', 'Rosana Beatriz', '30225118'],
        [209, 'Ledesma', 'Ariel Osvaldo', '27443190'],
        [210, 'Cruz', 'Mirta Elena', '26330871'],
    ];

    public function run(): void
    {
        $ahora = now();
        $personas = [];
        $roles = [];

        foreach (self::EMPLEADORES as [$id, $razonSocial, $documento]) {
            $personas[] = [
                'id' => $id,
                'type' => 'company',
                'legal_name' => $razonSocial,
                'document' => $documento,
                'is_active' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];

            $roles[] = [
                'person_id' => $id,
                'role' => 'employer',
                'person_type' => 'company',
                'created_at' => $ahora,
            ];
        }

        foreach (self::BENEFICIARIOS as [$id, $apellido, $nombre, $documento]) {
            $personas[] = [
                'id' => $id,
                'type' => 'individual',
                'last_name' => $apellido,
                'first_name' => $nombre,
                'document' => $documento,
                'is_active' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];

            $roles[] = [
                'person_id' => $id,
                'role' => 'beneficiary',
                'person_type' => 'individual',
                'created_at' => $ahora,
            ];
        }

        /*
         * `insertOrIgnore` y no `insert`: el seeder lo llaman los juegos de
         * datos que lo necesitan, y más de uno puede correr sobre la misma
         * base. Chocar contra el id 101 no es un error, es que ya estaba.
         */
        foreach ($personas as $persona) {
            DB::table('people')->insertOrIgnore($persona);
        }

        DB::table('person_roles')->insertOrIgnore($roles);

        // PostgreSQL no adelanta la secuencia al insertar IDs explícitos.
        DB::statement("SELECT setval(pg_get_serial_sequence('people', 'id'), (SELECT MAX(id) FROM people))");
    }
}
