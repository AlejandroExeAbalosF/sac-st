<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Enums\AttachmentSubject;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cuántos archivos tiene algo y cuántos están anulados, con lo necesario
 * para pedir el historial completo.
 *
 * Va al lado del archivo vigente en las pantallas que muestran uno solo:
 * con el único anulado, es el único camino para llegar a él.
 */
#[TypeScript]
final class AttachmentHistoryData extends Data
{
    public function __construct(
        public AttachmentSubject $subjectType,
        public int $subjectId,
        public ?string $documentType,
        public int $total,
        public int $voided,
    ) {}
}
