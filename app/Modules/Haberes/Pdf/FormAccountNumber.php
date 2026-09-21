<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

/**
 * El número de cuenta como lo escribe el formulario del área.
 *
 * La Orden 3582 —la que el área completa a mano— anota **la cola** del
 * número, no el número entero: `0123456789` donde el sistema guarda
 * `310000123456789`, y `23456789` adentro del cuadro de depósitos, que es
 * la columna más angosta. Los primeros dígitos son la entidad y la
 * sucursal, iguales en todas las cuentas del organismo, así que no
 * distinguen nada en un papel donde todas las filas son del mismo banco.
 *
 * **Es una decisión de presentación y vive acá, no en el modelo.**
 * `bank_accounts.account_number` sigue guardando el número completo, que
 * es con el que se opera y se concilia; esto solo elige cuánto de él entra
 * en cada casilla.
 *
 * El recorte además es lo que hace que entre: la columna `CTA. CTE.` mide
 * 13 mm útiles y quince dígitos a 7,5 pt necesitan veinticuatro. No se
 * resolvió achicando la tipografía porque para que entraran habría que
 * bajar a cuatro puntos, y porque el problema no era el tamaño de la letra
 * sino estar escribiendo otra cosa que la que el formulario pide.
 */
final class FormAccountNumber
{
    /** Lo que el papel escribe en «CTA N°», arriba. */
    public const EN_LA_FICHA = 10;

    /** Y en «CTA. CTE.», adentro del cuadro de depósitos. */
    public const EN_LOS_DEPOSITOS = 8;

    /**
     * Los últimos `$digitos` del número, o el número entero si es más corto.
     *
     * Se cuenta sobre los dígitos y se devuelve el tramo tal cual está
     * escrito: si alguna vez un número trae separadores, recortar sobre la
     * cadena cruda podría cortar por un guion y dejar algo que no se
     * parece a un número de cuenta.
     */
    public static function acortar(?string $numero, int $digitos): ?string
    {
        if ($numero === null) {
            return null;
        }

        $soloDigitos = preg_replace('/\D/', '', $numero) ?? '';

        if (mb_strlen($soloDigitos) <= $digitos) {
            return $numero;
        }

        return mb_substr($soloDigitos, -$digitos);
    }
}
