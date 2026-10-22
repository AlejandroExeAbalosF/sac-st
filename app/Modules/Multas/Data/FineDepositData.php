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
 * quedan en el historial con su motivo. Si el cobro fue con cheque, el cheque
 * vigente de la cadena y sus rechazos.
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
        /** El cheque vigente de la cadena: el original o su último reemplazo. */
        public ?string $chequeNumber = null,
        public ?string $chequeBank = null,
        /** El rechazo del cheque vigente, si está rechazado y sin reemplazo. */
        public ?int $openRejectionId = null,
        /**
         * Los rechazos de la cadena, del primero al último (M03).
         *
         * @var list<FineChequeRejectionData>
         */
        public array $rejections = [],
    ) {}
}
