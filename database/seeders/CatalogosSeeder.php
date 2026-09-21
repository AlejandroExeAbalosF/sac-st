<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * La configuración sin la cual el sistema no arranca.
 *
 * Permisos, series de numeración, cajas y etiquetas de gestión: no son
 * datos de prueba sino el catálogo que toda instalación necesita, en
 * producción igual que en un test. Por eso viven juntos y separados de los
 * juegos de demo, que arman escenarios y son alternativos entre sí.
 *
 * ── Por qué existe como clase aparte ───────────────────────────────────
 *
 * Los tests lo siembran **una sola vez por proceso**, no una vez por test.
 * `TestCase` lo declara en `$seeder`, y con eso Laravel lo corre dentro del
 * `migrate:fresh` que `RefreshDatabase` protege con
 * `RefreshDatabaseState::$migrated`; de ahí en adelante cada test hereda el
 * catálogo por la transacción que lo envuelve.
 *
 * Antes cada test lo rehacía: `operador()` sembraba los permisos en cada
 * llamada —617 veces en la suite— y tres decenas de `setUp()` repetían las
 * cajas y las series. Eran unos tres minutos de los ocho que tardaba Pest,
 * dedicados a escribir filas que la transacción tiraba enseguida.
 *
 * Los cuatro son idempotentes, así que sembrarlo de más no duplica nada.
 */
final class CatalogosSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        // Sin series no se puede emitir ningún comprobante.
        $this->call(DocumentSeriesSeeder::class);
        // Y sin cajas no hay a qué imputar un evento financiero.
        $this->call(CashBoxSeeder::class);
        // Las etiquetas son las opciones que ofrece el formulario de la
        // cuota: sin ellas el selector sale vacío.
        $this->call(HaberManagementLabelSeeder::class);
    }
}
