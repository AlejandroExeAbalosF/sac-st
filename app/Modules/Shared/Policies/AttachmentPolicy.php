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

    /**
     * Corregir la descripción: hay que poder abrirlo y tener el permiso.
     *
     * Solo responde por el operador. Si el archivo admite el cambio lo dice
     * el propio adjunto, porque `Gate::before` le concede todo al
     * administrador antes de llegar acá: la policy no puede ser quien
     * cuide el estado del archivo.
     */
    public function rename(User $user, Attachment $attachment): bool
    {
        return $user->can('adjuntos.renombrar') && $this->view($user, $attachment);
    }

    /** Anular: hay que poder abrirlo y tener el permiso. El estado, aparte. */
    public function void(User $user, Attachment $attachment): bool
    {
        return $user->can('adjuntos.anular') && $this->view($user, $attachment);
    }
}
