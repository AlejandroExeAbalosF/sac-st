<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Una multa a la que puede pasar un pago verificado al corregirlo. */
#[TypeScript]
final class FineCorrectionTargetData extends Data
{
    public function __construct(
        public int $id,
        public string $caption,
        public string $caseLabel,
        /** @var numeric-string */
        public string $debt,
    ) {}
}
