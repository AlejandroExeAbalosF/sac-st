<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Support\Money\Decimal;

/**
 * Lo que se paga para recurrir: el 20 % del importe vigente de la multa, con
 * sus ajustes y sin descontar pagos anteriores (M07).
 *
 * Se calcula con decimal exacto y se descartan los centavos, a favor del
 * deudor: $ 100.002,50 × 20 % = $ 20.000,50 → se exigen $ 20.000,00. Es
 * «exactamente», no «al menos». La base repite la cuenta
 * (`fine_payments_appeal_check`).
 */
final class AppealAmount
{
    /** @return numeric-string */
    public static function required(string $base): string
    {
        return Decimal::scale(bcdiv(bcmul(Decimal::scale($base), '20', 2), '100', 0));
    }
}
