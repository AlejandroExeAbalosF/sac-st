<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Haberes\Models\HaberManagementLabel;
use Illuminate\Database\Seeder;

/**
 * Las etiquetas de gestión de la cuota.
 *
 * **Es un maestro, no datos de prueba**, y por eso vive en su propio seeder
 * y corre siempre —también en producción—: son las opciones que el
 * formulario de la cuota ofrece, y sin ellas el selector sale vacío.
 *
 * Vivían dentro de `HaberesDemoSeeder`, mezcladas con los seis expedientes
 * del relevamiento. Mientras el demo corría solo, la distinción daba igual;
 * el día que se lo quiso apagar para probar el sistema desde cero, apagarlo
 * se llevaba puesto el catálogo.
 *
 * Van sin marca de bloqueo: el área definió que por ahora son informativas.
 * `T.CONC.` y `T.CONOC.` son el mismo código escrito de dos formas en la
 * planilla; se unifica en `T.CONOC.`.
 */
final class HaberManagementLabelSeeder extends Seeder
{
    public function run(): void
    {
        $catalogo = [
            ['T.CONOC.', 'Tomar conocimiento', 1],
            ['P.P. HOMOL.', 'Pendiente de homologación', 2],
            ['PAGAR', 'Habilitada para pagar', 3],
        ];

        foreach ($catalogo as [$code, $description, $orden]) {
            HaberManagementLabel::query()->updateOrCreate(
                ['code' => $code],
                [
                    'description' => $description,
                    'blocks_payment' => false,
                    'sort_order' => $orden,
                    'is_active' => true,
                ],
            );
        }
    }
}
