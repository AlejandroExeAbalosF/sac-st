<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\References;

use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Shared\Audit\AuditReferenceResolver;

/**
 * La etiqueta de gestión de una cuota, por su descripción y no por su id.
 */
final class ManagementLabelReference implements AuditReferenceResolver
{
    public function fields(): array
    {
        return ['management_label_id'];
    }

    public function labels(array $ids): array
    {
        /** @var array<int, string> $etiquetas */
        $etiquetas = HaberManagementLabel::query()->whereIn('id', $ids)->pluck('description', 'id')->all();

        return $etiquetas;
    }
}
