<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Qué se decidió sobre un problema de una fila importada. */
#[TypeScript]
enum FineImportAction: string
{
    case Exclude = 'exclude';
    case Accept = 'accept';
    case WithoutPayments = 'without_payments';
    case IgnoreColumns = 'ignore_columns';
    case Link = 'link';
    case AddMonths = 'add_months';
    case SameCase = 'same_case';
    case NewCase = 'new_case';
    case Keep = 'keep';

    public function label(): string
    {
        return match ($this) {
            self::Exclude => 'Excluir la fila',
            self::Accept => 'Aceptar los pagos mensuales como están',
            self::WithoutPayments => 'Importar sin los pagos de la fila',
            self::IgnoreColumns => 'Importar sin esa columna',
            self::Link => 'Vincular a la multa ya cargada',
            self::AddMonths => 'Agregar los meses nuevos',
            self::SameCase => 'Es el mismo expediente',
            self::NewCase => 'Es otro expediente',
            self::Keep => 'Importar esta y excluir las demás',
        };
    }
}
