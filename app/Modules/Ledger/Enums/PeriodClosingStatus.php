<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * El estado de un cierre de período — §9.9 del DER.
 *
 * `reopened` no es «vuelve a borrador»: es un estado propio y deliberado.
 * Un período que se reabrió y otro que nunca se cerró no son lo mismo —el
 * primero tuvo un cierre, alguien lo deshizo y dejó su motivo—, y
 * confundirlos borraría exactamente el rastro que la reapertura existe
 * para dejar.
 */
enum PeriodClosingStatus: string
{
    /** Los totales se están calculando; todavía no hay snapshot firme. */
    case Draft = 'draft';

    /** Congelado. No admite operaciones retroactivas ni cambios. */
    case Closed = 'closed';

    /** Se deshizo el cierre, con motivo, usuario y fecha. */
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'En preparación',
            self::Closed => 'Cerrado',
            self::Reopened => 'Reabierto',
        };
    }

    /** Si el período traba las operaciones con fecha adentro. */
    public function blocksOperations(): bool
    {
        return $this === self::Closed;
    }
}
