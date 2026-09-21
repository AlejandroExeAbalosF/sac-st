<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Cómo una fila del extracto quedó vinculada a un movimiento canónico.
 *
 * Importa saberlo porque no todas las vinculaciones valen igual: la que
 * hizo la importación al crear el movimiento es un hecho; la que resolvió
 * una persona mirando dos filas parecidas es un juicio, y quien lo emitió
 * queda en `linked_by`.
 */
enum MatchMethod: string
{
    /** La fila trajo el movimiento: nació con ella. */
    case Imported = 'imported';

    /** Huella idéntica a un movimiento ya existente. */
    case Fingerprint = 'fingerprint';

    case OperationId = 'operation_id';

    case Exact = 'exact';

    /** Lo decidió una persona. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Imported => 'Importada',
            self::Fingerprint => 'Por huella',
            self::OperationId => 'Por referencia',
            self::Exact => 'Coincidencia exacta',
            self::Manual => 'Manual',
        };
    }
}
