<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\SourceFormat;

/**
 * Lee un extracto de un formato concreto.
 *
 * Hay uno por formato de descarga porque MacroOnline exporta dos cosas
 * distintas con los mismos datos adentro, y porque el banco va a cambiar
 * el formato alguna vez: cuando pase, se agrega una implementación y las
 * importaciones viejas siguen sabiendo con cuál se leyeron gracias a
 * `bank_statement_imports.parser_version`.
 */
interface StatementParser
{
    public function format(): SourceFormat;

    /** Versión de esta lógica; queda guardada en la importación. */
    public function version(): string;

    public function parse(string $path): ParsedStatement;
}
