<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un pago de la planilla desde el mes de la apertura: se identifica a mano. */
#[TypeScript]
enum FineImportCellStatus: string
{
    case Pending = 'pending';
    case Identified = 'identified';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente de identificar',
            self::Identified => 'Identificado',
            self::Dismissed => 'Descartado',
        };
    }
}
