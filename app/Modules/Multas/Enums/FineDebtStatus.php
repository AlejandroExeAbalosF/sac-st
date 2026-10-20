<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use App\Support\Money\Decimal;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La situación de la multa, deducida de su saldo (M01, RF-08).
 *
 * No se guarda en ninguna columna: sale de la obligación y lo pagado cada
 * vez que se mira. El excedente no es un estado sino un dato aparte —una
 * multa cancelada puede tenerlo o no—, y la pantalla lo muestra al lado.
 */
#[TypeScript]
enum FineDebtStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Cancelled = 'cancelled';
    case WithoutEffect = 'without_effect';

    public static function of(string $obligation, string $debt): self
    {
        if (Decimal::equals($obligation, '0')) {
            return self::WithoutEffect;
        }

        if (Decimal::equals($debt, '0')) {
            return self::Cancelled;
        }

        return Decimal::equals($debt, $obligation) ? self::Pending : self::Partial;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Con pagos parciales',
            self::Cancelled => 'Cancelada',
            self::WithoutEffect => 'Sin efecto',
        };
    }
}
