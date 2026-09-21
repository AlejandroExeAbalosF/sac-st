<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Support\FundingSourceRow;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un renglón del cuadro de depósitos, en la pantalla.
 *
 * ```text
 * DEPÓSITO U OPERACIÓN N° | FECHA      | CTA. CTE. | IMPORTE
 * 83690105                | 28/5/2026  | 23456789  | $2.892.402,00
 * ```
 *
 * Se muestra en el modal antes de emitir, y no por adorno: es la
 * justificación que el área le da al organismo, y quien firma tiene que
 * poder verla antes de que quede congelada.
 *
 * El importe viaja como texto sin formatear —la fecha en ISO, el número
 * con punto decimal— y lo formatea `lib/format.ts`. Ningún importe se
 * formatea en línea.
 */
#[TypeScript]
final class OrderDepositRowData extends Data
{
    public function __construct(
        public ?string $operationNumber,
        public ?string $operationDate,
        public ?string $bankAccountNumber,
        public ?string $bankName,
        /** @var numeric-string */
        public string $amount,
    ) {}

    public static function fromRow(FundingSourceRow $row): self
    {
        return new self(
            operationNumber: $row->operationNumber,
            operationDate: $row->operationDate?->format('Y-m-d'),
            bankAccountNumber: $row->bankAccountNumber,
            bankName: $row->bankName,
            amount: $row->amount,
        );
    }
}
