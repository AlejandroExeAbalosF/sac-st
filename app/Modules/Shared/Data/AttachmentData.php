<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Models\User;
use App\Modules\Shared\Enums\AttachmentKind;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Support\Reason;
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
        /** Si quien mira puede corregir la descripción, y el archivo lo admite. */
        public bool $canRename,
        /** Si quien mira puede anularlo, y el archivo lo admite. */
        public bool $canVoid,
        /** Quién lo anuló, cuándo y por qué; nulo si está vigente. */
        public ?AttachmentVoidData $voided,
        public ?string $createdByName,
        public string $createdAt,
    ) {}

    /**
     * El autor sale de `uploader` y quien anuló, de `voider`: conviene
     * traerlos cargados.
     *
     * Las acciones exigen dos cosas: que el archivo las admita —el estado,
     * del propio adjunto— y que quien mira tenga el permiso —la policy—.
     * La policy sola no alcanza: `Gate::before` le concede todo al
     * administrador antes de llegar a ella.
     */
    public static function fromModel(Attachment $adjunto, ?User $viewer): self
    {
        return new self(
            id: $adjunto->id,
            title: $adjunto->title,
            originalFilename: $adjunto->original_filename,
            kind: $adjunto->kind(),
            sizeBytes: $adjunto->size_bytes,
            canOpen: $viewer !== null && Gate::forUser($viewer)->allows('view', $adjunto),
            canRename: $viewer !== null && $adjunto->canBeRenamed() && Gate::forUser($viewer)->allows('rename', $adjunto),
            canVoid: $viewer !== null && $adjunto->canBeVoided() && Gate::forUser($viewer)->allows('void', $adjunto),
            voided: $adjunto->voided_at === null || $adjunto->void_reason_code === null ? null : new AttachmentVoidData(
                at: $adjunto->voided_at->toIso8601String(),
                byName: $adjunto->voider?->name,
                reason: Reason::stored($adjunto->void_reason_code, $adjunto->void_reason),
            ),
            createdByName: $adjunto->uploader?->name,
            createdAt: $adjunto->created_at->toIso8601String(),
        );
    }
}
