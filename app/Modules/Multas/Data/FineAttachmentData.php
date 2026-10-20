<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Shared\Models\Attachment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un archivo de Multas, con lo que la pantalla necesita para mostrarlo.
 */
#[TypeScript]
final class FineAttachmentData extends Data
{
    public function __construct(
        public int $id,
        public string $title,
        public string $originalFilename,
        /** Si el navegador lo puede mostrar —foto o PDF— o solo descargar. */
        public bool $viewable,
        public ?string $createdByName,
        public string $createdAt,
    ) {}

    public static function fromModel(Attachment $adjunto, ?string $autor): self
    {
        return new self(
            id: $adjunto->id,
            title: $adjunto->title,
            originalFilename: $adjunto->original_filename,
            viewable: $adjunto->isViewableInline(),
            createdByName: $autor,
            createdAt: $adjunto->created_at->toIso8601String(),
        );
    }
}
