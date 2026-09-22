<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un campo de un evento de auditoría, con su antes y su después ya en
 * palabras. `before` es nulo cuando no hubo un antes —un alta, un motivo—.
 */
#[TypeScript]
final class AuditChangeData extends Data
{
    public function __construct(
        public string $field,
        public ?string $before,
        public ?string $after,
    ) {}
}
