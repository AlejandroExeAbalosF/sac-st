<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Models\User;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Corrige la descripción de un adjunto. El archivo no se toca.
 *
 * La fila se relee bloqueada dentro de la transacción: si dos personas
 * corrigen el mismo adjunto a la vez, cada una audita el título que de
 * verdad reemplazó, y no el que tenía en pantalla.
 */
final class RenameAttachment
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Attachment $attachment, string $title, User $actor): Attachment
    {
        $nuevo = trim($title);

        if ($nuevo === '') {
            throw ValidationException::withMessages(['title' => 'Escribí la descripción del archivo.']);
        }

        return DB::transaction(function () use ($attachment, $nuevo, $actor): Attachment {
            $adjunto = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);

            if (! $adjunto->canBeRenamed()) {
                throw ValidationException::withMessages(['title' => 'Un archivo anulado no se renombra.']);
            }

            if (! Gate::forUser($actor)->allows('rename', $adjunto)) {
                throw ValidationException::withMessages(['title' => 'No podés corregir la descripción de este archivo.']);
            }

            $anterior = $adjunto->title;

            if ($anterior === $nuevo) {
                return $adjunto;
            }

            $adjunto->title = $nuevo;
            $adjunto->save();

            $this->auditar->handle(
                action: 'adjunto.renombrado',
                subject: $adjunto,
                before: ['title' => $anterior],
                after: ['title' => $nuevo],
                actorId: $actor->id,
            );

            return $adjunto;
        });
    }
}
