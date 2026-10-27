<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use App\Modules\Shared\Support\Reason;
use App\Modules\Shared\Support\ReasonAct;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los actos de Haberes que piden motivo, cada uno con su lista.
 *
 * La lista es la que ofrece la pantalla y valida el Form Request; la base
 * repite la misma en el CHECK de cada columna
 * (`2026_10_27_030000_add_reason_codes_to_haberes`). Anular y reactivar un
 * expediente o un haber no tienen columna: el motivo queda en la auditoría.
 */
#[TypeScript]
enum HaberesReasonAct: string implements ReasonAct
{
    case Cancellation = 'cancellation';
    case Reactivation = 'reactivation';
    case PaymentOrderVoid = 'payment_order_void';
    case InstallmentUnlock = 'installment_unlock';
    case LegacySettlementVoid = 'legacy_settlement_void';
    case LegacyDocumentVoid = 'legacy_document_void';
    case DepositTicketDiscard = 'deposit_ticket_discard';

    /**
     * @return list<HaberesReasonCode>
     */
    public function codes(): array
    {
        return match ($this) {
            self::Cancellation => [HaberesReasonCode::DataEntryError, HaberesReasonCode::Duplicate, HaberesReasonCode::CaseArchived, HaberesReasonCode::Other],
            self::Reactivation => [HaberesReasonCode::CancelledByMistake, HaberesReasonCode::CaseReopened, HaberesReasonCode::Other],
            self::PaymentOrderVoid => [HaberesReasonCode::WrongBeneficiaryData, HaberesReasonCode::WrongAmount, HaberesReasonCode::WrongInstallment, HaberesReasonCode::SafObservation, HaberesReasonCode::Other],
            self::InstallmentUnlock => [HaberesReasonCode::SafObservation, HaberesReasonCode::DataEntryError, HaberesReasonCode::ResolutionChanged, HaberesReasonCode::Other],
            self::LegacySettlementVoid => [HaberesReasonCode::WrongInstallment, HaberesReasonCode::WrongAmount, HaberesReasonCode::Duplicate, HaberesReasonCode::DataEntryError, HaberesReasonCode::Other],
            self::LegacyDocumentVoid => [HaberesReasonCode::MistypedNumber, HaberesReasonCode::WrongInstallment, HaberesReasonCode::Duplicate, HaberesReasonCode::Other],
            self::DepositTicketDiscard => [HaberesReasonCode::DepositNotMade, HaberesReasonCode::WrongReceipt, HaberesReasonCode::WrongExpediente, HaberesReasonCode::Duplicate, HaberesReasonCode::Other],
        };
    }

    /**
     * Lo que ofrece cada pantalla, en el orden de la lista.
     *
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    public static function options(): array
    {
        return Reason::options(self::class);
    }
}
