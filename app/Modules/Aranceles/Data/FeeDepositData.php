<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Shared\Data\AttachmentData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un traspaso de la caja al banco, con lo que lleva. */
#[TypeScript]
final class FeeDepositData extends Data
{
    /**
     * @param  list<FeeCashShareData>  $shares
     */
    public function __construct(
        public int $id,
        public CashTransferStatus $status,
        public string $amount,
        public string $depositDate,
        public string $registeredAt,
        public string $accountLabel,
        public ?string $depositedByName,
        public ?string $ticketDate,
        public ?string $operationNumber,
        public ?string $terminal,
        public ?string $notes,
        public ?string $creditedOn,
        /** El ticket vigente: si se volvió a cargar, el último. */
        public ?AttachmentData $ticketAttachment,
        public array $shares,
    ) {}
}
