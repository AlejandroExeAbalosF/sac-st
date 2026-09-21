<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * Medio previsto para la cuota.
 *
 * Es informativo y no determina nada: el medio efectivo lo fija la primera
 * recepción vinculada, y una segunda con medio distinto se rechaza, porque
 * haría ambiguo el medio impreso en el recibo.
 */
enum ExpectedMedium: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Bank = 'bank';
}
