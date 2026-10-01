<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

/**
 * El renglón OBS de la Orden, redactado desde la foja del CBU.
 *
 * **No es un campo que alguien escriba: es el mismo dato dicho dos
 * veces.** La Orden lleva únicamente el número de foja (D-003).
 *
 * Una sola foja redacta los dos renglones. En la Orden 3582 real, el pie
 * dice *«Se observa en expte. CBU del trabajador a fs. n.º 19»* y la nota
 * que la acompaña pide transferir *«a la CBU informada en fs. 19»*. Pedir
 * el número dos veces sería la forma segura de que alguna vez no
 * coincidan.
 *
 * **Se guarda compuesto, no se compone al imprimir.** `payment_orders` es
 * append-only y el papel ya circuló: si esta plantilla cambiara alguna vez,
 * una Orden vieja tiene que reimprimirse con el texto que se firmó, no con
 * el de hoy. La columna `notes` es la fotografía, y la escribe esta clase.
 */
final class PaymentOrderObservation
{
    /**
     * Cómo lo redacta el formulario del área, palabra por palabra.
     *
     * El `%s` es la foja tal como se cargó —«19», «19 vta.», «19/21»—, que
     * por eso viaja como texto y no como entero.
     */
    private const PLANTILLA = 'Se observa en expte. CBU del trabajador a fs. n.º %s';

    /**
     * Sin foja no hay observación.
     *
     * La emisión exige la foja, pero la vista previa y las Órdenes emitidas
     * mientras fue opcional pueden no tenerla. Ahí el renglón queda en
     * blanco: inventar un texto genérico sería escribir en un documento
     * algo que nadie afirmó.
     */
    public static function forFolio(?string $folio): ?string
    {
        $limpia = trim($folio ?? '');

        return $limpia === '' ? null : sprintf(self::PLANTILLA, $limpia);
    }
}
