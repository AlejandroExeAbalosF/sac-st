<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * De dónde salió el archivo.
 *
 * Importa para saber qué se puede esperar de él: uno generado por el
 * sistema es reproducible y tiene `template_version`; uno escaneado o
 * subido es la única copia de algo que existe en papel.
 */
enum AttachmentSource: string
{
    /** Lo produjo el sistema: un recibo en PDF, una Orden de Pago. */
    case Generated = 'generated';

    /** Lo subió una persona desde su computadora. */
    case Uploaded = 'uploaded';

    /** Viene de un escáner o de la cámara del teléfono. */
    case Scanned = 'scanned';

    public function label(): string
    {
        return match ($this) {
            self::Generated => 'Generado por el sistema',
            self::Uploaded => 'Subido',
            self::Scanned => 'Escaneado',
        };
    }
}
