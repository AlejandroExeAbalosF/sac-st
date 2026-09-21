<?php

declare(strict_types=1);

namespace App\Support\Pdf;

/** Hacia dónde se alinea una columna de la planilla. */
enum WorksheetAlign: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';
}
