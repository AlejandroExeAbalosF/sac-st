<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * Los dos comprobantes que el área emite — §9.8 del DER.
 *
 * El de ingreso documenta que el dinero entró y de quién; el de egreso,
 * que salió y hacia quién. Son documentos distintos con numeración
 * distinta, y por eso el tipo no es un detalle de presentación.
 */
enum ReceiptType: string
{
    /** «Recibí de»: quien depositó. */
    case Income = 'income';

    /** El beneficiario que cobró. */
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Income => 'Recibo de ingreso',
            self::Expense => 'Recibo de egreso',
        };
    }

    /** La serie de la que toma su número. */
    public function seriesCode(): string
    {
        return match ($this) {
            self::Income => '0010',
            self::Expense => '0020',
        };
    }
}
