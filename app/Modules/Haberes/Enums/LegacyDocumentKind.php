<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Qué papel del sistema anterior es. */
#[TypeScript]
enum LegacyDocumentKind: string
{
    case IncomeReceipt = 'income_receipt';
    case PaymentOrder = 'payment_order';
    case ExpenseReceipt = 'expense_receipt';

    public function label(): string
    {
        return match ($this) {
            self::IncomeReceipt => 'Recibo de ingreso',
            self::PaymentOrder => 'Orden de Pago',
            self::ExpenseReceipt => 'Recibo de egreso',
        };
    }
}
