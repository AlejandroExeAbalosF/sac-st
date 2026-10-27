<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** La multa y sus vínculos documentales para las hojas del expediente. */
#[TypeScript]
final class FineCaseFineData extends Data
{
    public function __construct(
        public FineListItemData $fine,
        public int $number,
        /** @var list<FineDocumentData> */
        public array $documents,
    ) {}

    /** Espera las mismas relaciones que FineListItemData. */
    public static function fromModel(Fine $multa): self
    {
        $documentos = [];

        foreach ($multa->documents->sortBy('id')->groupBy('id') as $vinculos) {
            /** @var FineDocument $documento */
            $documento = $vinculos->first();
            $roles = [];
            foreach ($vinculos as $vinculo) {
                /** @var object{role: string} $pivote */
                $pivote = $vinculo->getRelationValue('pivot');
                $roles[] = FineDocumentRole::from($pivote->role);
            }
            // Orden estable: origen antes de modificación, ratificación y respaldo.
            $roles = array_values(array_filter(
                FineDocumentRole::cases(),
                static fn (FineDocumentRole $rol): bool => in_array($rol, $roles, true),
            ));
            $documentos[] = FineDocumentData::fromModel($documento, $roles);
        }

        return new self(FineListItemData::fromModel($multa), $multa->fine_number, $documentos);
    }
}
