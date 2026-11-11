<?php

declare(strict_types=1);

namespace App\Modules\Shared\Policies;

use App\Models\User;
use App\Modules\Shared\Enums\Confidentiality;
use App\Modules\Shared\Models\Attachment;

/**
 * Quién puede abrir un archivo.
 *
 * Es la única respuesta a esa pregunta, y la usan los dos lados: el
 * controlador que sirve el archivo y el DTO que le dice a la pantalla si
 * puede pedirlo. Con la regla repetida, la tarjeta podía pedir una
 * miniatura que el servidor después rechazaba con un 403.
 */
final class AttachmentPolicy
{
    public function view(User $user, Attachment $attachment): bool
    {
        if (! $user->can('adjuntos.ver')) {
            return false;
        }

        return $attachment->confidentiality !== Confidentiality::Restricted
            || $user->can('adjuntos.ver-reservados');
    }
}
