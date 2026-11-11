<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit\Subjects;

use App\Models\User;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Modules\Shared\Models\Attachment;

/**
 * Un archivo adjunto en el historial: su descripción y su nombre original.
 *
 * Los eventos sobre el adjunto —renombrarlo, anularlo— van sobre él y no
 * sobre lo que documenta: es el archivo el que cambió.
 */
final class AttachmentAuditSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'Attachment';
    }

    public function label(): string
    {
        return 'Adjunto';
    }

    public function fields(): array
    {
        return ['title' => 'Descripción'];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $result = [];

        foreach (Attachment::query()->whereIn('id', $ids)->get(['id', 'title', 'original_filename']) as $adjunto) {
            $result[$adjunto->id] = new AuditSubjectDescription(
                $adjunto->title.' ('.$adjunto->original_filename.')',
                null,
            );
        }

        return $result;
    }
}
