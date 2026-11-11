<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Models\User;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;

/**
 * Los archivos de una tanda de sujetos, listos para la pantalla.
 */
final class Attachments
{
    /**
     * El archivo vigente de cada sujeto, indexado por su id.
     *
     * **El vigente es el más reciente.** Los adjuntos son inmutables:
     * corregir un ticket con una foto mejor no reemplaza la anterior, la
     * deja y suma la nueva. Lo que se muestra es la última.
     *
     * Una sola consulta para toda la tanda: una lista de veinticinco
     * tickets no hace veinticinco viajes a la base.
     *
     * @param  array<int>  $subjectIds
     * @return array<int, AttachmentData>
     */
    public function latestFor(
        AttachmentSubject $subject,
        array $subjectIds,
        ?User $viewer,
        ?string $documentType = null,
    ): array {
        if ($subjectIds === []) {
            return [];
        }

        $ultimos = Attachment::query()
            ->selectRaw('max(id)')
            ->where('subject_type', $subject->value)
            ->whereIn('subject_id', $subjectIds)
            ->when($documentType !== null, fn ($query) => $query->where('document_type', $documentType))
            ->groupBy('subject_id');

        $porSujeto = [];

        foreach (Attachment::query()->with('uploader:id,name')->whereIn('id', $ultimos)->get() as $adjunto) {
            $porSujeto[$adjunto->subject_id] = AttachmentData::fromModel($adjunto, $viewer);
        }

        return $porSujeto;
    }

    /** El vigente de un solo sujeto. */
    public function latestOf(
        AttachmentSubject $subject,
        int $subjectId,
        ?User $viewer,
        ?string $documentType = null,
    ): ?AttachmentData {
        return $this->latestFor($subject, [$subjectId], $viewer, $documentType)[$subjectId] ?? null;
    }
}
