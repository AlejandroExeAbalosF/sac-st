<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Sentido del movimiento, visto desde la cuenta del organismo.
 *
 * El importe siempre se guarda positivo y el sentido va acá. Los dos
 * formatos de MacroOnline lo expresan distinto —el CSV usa columnas
 * separadas de débito y crédito, el Excel un único importe con signo— y
 * ambos terminan en esto.
 */
enum TransactionDirection: string
{
    /** Entró dinero a la cuenta. */
    case Credit = 'credit';

    /** Salió dinero de la cuenta. */
    case Debit = 'debit';

    public function label(): string
    {
        return match ($this) {
            self::Credit => 'Crédito',
            self::Debit => 'Débito',
        };
    }
}
