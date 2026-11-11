<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Data\AttachmentHistoryData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un papel del sistema anterior, tal como lo muestra la cuota. */
#[TypeScript]
final class LegacyDocumentData extends Data
{
    public function __construct(
        public int $id,
        public LegacyDocumentKind $kind,
        /** El número de talonario impreso en el papel. */
        public string $number,
        public string $issuedOn,
        /**
         * Lo que dice el papel, aunque la cuota haya cambiado después.
         *
         * @var numeric-string
         */
        public string $amount,
        /** La foto, si se cargó. Es opcional. */
        public ?AttachmentData $attachment,
        /** Cuántas fotos tiene y cuántas anuladas: con la única anulada, el camino a ella. */
        public ?AttachmentHistoryData $attachmentHistory,
        /** Si lo trajo el registro del pago, y cae con él al anularlo. */
        public bool $belongsToSettlement,
    ) {}

    public static function fromModel(LegacyDocument $documento, ?AttachmentData $attachment, ?AttachmentHistoryData $attachmentHistory = null): self
    {
        return new self(
            id: $documento->id,
            kind: $documento->kind,
            number: $documento->number,
            issuedOn: $documento->issued_on->format('Y-m-d'),
            amount: $documento->amount,
            attachment: $attachment,
            attachmentHistory: $attachmentHistory,
            belongsToSettlement: $documento->legacy_settlement_id !== null,
        );
    }
}
