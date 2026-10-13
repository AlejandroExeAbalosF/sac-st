<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Permissions\PermissionDefinition;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un permiso tal como lo lee el área: qué hace, con su nombre técnico al
 * lado para poder cotejarlo contra la ruta que lo exige.
 */
#[TypeScript]
final class PermissionData extends Data
{
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public bool $sensitive,
        public bool $delegable,
    ) {}

    public static function fromDefinition(PermissionDefinition $definition): self
    {
        return new self(
            name: $definition->name,
            label: $definition->label,
            description: $definition->description,
            sensitive: $definition->sensitive,
            delegable: $definition->delegable,
        );
    }
}
