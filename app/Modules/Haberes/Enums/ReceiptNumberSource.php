<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use App\Modules\Shared\Models\Receipt;

/**
 * Cuál de los dos números del recibo de ingreso imprime la Orden.
 *
 * El formulario tiene un solo renglón —«RECIBO DE INGRESO N°»— y el recibo
 * puede tener dos números: el del sistema, que es siempre el identificador,
 * y el del talonario, cuando el papel se escribió a mano. La Orden 3582
 * imprime `72273`, que es un número de talonario.
 *
 * **Se guarda y no se deduce**, por el mismo motivo que
 * `receipts.prints_talonario_number`: es una decisión del operador, y la
 * reimpresión tiene que salir igual que el original. Si el criterio
 * viviera solo en la pantalla, el papel de hoy y su copia de mañana
 * podrían decir números distintos.
 */
enum ReceiptNumberSource: string
{
    /** El correlativo del sistema: `0010/00000042`. */
    case System = 'system';

    /** El preimpreso del talonario: `72273`. */
    case Talonario = 'talonario';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Número del sistema',
            self::Talonario => 'Número del talonario',
        };
    }

    /**
     * El texto que va impreso, resuelto contra el recibo.
     *
     * Cae al del sistema cuando se pidió el del talonario y el recibo no
     * lo tiene. Es la única salida sensata: el renglón no puede quedar en
     * blanco en un documento que se entrega, y el número del sistema
     * siempre existe.
     */
    public function numberOf(Receipt $receipt): string
    {
        return match ($this) {
            self::System => $receipt->formatted_number,
            self::Talonario => $receipt->talonario_number ?? $receipt->formatted_number,
        };
    }
}
