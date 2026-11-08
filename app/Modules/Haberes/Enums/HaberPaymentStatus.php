<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use App\Support\Money\Decimal;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cuánto del haber ya se le pagó al beneficiario.
 *
 * **No se guarda: se deriva de las cuotas.** Un pago se puede deshacer
 * —anular un registro de pago fuera del circuito devuelve la cuota a
 * pendiente— y un estado guardado tendría que acordarse de volver atrás
 * en cada una de esas operaciones. Derivado, no puede contradecir a las
 * cuotas porque es lo que las cuotas dicen.
 *
 * Completa a `HaberWorkflowStatus`, no lo reemplaza: aquel dice si el
 * haber puede avanzar (activo o anulado); esto, en qué va su pago. Solo
 * tiene sentido mientras el haber está activo.
 */
#[TypeScript]
enum HaberPaymentStatus: string
{
    /** Las cuotas cargadas todavía no cubren el importe reconocido. */
    case IncompletePlan = 'incomplete_plan';

    /** El plan cubre el importe y ninguna cuota se pagó. */
    case Unpaid = 'unpaid';

    /** Hay cuotas pagadas, pero no cubren el importe reconocido. */
    case PartiallyPaid = 'partially_paid';

    /** Lo pagado cubre todo el importe reconocido. */
    case FullyPaid = 'fully_paid';

    public function label(): string
    {
        return match ($this) {
            self::IncompletePlan => 'Plan incompleto',
            self::Unpaid => 'Sin pagos',
            self::PartiallyPaid => 'Pago parcial',
            self::FullyPaid => 'Pago completado',
        };
    }

    /**
     * La situación que resulta de las cuotas del haber.
     *
     * Se comparan **importes**, no cantidades: la cantidad prevista de
     * cuotas puede no estar cargada, y entonces «1 de 1 pagadas» diría
     * completo un haber de $400.000 con una sola cuota de $100.000.
     *
     * Cuentan como pagadas las del circuito y las saldadas en papel antes
     * de la apertura: a ninguna de las dos se le debe nada. Las anuladas no
     * cuentan para nada, igual que en la suma del plan que vigila la base.
     *
     * @param  numeric-string  $assignedAmount  El importe reconocido.
     * @param  iterable<array{status: InstallmentWorkflowStatus, amount: numeric-string}>  $installments
     */
    public static function fromInstallments(string $assignedAmount, iterable $installments): self
    {
        $planificado = '0.00';
        $pagado = '0.00';
        $hayPagadas = false;

        foreach ($installments as $cuota) {
            if ($cuota['status'] === InstallmentWorkflowStatus::Cancelled) {
                continue;
            }

            $planificado = Decimal::add($planificado, $cuota['amount']);

            if (in_array($cuota['status'], [InstallmentWorkflowStatus::Paid, InstallmentWorkflowStatus::LegacySettled], true)) {
                $pagado = Decimal::add($pagado, $cuota['amount']);
                $hayPagadas = true;
            }
        }

        // El orden de las preguntas es la regla: lo pagado manda sobre el plan.
        if (! Decimal::isNegative(Decimal::sub($pagado, $assignedAmount))) {
            return self::FullyPaid;
        }

        if ($hayPagadas) {
            return self::PartiallyPaid;
        }

        if (Decimal::isNegative(Decimal::sub($planificado, $assignedAmount))) {
            return self::IncompletePlan;
        }

        return self::Unpaid;
    }
}
