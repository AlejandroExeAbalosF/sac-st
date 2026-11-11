<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Models\User;
use App\Modules\Shared\Enums\AttachmentKind;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un archivo guardado, con lo que la pantalla necesita para mostrarlo.
 *
 * Es el mismo para todos los módulos: el visor y las tarjetas son uno
 * solo, y antes cada pantalla recibía un id suelto y adivinaba el resto
 * —el comprobante de depósito se dibujaba siempre como foto, aunque fuera
 * un PDF—.
 */
#[TypeScript]
final class AttachmentData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public string $originalFilename,
        public AttachmentKind $kind,
        public int $sizeBytes,
        /**
         * Si quien mira puede pedir el contenido.
         *
         * No es lo mismo que `kind`: un PDF se puede mostrar, pero un
         * operador sin `adjuntos.ver` —o sin `ver-reservados` para un
         * archivo reservado— no lo puede abrir. Sin este dato la tarjeta
         * pedía la miniatura y se encontraba con un 403.
         */
        public bool $canOpen,
        /** Si quien mira puede corregir la descripción. */
        public bool $canRename,
        public ?string $createdByName,
        public string $createdAt,
    ) {}

    /** El autor sale de `uploader`: conviene traerlo cargado. */
    public static function fromModel(Attachment $adjunto, ?User $viewer): self
    {
        return new self(
            id: $adjunto->id,
            title: $adjunto->title,
            originalFilename: $adjunto->original_filename,
            kind: $adjunto->kind(),
            sizeBytes: $adjunto->size_bytes,
            canOpen: $viewer !== null && Gate::forUser($viewer)->allows('view', $adjunto),
            canRename: $viewer !== null && Gate::forUser($viewer)->allows('rename', $adjunto),
            createdByName: $adjunto->uploader?->name,
            createdAt: $adjunto->created_at->toIso8601String(),
        );
    }
}
