<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

/**
 * Dónde está la plata de un cobro de la caja (M06).
 *
 * Se deriva del traslado vigente, no se guarda: en la caja (o en custodia,
 * si es un cheque) hasta depositarlo; en tránsito hasta que el extracto lo
 * acredite; en el banco después.
 */
enum FineCollectionPlace: string
{
    case InCustody = 'in_custody';
    case InTransit = 'in_transit';
    case InBank = 'in_bank';

    public function label(): string
    {
        return match ($this) {
            self::InCustody => 'En la caja',
            self::InTransit => 'Depositado, sin acreditar',
            self::InBank => 'Acreditado en el banco',
        };
    }
}
