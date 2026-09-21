<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\Haber;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el haber guarda del acta y la ficha no muestra de entrada.
 *
 * Son los datos que dicen de dónde sale el derecho: qué resolución lo
 * reconoce y de qué fecha. No se leen todos los días, pero cuando alguien
 * discute un importe son los que hay que poder citar.
 *
 * Viajan aparte de `HaberListItemData`, que la usan el listado y el
 * acordeón del expediente: ahí serían peso muerto en cada haber de cada
 * fila.
 */
#[TypeScript]
final class HaberExtraData extends Data
{
    public function __construct(
        /** Fecha del acta o de la resolución que reconoce el haber. */
        public ?string $legalDate,
        /** Su identificación: «Res. N° 3269/2025». */
        public ?string $resolutionReference,
        /** Si se salda de una vez o en cuotas. */
        public PaymentTerms $paymentTerms,
    ) {}

    public static function fromModel(Haber $haber): self
    {
        return new self(
            legalDate: $haber->legal_date?->format('Y-m-d'),
            resolutionReference: $haber->resolution_reference,
            paymentTerms: $haber->payment_terms,
        );
    }
}
