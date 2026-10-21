<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Models\User;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\PartialDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La ficha de la multa: sus datos, sus papeles, su obligación y su saldo.
 */
#[TypeScript]
final class FineDetailData extends Data
{
    public function __construct(
        public int $id,
        public int $caseId,
        public string $caseLabel,
        public string $caseOriginalText,
        public string $caption,
        public string $captionOriginal,
        public ?int $personId,
        public ?string $personName,
        /** Lo que necesita el buscador de personas para mostrar la elegida. */
        public ?string $personDocument,
        /** `individual` o `company`. */
        public ?string $personType,
        public string $registeredOn,
        public DatePrecision $registeredPrecision,
        public FineProvenance $provenance,
        /** @var list<FineDocumentData> */
        public array $documents,
        /** @var list<FineDebtEntryData> */
        public array $debtEntries,
        /** @var list<FineHistoricalMovementData> */
        public array $historicalMovements,
        public FineBalanceData $balance,
        /** @var list<FinePaymentData> */
        public array $payments,
        public ?string $createdByName,
        public ?string $createdAt,
        /** @var list<FineAttachmentData> */
        public array $attachments,
    ) {}

    /**
     * Espera cargados `fineCase`, `person`, `documents`, `balance`, `creator`,
     * `debtEntries.{document,reversedBy,creator}` e
     * `historicalMovements.{creator,reviewer,bankTransaction}` y
     * `payments.{application.fundReceipt,paymentReceipt.receipt,creator}`.
     *
     * @param  list<FineAttachmentData>  $adjuntos  los de la multa
     * @param  array<int, list<FineAttachmentData>>  $adjuntosDeAntecedentes  por id del pago anterior
     * @param  array<int, list<FineAttachmentData>>  $adjuntosDePagos  por id del pago
     * @param  array<int, FineDepositData>  $depositos  por id del pago: dónde está la plata de cada cobro de caja
     */
    public static function fromModel(Fine $multa, array $adjuntos, array $adjuntosDeAntecedentes, array $adjuntosDePagos = [], array $depositos = []): self
    {
        $autor = $multa->creator;

        /*
         * Un documento que cumple dos papeles llega dos veces en la relación;
         * en la ficha va una vez, con sus papeles juntos.
         */
        $documentos = $multa->documents
            ->groupBy('id')
            ->map(fn ($repetidos): FineDocumentData => FineDocumentData::fromModel(
                $repetidos->first(),
                array_values($repetidos
                    ->map(fn (FineDocument $documento): FineDocumentRole => FineDocumentRole::from(self::rol($documento)))
                    ->all()),
            ))
            ->all();

        return new self(
            id: $multa->id,
            caseId: $multa->fine_case_id,
            caseLabel: $multa->fineCase->label(),
            caseOriginalText: $multa->fineCase->original_text,
            caption: $multa->caption,
            captionOriginal: $multa->caption_original,
            personId: $multa->person_id,
            personName: $multa->person?->name,
            personDocument: $multa->person?->document,
            personType: $multa->person?->type,
            registeredOn: PartialDate::fromStored($multa->registered_on?->toDateString(), $multa->registered_precision)->toInput(),
            registeredPrecision: $multa->registered_precision,
            provenance: $multa->provenance,
            documents: array_values($documentos),
            debtEntries: array_values($multa->debtEntries
                ->sortBy('id')
                ->map(fn (FineDebtEntry $movimiento): FineDebtEntryData => FineDebtEntryData::fromModel($movimiento))
                ->all()),
            historicalMovements: array_values($multa->historicalMovements
                ->sortBy('id')
                ->map(fn (FineHistoricalMovement $antecedente): FineHistoricalMovementData => FineHistoricalMovementData::fromModel(
                    $antecedente,
                    $adjuntosDeAntecedentes[$antecedente->id] ?? [],
                ))
                ->all()),
            balance: FineBalanceData::fromModel($multa->balance),
            payments: array_values($multa->payments
                ->map(fn (FinePayment $pago): FinePaymentData => FinePaymentData::fromModel($pago, $adjuntosDePagos[$pago->id] ?? [], $depositos[$pago->id] ?? null))
                ->all()),
            createdByName: $autor instanceof User ? $autor->name : null,
            createdAt: $multa->created_at?->toIso8601String(),
            attachments: $adjuntos,
        );
    }

    private static function rol(FineDocument $documento): string
    {
        /** @var object{role: string} $pivote */
        $pivote = $documento->getRelationValue('pivot');

        return $pivote->role;
    }
}
