<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * Qué clase de asignación es — §9.4 del DER.
 *
 * `Allocation` es el caso normal y el único que esta tanda usa. Los otros
 * dos existen en el esquema desde ahora para no tener que migrar una tabla
 * append-only cuando lleguen sus circuitos.
 */
enum AllocationKind: string
{
    /** El caso normal: parte de una recepción financia una cuota. */
    case Allocation = 'allocation';

    /**
     * El redondeo del efectivo — §2.4 del DER.
     *
     * **La única excepción admitida** a la regla de no sobreasignar una
     * cuota, y existe porque el área confirmó el hecho: cuando el
     * empleador redondea hacia arriba en efectivo, se ingresa todo lo
     * recibido y se le entrega todo al beneficiario. El derecho —el
     * `expected_amount` de la cuota— no se toca, porque ese importe es lo
     * que dice el expediente.
     *
     * Solo vale con `medium = cash`, y por eso no entra en esta tanda: el
     * circuito de efectivo todavía no existe.
     */
    case CashRoundingSurplus = 'cash_rounding_surplus';

    /** La contracara de una asignación anterior. No la borra: la resta. */
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Allocation => 'Asignación',
            self::CashRoundingSurplus => 'Excedente de redondeo en efectivo',
            self::Reversal => 'Reversión',
        };
    }

    /** Si suma contra el importe esperado de la cuota. */
    public function countsTowardExpected(): bool
    {
        return $this === self::Allocation;
    }
}
