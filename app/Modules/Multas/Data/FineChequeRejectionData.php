<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Banking\Enums\ChequeDisposition;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un rechazo de la cadena de cheques de un cobro (M03), con sus dos
 * seguimientos: la regularización de la empresa y lo que dice el banco. Y lo
 * que se completa después: el aviso y qué se hizo con el papel.
 */
#[TypeScript]
final class FineChequeRejectionData extends Data
{
    public function __construct(
        public int $id,
        public string $chequeNumber,
        public ?string $chequeBank,
        public string $rejectedOn,
        public string $reason,
        public ?string $registeredByName,
        public ChequeRejectionBankStatus $bankStatus,
        /** Si se depositó: el depósito devuelto o acreditado. */
        public ?string $depositDate,
        public ?string $creditOn,
        public ?string $debitOn,
        public ?string $neverCreditedNote,
        public ?string $companyNotifiedOn,
        public ?ChequeDisposition $disposition,
        public bool $regularized,
        public ?string $replacementChequeNumber,
        /** @var list<FineAttachmentData> */
        public array $attachments,
    ) {}
}
