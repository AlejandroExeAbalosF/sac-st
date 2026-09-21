<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * Si el comprobante lo emitió el sistema o se hizo en papel.
 *
 * El número siempre lo pone el sistema; lo que el origen distingue es de
 * dónde salió el papel. Un recibo confeccionado a mano durante un corte de
 * luz recibe igual su `0011/00000042`, y el número preimpreso queda como
 * referencia.
 */
enum SeriesOrigin: string
{
    case System = 'system';
    case TalonarioLoaded = 'talonario_loaded';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Emitido por el sistema',
            self::TalonarioLoaded => 'Confeccionado en talonario',
        };
    }
}
