<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Quién anuló un adjunto, cuándo y por qué. */
#[TypeScript]
final class AttachmentVoidData extends Data
{
    public function __construct(
        public string $at,
        public ?string $byName,
        public string $reason,
    ) {}
}
