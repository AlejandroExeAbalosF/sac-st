<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** En qué anda un lote de la planilla histórica. */
#[TypeScript]
enum FineImportStatus: string
{
    case Reading = 'reading';
    case Ready = 'ready';
    case Consolidating = 'consolidating';
    case Consolidated = 'consolidated';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'Leyendo la planilla',
            self::Ready => 'Listo para revisar',
            self::Consolidating => 'Consolidando',
            self::Consolidated => 'Consolidado',
            self::Failed => 'No se pudo leer',
        };
    }
}
