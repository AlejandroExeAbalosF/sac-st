<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

/**
 * Dónde está la plata de un cobro de la caja (M06).
 *
 * Se deriva del traslado vigente, no se guarda: en la caja (o en custodia,
 * si es un cheque) hasta depositarlo; en tránsito hasta que el extracto lo
 * acredite; en el banco después. Un cheque rechazado ya no es plata: espera
 * el cheque que lo reemplace.
 */
enum FineCollectionPlace: string
{
    case InCustody = 'in_custody';
    case InTransit = 'in_transit';
    case InBank = 'in_bank';

    /** El cheque vigente se rechazó y espera su reemplazo (M03). */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::InCustody => 'En la caja',
            self::InTransit => 'Depositado, sin acreditar',
            self::InBank => 'Acreditado en el banco',
            self::Rejected => 'Cheque rechazado',
        };
    }
}
