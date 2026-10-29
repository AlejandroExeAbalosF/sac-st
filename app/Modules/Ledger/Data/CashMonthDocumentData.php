<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un papel emitido sobre el cierre mensual, en una de sus versiones. */
#[TypeScript]
final class CashMonthDocumentData extends Data
{
    public function __construct(
        public string $label,
        /** Lo que lo resume en una línea: el total, cuántos renglones. */
        public string $detail,
        /** @var numeric-string */
        public string $total,
        public int $version,
        /** De la versión vigente del cierre; las anteriores quedan archivadas. */
        public bool $current,
        public string $emittedAt,
        public ?string $emittedBy,
        /** Se mira en el visor de documentos: la ruta está en `same_origin_frame_routes`. */
        public string $viewUrl,
        public string $downloadUrl,
    ) {}
}
