<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\PartialDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una fila de la cuenta corriente: la multa con su expediente, sus papeles
 * de origen y su saldo.
 */
#[TypeScript]
final class FineListItemData extends Data
{
    public function __construct(
        public int $id,
        public int $caseId,
        /** El número del expediente en la dirección; `null` si no está normalizado. */
        public ?string $caseRouteNumber,
        public string $caseLabel,
        public string $caption,
        public ?string $personName,
        public string $registeredOn,
        public DatePrecision $registeredPrecision,
        /** @var list<string> */
        public array $originDocuments,
        public FineBalanceData $balance,
        public FineProvenance $provenance,
    ) {}

    /**
     * Espera cargados `fineCase`, `person`, `documents` y `balance`.
     */
    public static function fromModel(Fine $multa): self
    {
        return new self(
            id: $multa->id,
            caseId: $multa->fine_case_id,
            caseRouteNumber: $multa->fineCase->routeNumber(),
            caseLabel: $multa->fineCase->label(),
            caption: $multa->caption,
            personName: $multa->person?->name,
            registeredOn: PartialDate::fromStored($multa->registered_on?->toDateString(), $multa->registered_precision)->toInput(),
            registeredPrecision: $multa->registered_precision,
            originDocuments: array_values($multa->documents
                ->filter(fn (FineDocument $documento): bool => self::rol($documento) === FineDocumentRole::Origin->value)
                ->map(fn (FineDocument $documento): string => $documento->label())
                ->all()),
            balance: FineBalanceData::fromModel($multa->balance),
            provenance: $multa->provenance,
        );
    }

    private static function rol(FineDocument $documento): ?string
    {
        /** @var object{role: string}|null $pivote */
        $pivote = $documento->getRelationValue('pivot');

        return $pivote?->role;
    }
}
