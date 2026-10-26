<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un paso de la puesta en marcha del sistema.
 *
 * Se deriva del estado de los datos —hay cuentas, el libro está abierto—
 * y no de quién entra ni de si ya lo vio: la puesta en marcha es del
 * sistema, se hace una vez para todos, y el paso se completa solo cuando
 * alguien lo hace.
 */
#[TypeScript]
final class SetupStepData extends Data
{
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public bool $done,
        /** No cuenta para dar la puesta en marcha por terminada. */
        public bool $optional = false,
        /**
         * Adónde ir a hacerlo. `null` cuando quien mira no tiene el permiso
         * o el paso todavía no se puede hacer.
         */
        public ?string $href = null,
        /** Por qué todavía no se puede hacer: lo que falta antes. */
        public ?string $blockedBy = null,
    ) {}
}
