<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Ledger\Enums\FundReceiptOrigin;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un cheque que está en la caja: el papel, de dónde vino y de quién es.
 */
#[TypeScript]
final class ChequeInCustodyData extends Data
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
        /** Quién lo entregó, si entró por el circuito. */
        public ?string $depositorName,
        /** @var list<ChequeAssignmentData> */
        public array $assignments,
        /**
         * Lo que todavía no tiene dueño: sin identificar si entró por el
         * circuito, sin reservar si es del sistema anterior.
         *
         * @var numeric-string
         */
        public string $unassigned,
    ) {}
}
