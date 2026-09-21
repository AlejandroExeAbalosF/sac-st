<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un caso concreto de una cola de trabajo.
 *
 * El tablero promete que el click lleva **exactamente** a las filas que
 * el número cuenta. Para las colas de Banco y de Caja eso se resuelve con
 * un enlace a la pantalla filtrada; para las de Haberes no hay listado
 * que las filtre —el estado de una Orden vive adentro del expediente— y
 * un enlace al índice completo sería una promesa incumplida.
 *
 * Entonces la cola trae los primeros casos y cada uno es su propio
 * enlace. No es un premio de consuelo: llegar al expediente en un click
 * es mejor que llegar a un listado donde hay que volver a buscarlo.
 */
#[TypeScript]
final class QueueSampleData extends Data
{
    public function __construct(
        /** Lo que identifica el caso: «EXP-1234/2026 · Pérez, Juan». */
        public string $label,
        /** El dato que explica por qué está en la cola. Puede no haberlo. */
        public ?string $detail,
        public string $href,
    ) {}
}
