<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

/**
 * El renglón OBS de la Orden, redactado desde la foja del CBU.
 *
 * **No es un campo que alguien escriba: es el mismo dato dicho dos
 * veces.** El área lo confirmó sin margen de duda:
 *
 * > *«En la orden de pago se registra únicamente el número de foja. No se
 * > realizan otras observaciones.»* (Dudas_Consultas D-003)
 *
 * Hasta acá el formulario pedía dos cosas —un OBS de texto libre y una
 * foja— que en la Orden 3582 real son la misma: el pie dice *«Se observa
 * en expte. CBU del trabajador a fs. n.º 19»* y la nota que la acompaña
 * pide transferir *«a la CBU informada en fs. 19»*. Un solo 19, dos
 * renglones. Que el operador lo escribiera dos veces era la forma segura
 * de que alguna vez no coincidieran.
 *
 * **Se guarda compuesto, no se compone al imprimir.** `payment_orders` es
 * append-only y el papel ya circuló: si esta plantilla cambiara alguna vez,
 * una Orden vieja tiene que reimprimirse con el texto que se firmó, no con
 * el de hoy. La columna `notes` sigue siendo la fotografía; lo único que
 * cambió es quién la escribe.
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
     * Y es correcto que la Orden salga con el renglón en blanco: el área
     * pidió expresamente que la foja **no** frene la emisión, porque lo que
     * falta se agrega después. Inventar un texto genérico para llenar el
     * hueco sería escribir en un documento algo que nadie afirmó.
     */
    public static function forFolio(?string $folio): ?string
    {
        $limpia = trim($folio ?? '');

        return $limpia === '' ? null : sprintf(self::PLANTILLA, $limpia);
    }
}
