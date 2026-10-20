<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Support\PartialDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un pago o una devolución anterior al sistema, con su revisión.
 */
#[TypeScript]
final class FineHistoricalMovementData extends Data
{
    public function __construct(
        public int $id,
        public HistoricalMovementKind $kind,
        /** @var numeric-string */
        public string $amount,
        public string $occurredOn,
        public DatePrecision $occurredPrecision,
        public string $evidence,
        public HistoricalMovementStatus $status,
        public ?string $reviewNote,
        public ?string $reviewedByName,
        public ?string $reviewedAt,
        public ?string $createdByName,
        public ?string $createdAt,
        /** @var list<FineAttachmentData> */
        public array $attachments,
    ) {}

    /**
     * Espera cargados `creator` y `reviewer`.
     *
     * @param  list<FineAttachmentData>  $adjuntos
     */
    public static function fromModel(FineHistoricalMovement $antecedente, array $adjuntos): self
    {
        $autor = $antecedente->creator;
        $revisor = $antecedente->reviewer;

        return new self(
            id: $antecedente->id,
            kind: $antecedente->kind,
            amount: (string) $antecedente->amount,
            occurredOn: PartialDate::fromStored($antecedente->occurred_on?->toDateString(), $antecedente->occurred_precision)->toInput(),
            occurredPrecision: $antecedente->occurred_precision,
            evidence: $antecedente->evidence_text,
            status: $antecedente->status,
            reviewNote: $antecedente->review_note,
            reviewedByName: $revisor instanceof User ? $revisor->name : null,
            reviewedAt: $antecedente->reviewed_at?->toIso8601String(),
            createdByName: $autor instanceof User ? $autor->name : null,
            createdAt: $antecedente->created_at?->toIso8601String(),
            attachments: $adjuntos,
        );
    }
}
