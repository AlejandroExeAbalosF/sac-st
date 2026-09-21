<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * El estado de un arqueo — §9.9 del DER.
 *
 * Contar billetes es cargar veinte números y equivocarse en uno es normal,
 * así que el borrador se corrige libremente. Lo que no se corrige es el
 * arqueo cerrado: es el respaldo del cierre del día y del papel que el
 * área archiva, y si pudiera editarse los dos dejarían de decir lo mismo.
 * Lo impone el trigger `cash_counts_frozen`, no una validación.
 */
enum CashCountStatus: string
{
    /** Se está contando. Es el único estado editable. */
    case Draft = 'draft';

    /** Alguien lo miró y lo dio por bueno. */
    case Reviewed = 'reviewed';

    /** La diferencia se imputó contablemente a `CASH_DIFFERENCE`. */
    case Adjusted = 'adjusted';

    /** Congelado. Es evidencia del cierre. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'En borrador',
            self::Reviewed => 'Revisado',
            self::Adjusted => 'Ajustado',
            self::Closed => 'Cerrado',
        };
    }

    /** Si todavía admite cambios en el conteo. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Si sirve como respaldo de un cierre de período. */
    public function isSettled(): bool
    {
        return $this !== self::Draft;
    }
}
