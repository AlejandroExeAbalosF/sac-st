<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Ledger\Enums\FundReceiptOrigin;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un cheque que está en la caja de Multas: el papel, de dónde vino y qué
 * multa paga.
 */
#[TypeScript]
final class FineChequeInCustodyData extends Data
{
    public function __construct(
        public int $id,
        public ?string $number,
        public ?string $bank,
        public ?string $issueDate,
        /** @var numeric-string */
        public string $amount,
        /** Desde cuándo está en la caja. */
        public string $receivedDate,
        public FundReceiptOrigin $origin,
        /** La multa que paga, por la raíz de su cadena; nula si es de la apertura y no se aplicó. */
        public ?int $fineId,
        /** La ficha de esa multa, armada por el servidor; nula como `fineId`. */
        public ?string $fineHref,
        public ?string $caseLabel,
        public ?string $caption,
        /** Lo que declaró la apertura, si es de la apertura. */
        public ?string $declaredExpediente,
        public ?string $declaredDrawer,
    ) {}
}
