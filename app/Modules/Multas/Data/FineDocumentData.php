<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineActType;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\PartialDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una resolución, un acta u otro papel, con lo que dice el papel y cómo se leyó.
 */
#[TypeScript]
final class FineDocumentData extends Data
{
    public function __construct(
        public int $id,
        public FineDocumentKind $kind,
        public ?FineActType $actType,
        public ?int $number,
        public ?int $year,
        public string $originalText,
        public string $label,
        /** «2026-05-07», «2026-05» o vacío: la forma en que vuelve al formulario. */
        public string $issuedOn,
        public DatePrecision $issuedPrecision,
        public FineProvenance $provenance,
        /**
         * Los papeles que cumple en la multa que se está mirando. Vacío
         * cuando se lista desde el expediente.
         *
         * @var list<FineDocumentRole>
         */
        public array $roles = [],
    ) {}

    /**
     * @param  list<FineDocumentRole>  $roles
     */
    public static function fromModel(FineDocument $documento, array $roles = []): self
    {
        return new self(
            id: $documento->id,
            kind: $documento->kind,
            actType: $documento->act_type,
            number: $documento->number,
            year: $documento->year,
            originalText: $documento->original_text,
            label: $documento->label(),
            issuedOn: PartialDate::fromStored($documento->issued_on?->toDateString(), $documento->issued_precision)->toInput(),
            issuedPrecision: $documento->issued_precision,
            provenance: $documento->provenance,
            roles: $roles,
        );
    }
}
