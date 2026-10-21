<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

/**
 * La serie de los recibos de Multas (M09).
 *
 * El código sigue la convención de `document_series`: `02` es la caja de
 * Multas, `1` el recibo de ingreso y `0` el sistema. Correlativo propio,
 * independiente del de Haberes.
 */
final class FineReceiptSeries
{
    public const string CODE = '0210';
}
