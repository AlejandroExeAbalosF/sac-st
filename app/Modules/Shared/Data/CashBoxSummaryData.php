<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Volumen de trámites de una caja.
 *
 * Da el tamaño del universo: cuántos expedientes maneja cada caja y qué
 * proporción representa. Es información de jefatura, no de operación —por
 * eso va debajo de las colas y no encima—, pero responde una pregunta
 * legítima que las colas no responden: «¿cuánto movemos?».
 *
 * `count` en `null` mientras la caja no tenga módulo, con el mismo
 * criterio que las colas: un cero es información y un módulo ausente no.
 */
#[TypeScript]
final class CashBoxSummaryData extends Data
{
    public function __construct(
        public string $code,
        public string $name,
        public ?int $count,
        /** Proporción sobre el total, de 0 a 100. Null si no hay datos. */
        public ?int $share,
        public bool $isCurrentPhase,
    ) {}
}
