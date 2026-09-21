<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * Quién puede abrir el archivo.
 *
 * `Internal` es el caso normal: lo ve cualquiera que tenga acceso al
 * expediente. `Restricted` es para lo que no debería circular —una nota
 * médica, un dato sensible del trabajador— y exige el permiso
 * `adjuntos.ver-reservados`.
 *
 * Son dos y no cinco a propósito. Una escala de niveles obliga a decidir
 * el nivel de cada archivo, y quien carga a las apuradas elige el primero
 * de la lista.
 */
enum Confidentiality: string
{
    case Internal = 'internal';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Interno',
            self::Restricted => 'Reservado',
        };
    }
}
