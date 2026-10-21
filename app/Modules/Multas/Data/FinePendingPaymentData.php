<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Models\FinePayment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un pago que espera a alguien: informado sin verificar, o recurso en
 * revisión. Lleva la multa para poder ir a ella.
 */
#[TypeScript]
final class FinePendingPaymentData extends Data
{
    public function __construct(
        public int $fineId,
        public string $fineCaption,
        public string $caseLabel,
        public FinePaymentData $payment,
    ) {}

    /** Espera cargados `fine.fineCase` y lo que pide `FinePaymentData`. */
    public static function fromModel(FinePayment $pago): self
    {
        return new self(
            fineId: $pago->fine_id,
            fineCaption: $pago->fine->caption,
            caseLabel: $pago->fine->fineCase->label(),
            payment: FinePaymentData::fromModel($pago),
        );
    }
}
