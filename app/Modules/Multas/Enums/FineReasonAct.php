<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los actos de Multas que piden motivo, cada uno con su lista.
 *
 * La lista es la que ofrece la pantalla y valida el Form Request; la base
 * repite la misma en el CHECK de cada columna
 * (`2026_10_27_020000_add_reason_codes_to_fines`).
 */
#[TypeScript]
enum FineReasonAct: string
{
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
    case PaymentDiscard = 'payment_discard';
    case LinkRevocation = 'link_revocation';
    case Correction = 'correction';
    case HistoricalRejection = 'historical_rejection';

    /**
     * @return list<FineReasonCode>
     */
    public function codes(): array
    {
        return match ($this) {
            self::Adjustment => [FineReasonCode::AppealAdmitted, FineReasonCode::CourtRuling, FineReasonCode::ResolutionCorrection, FineReasonCode::Other],
            self::Reversal => [FineReasonCode::DataEntryError, FineReasonCode::WrongFine, FineReasonCode::Duplicate, FineReasonCode::Other],
            self::PaymentDiscard => [FineReasonCode::DataEntryError, FineReasonCode::Duplicate, FineReasonCode::WrongFine, FineReasonCode::NotCredited, FineReasonCode::Other],
            self::LinkRevocation => [FineReasonCode::DataEntryError, FineReasonCode::WrongDocument, FineReasonCode::WrongFine, FineReasonCode::Duplicate, FineReasonCode::Other],
            self::Correction => [FineReasonCode::DataEntryError, FineReasonCode::MisreadPaper, FineReasonCode::Other],
            self::HistoricalRejection => [FineReasonCode::NoEvidence, FineReasonCode::Duplicate, FineReasonCode::WrongFine, FineReasonCode::DataEntryError, FineReasonCode::Other],
        };
    }

    /**
     * Lo que ofrece cada pantalla, en el orden de la lista.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $opciones = [];

        foreach (self::cases() as $acto) {
            $opciones[$acto->value] = array_map(
                static fn (FineReasonCode $codigo): array => ['value' => $codigo->value, 'label' => $codigo->label()],
                $acto->codes(),
            );
        }

        return $opciones;
    }
}
