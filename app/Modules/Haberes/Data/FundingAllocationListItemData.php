<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Models\FundingAllocation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Una asignación ya hecha: a qué cuota fue a parar parte de la recepción. */
#[TypeScript]
final class FundingAllocationListItemData extends Data
{
    public function __construct(
        public int $id,
        /** @var numeric-string */
        public string $amount,
        public AllocationKind $kind,
        public string $allocatedAt,
        public ?string $allocatedByName,
        public int $installmentId,
        public int $installmentNumber,
        public string $beneficiaryName,
        public string $expedienteNumber,
        public int $expedienteId,
        public ?string $notes,
    ) {}

    public static function fromModel(FundingAllocation $allocation): self
    {
        $cuota = $allocation->installment;
        $haber = $cuota->haber;

        return new self(
            id: $allocation->id,
            amount: $allocation->amount,
            kind: $allocation->allocation_kind,
            allocatedAt: $allocation->allocated_at->format('Y-m-d H:i'),
            allocatedByName: $allocation->allocatedBy?->name,
            installmentId: $cuota->id,
            installmentNumber: $cuota->installment_number,
            beneficiaryName: $haber->beneficiary->name,
            expedienteNumber: $haber->expediente->display_number,
            expedienteId: $haber->expediente->id,
            notes: $allocation->notes,
        );
    }
}
