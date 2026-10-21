<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\FineCollectionPlace;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Dónde está la plata de un cobro de la caja, y su depósito (M06).
 *
 * El depósito vigente, si lo hay, con su ticket; y los cancelados, que
 * quedan en el historial con su motivo.
 */
#[TypeScript]
final class FineDepositData extends Data
{
    public function __construct(
        public FineCollectionPlace $place,
        public ?int $transferId,
        public ?string $depositDate,
        public ?string $operationNumber,
        /** El día del crédito en el extracto. */
        public ?string $creditedOn,
        public ?FineAttachmentData $ticket,
        /** @var list<FineCancelledDepositData> */
        public array $cancelled,
    ) {}
}
