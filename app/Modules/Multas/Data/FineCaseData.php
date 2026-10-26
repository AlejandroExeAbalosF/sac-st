<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El expediente de Multas con sus documentos.
 */
#[TypeScript]
final class FineCaseData extends Data
{
    public function __construct(
        public int $id,
        /** El número en la dirección (`169607-2024-0`); `null` si no está normalizado. */
        public ?string $routeNumber,
        public string $label,
        public string $originalText,
        public ?int $number,
        public ?int $year,
        public ?string $suffix,
        public FineProvenance $provenance,
        public ?string $notes,
        /** @var list<FineDocumentData> */
        public array $documents,
        /**
         * Expedientes con el mismo número y año y otro sufijo. No se
         * fusionan (P03): se avisan para que alguien decida.
         *
         * @var list<array{id: int, routeNumber: string|null, label: string}>
         */
        public array $similar,
    ) {}

    /**
     * Espera cargado `documents`.
     */
    public static function fromModel(FineCase $expediente): self
    {
        $similares = $expediente->number === null || $expediente->year === null
            ? collect()
            : FineCase::query()
                ->where('number', $expediente->number)
                ->where('year', $expediente->year)
                ->whereKeyNot($expediente->id)
                ->get(['id', 'original_text', 'number', 'year', 'suffix']);

        return new self(
            id: $expediente->id,
            routeNumber: $expediente->routeNumber(),
            label: $expediente->label(),
            originalText: $expediente->original_text,
            number: $expediente->number,
            year: $expediente->year,
            suffix: $expediente->suffix,
            provenance: $expediente->provenance,
            notes: $expediente->notes,
            documents: array_values($expediente->documents
                ->sortBy('id')
                ->map(fn (FineDocument $documento): FineDocumentData => FineDocumentData::fromModel($documento))
                ->all()),
            similar: array_values($similares
                ->map(fn (FineCase $otro): array => ['id' => $otro->id, 'routeNumber' => $otro->routeNumber(), 'label' => $otro->label()])
                ->all()),
        );
    }
}
