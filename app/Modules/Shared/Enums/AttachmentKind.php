<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * Cómo se muestra un archivo: lo que la pantalla necesita para elegir entre
 * una foto, una hoja de PDF o un ícono con el botón de descarga.
 *
 * Sale del tipo MIME que se guardó, que se lee del contenido y no del
 * nombre. No dice si quien mira lo puede abrir: eso es un permiso, y lo
 * responde `AttachmentPolicy`.
 */
enum AttachmentKind: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    /** Una planilla, un CSV: se descarga, no se mira en pantalla. */
    case Other = 'other';
}
