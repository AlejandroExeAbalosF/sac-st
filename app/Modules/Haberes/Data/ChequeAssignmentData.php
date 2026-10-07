<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A qué cuota está asignado un cheque de la cartera, y por cuánto.
 */
#[TypeScript]
final class ChequeAssignmentData extends Data
{
    public function __construct(
        public int $installmentId,
        public int $installmentNumber,
        /** Forma corta de uso diario: `125957/2026`. */
        public string $expedienteNumber,
        public string $beneficiaryName,
        /** @var numeric-string */
        public string $amount,
        /** Si se reservó del sistema anterior, y no entró por el circuito. */
        public bool $reserved,
    ) {}
}
