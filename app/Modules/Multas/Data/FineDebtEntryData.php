<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Support\PartialDate;
use App\Modules\Shared\Support\Reason;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un movimiento de la obligación, como lo lee la ficha.
 */
#[TypeScript]
final class FineDebtEntryData extends Data
{
    public function __construct(
        public int $id,
        public FineDebtEntryKind $kind,
        /** @var numeric-string */
        public string $amount,
        public string $effectiveOn,
        public DatePrecision $effectivePrecision,
        public ?string $documentLabel,
        /** Un aumento o una reducción sin resolución: corrige un importe mal cargado. */
        public bool $isCorrection,
        public ?string $reason,
        public ?int $reversalOfId,
        /** El id de la reversión que lo deshizo, si la hubo. */
        public ?int $reversedById,
        public ?string $createdByName,
        public ?string $createdAt,
    ) {}

    /**
     * Espera cargados `document`, `reversedBy` y `creator`.
     */
    public static function fromModel(FineDebtEntry $movimiento): self
    {
        $autor = $movimiento->creator;

        return new self(
            id: $movimiento->id,
            kind: $movimiento->kind,
            amount: (string) $movimiento->amount,
            effectiveOn: PartialDate::fromStored($movimiento->effective_on?->toDateString(), $movimiento->effective_precision)->toInput(),
            effectivePrecision: $movimiento->effective_precision,
            documentLabel: $movimiento->document?->label(),
            isCorrection: $movimiento->isAmountCorrection(),
            reason: Reason::text($movimiento->reason_code, $movimiento->reason),
            reversalOfId: $movimiento->reversal_of_id,
            reversedById: $movimiento->reversedBy?->id,
            createdByName: $autor instanceof User ? $autor->name : null,
            createdAt: $movimiento->created_at?->toIso8601String(),
        );
    }
}
