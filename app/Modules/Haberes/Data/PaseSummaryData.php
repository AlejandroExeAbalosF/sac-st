<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\PaseStatus;
use App\Modules\Haberes\Models\Pase;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La nota de Pase, tal como la muestra la tarjeta de la cuota.
 *
 * Sin número propio: el área confirmó que la nota se identifica por el
 * número de su Orden, así que la pantalla la nombra por ahí.
 */
#[TypeScript]
final class PaseSummaryData extends Data
{
    public function __construct(
        public int $id,
        public string $destination,
        public string $issueDate,
        public PaseStatus $status,
        /** El párrafo extra de la nota, cuando el caso pide aclarar algo. */
        public ?string $notes,
    ) {}

    public static function fromModel(Pase $pase): self
    {
        return new self(
            id: $pase->id,
            destination: $pase->destination,
            issueDate: $pase->issue_date->format('Y-m-d'),
            status: $pase->status,
            notes: $pase->notes,
        );
    }
}
