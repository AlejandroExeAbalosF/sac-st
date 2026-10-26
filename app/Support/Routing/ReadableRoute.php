<?php

declare(strict_types=1);

namespace App\Support\Routing;

/**
 * El parámetro de un registro con `ReadableRouteKey`: el id y, opcional,
 * una barra con el número legible.
 *
 * El número empieza con una cifra: así `nuevo`, `editar`, `haber` o
 * `agregar-multa` nunca se toman por número.
 */
final class ReadableRoute
{
    /** El número legible solo, sin el id. */
    public const NUMBER = '[0-9][0-9A-Za-z-]*';

    public const PATTERN = '[0-9]+(?:/'.self::NUMBER.')?';
}
