<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Lo que toda instalación necesita, y nada más.
     *
     * **Ningún dato de prueba se carga solo.** Antes el demo de haberes
     * entraba con `--seed` en desarrollo, y una base recién migrada nacía
     * con seis expedientes que nadie pidió: no había forma de arrancar
     * limpio para probar el circuito desde cero.
     *
     * Los juegos de datos son alternativos entre sí —cada uno arma su
     * propio escenario— y se piden por nombre:
     *
     * | Comando | Qué carga |
     * |---|---|
     * | `db:seed --class=PersonaDemoSeeder` | Las dieciséis contrapartes de muestra. Aditivo |
     * | `db:seed --class=HaberesDemoSeeder` | Seis expedientes del relevamiento, con sus contrapartes. Aditivo |
     * | `db:seed --class=EscenariosDemoSeeder` | Tres expedientes con sus bordes. **Reemplaza** |
     * | `db:seed --class=CajaDemoSeeder` | La planilla de caja de junio. **Reemplaza** |
     * | `db:seed --class=OperacionDemoSeeder` | Dos meses de operación día por día. **Reemplaza** |
     *
     * Los que dicen «reemplaza» vacían el circuito antes de construir: no
     * se acumulan y correr dos seguidos deja solo el último.
     */
    public function run(): void
    {
        // Permisos, series, cajas y etiquetas. La lista vive en un solo
        // lugar porque los tests siembran ese mismo catálogo, y dos listas
        // que se copian terminan diciendo cosas distintas.
        $this->call(CatalogosSeeder::class);

        // Usuario de desarrollo. En producción las altas las hace un
        // administrador desde el módulo de usuarios: no hay registro público.
        if (! app()->isProduction()) {
            User::factory()->create([
                'first_name' => 'Administrador',
                'last_name' => 'De Prueba',
                'username' => 'admin',
                'document_number' => '20123456',
                'email' => 'admin@example.test',
                'password' => 'Contrasena.Segura.2026',
            ])->assignRole('administrador');
        }
    }
}
