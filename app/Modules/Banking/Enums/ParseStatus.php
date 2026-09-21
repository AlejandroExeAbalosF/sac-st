<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Resultado de interpretar una fila del extracto.
 *
 * `Warning` existe para la fila que se entendió pero arrastra una duda
 * —un saldo posterior ausente, por ejemplo, que impide deduplicarla con
 * certeza—. Entra al sistema y queda señalada, que es distinto de
 * rechazarla.
 */
enum ParseStatus: string
{
    case Valid = 'valid';
    case Warning = 'warning';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Interpretada',
            self::Warning => 'Con observación',
            self::Rejected => 'Rechazada',
        };
    }
}
