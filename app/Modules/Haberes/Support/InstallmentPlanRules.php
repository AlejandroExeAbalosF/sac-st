<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Support\Money\Decimal;

/**
 * Lo que se debe de las cuotas contra el importe reconocido del haber.
 *
 * Cuentan los importes **a abonar**: el excedente que entró en una cuota se
 * cobra y se paga, pero no forma parte de lo reconocido. Tres reglas:
 *
 * - lo que se debe no supera lo reconocido: lo que entró de más se registra en
 *   su cuota, con «Registrar importe mayor»;
 * - mientras queden cuotas previstas sin cargar no lo agota, porque cada una
 *   necesita un importe positivo;
 * - con el plan completo lo cubre entero.
 *
 * La base repite la primera (`check_installments_sum`). Las otras dos dependen
 * de cuántas cuotas se prevén, que solo conoce el plan.
 *
 * Devuelve el mensaje para el operador, o `null` si la suma vale.
 */
final class InstallmentPlanRules
{
    /**
     * @param  numeric-string  $suma  Lo que se debe de las cuotas cargadas, con la nueva.
     * @param  numeric-string  $reconocido
     */
    public static function problema(string $suma, string $reconocido, bool $completaElPlan): ?string
    {
        $comparacion = bccomp($suma, $reconocido, 2);

        if ($comparacion === 1) {
            return 'Lo que se debe de las cuotas superaría el importe reconocido del haber. Si entró de más, registralo en la cuota con «Registrar importe mayor».';
        }

        if (! $completaElPlan && $comparacion === 0) {
            return 'Todavía hay cuotas previstas sin cargar: debe quedar saldo para ellas.';
        }

        if ($completaElPlan && $comparacion === -1) {
            $faltan = Decimal::format(Decimal::sub($reconocido, $suma));

            return "Es la última cuota prevista: debe cubrir el importe reconocido del haber. Faltan $ {$faltan}.";
        }

        return null;
    }
}
