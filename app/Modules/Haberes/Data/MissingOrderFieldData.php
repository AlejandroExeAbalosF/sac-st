<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un dato que le falta a la Orden, tal como lo muestra el modal.
 *
 * `section` es lo que permite agruparlos: quien abre el modal completa
 * cada bloque en su lugar en vez de salir a buscar seis pantallas.
 * `required` distingue lo que frena de lo que solo deja un renglón en
 * blanco, porque el formulario tolera renglones en blanco y frenar por
 * eso sería inventar una exigencia que el papel no tiene.
 */
#[TypeScript]
final class MissingOrderFieldData extends Data
{
    public function __construct(
        public string $code,
        public string $section,
        public string $label,
        public string $reason,
        public bool $required,
    ) {}
}
