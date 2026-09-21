<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

/**
 * El CUIT: su dígito verificador y a quién pertenece.
 *
 * Vive acá y no dentro de un `FormRequest` porque hay dos vías que llegan
 * al mismo dato —el alta contextual desde el buscador y la corrección
 * desde el maestro— y una regla duplicada es una regla que en algún
 * momento diverge.
 */
final class Cuit
{
    /** @var list<int> */
    private const PESOS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    /**
     * Los dos primeros dígitos dicen de qué es el número.
     *
     * Con el verificador solo no alcanza: `20-29939415-9` es aritmética
     * impecable y es el CUIL de una persona, no el CUIT de un organismo.
     * Sin esta distinción, una organización podía quedar registrada en el
     * maestro con el número de un humano.
     *
     * @var list<string>
     */
    private const PREFIJOS_ORGANIZACION = ['30', '33', '34'];

    /** @var list<string> */
    private const PREFIJOS_PERSONA = ['20', '23', '24', '27'];

    public static function isValid(string $cuit): bool
    {
        if (strlen($cuit) !== 11 || ! ctype_digit($cuit)) {
            return false;
        }

        $suma = 0;

        foreach (self::PESOS as $indice => $peso) {
            $suma += ((int) $cuit[$indice]) * $peso;
        }

        $digito = 11 - ($suma % 11);
        $digito = match ($digito) {
            11 => 0,
            10 => 9,
            default => $digito,
        };

        return $digito === (int) $cuit[10];
    }

    /** El CUIT de una empresa u organismo. */
    public static function isOrganization(string $cuit): bool
    {
        return self::isValid($cuit) && in_array(substr($cuit, 0, 2), self::PREFIJOS_ORGANIZACION, true);
    }

    /** El CUIL —o el CUIT de un monotributista— de una persona física. */
    public static function isIndividual(string $cuit): bool
    {
        return self::isValid($cuit) && in_array(substr($cuit, 0, 2), self::PREFIJOS_PERSONA, true);
    }

    /**
     * El DNI que hay adentro de un CUIL.
     *
     * El expediente muchas veces trae el CUIL y no el DNI, y hasta ahora el
     * operador tenía que sacarle el prefijo y el verificador a mano. Es una
     * cuenta mental por cada carga, y una que el sistema no puede auditar:
     * ocho dígitos mal copiados siguen siendo un DNI válido.
     *
     * Se guarda el DNI y no el CUIL porque el CUIL no agrega identidad, la
     * duplica: es el mismo documento con dos dígitos adelante y uno atrás.
     * Guardar los dos permitiría dos fichas del mismo humano, y el índice
     * único de `document` no las vería.
     */
    public static function toDocumentNumber(string $cuil): ?string
    {
        if (! self::isIndividual($cuil)) {
            return null;
        }

        // Los ceros a la izquierda de un DNI corto no se conservan: el
        // maestro guarda «1234567», no «01234567».
        return ltrim(substr($cuil, 2, 8), '0');
    }
}
