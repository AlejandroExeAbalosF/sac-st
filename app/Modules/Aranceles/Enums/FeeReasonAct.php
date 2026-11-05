<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Enums;

use App\Modules\Shared\Support\Reason;
use App\Modules\Shared\Support\ReasonAct;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Los actos de Aranceles que piden motivo, cada uno con su lista. */
#[TypeScript]
enum FeeReasonAct: string implements ReasonAct
{
    case AmountChange = 'amount_change';
    case CollectionVoid = 'collection_void';
    case ReceiptReplacement = 'receipt_replacement';
    case ChargeCorrection = 'charge_correction';

    /**
     * @return list<FeeReasonCode>
     */
    public function codes(): array
    {
        return match ($this) {
            self::AmountChange => [FeeReasonCode::Rounding, FeeReasonCode::Exception, FeeReasonCode::Other],
            self::CollectionVoid => [FeeReasonCode::DataEntryError, FeeReasonCode::Duplicate, FeeReasonCode::Other],
            self::ReceiptReplacement => [FeeReasonCode::WrongPayer, FeeReasonCode::WrongCuit, FeeReasonCode::WrongWording, FeeReasonCode::Other],
            self::ChargeCorrection => [FeeReasonCode::WrongType, FeeReasonCode::WrongQuantity, FeeReasonCode::WrongAmount, FeeReasonCode::Other],
        };
    }

    /**
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    public static function options(): array
    {
        return Reason::options(self::class);
    }
}
