<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Models\User;
use App\Modules\Multas\Data\FineAttachmentData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;

/**
 * Los adjuntos de varios sujetos de un mismo tipo, en una sola consulta.
 *
 * Una ficha muestra los de la multa y los de cada pago anterior: pedirlos
 * de a uno serían tantas consultas como antecedentes.
 */
final class FineAttachments
{
    /**
     * @param  list<int>  $ids
     * @return array<int, list<FineAttachmentData>> por id del sujeto, del más viejo al más nuevo
     */
    public function for(AttachmentSubject $sujeto, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $adjuntos = Attachment::query()
            ->where('subject_type', $sujeto)
            ->whereIn('subject_id', $ids)
            ->orderBy('id')
            ->get();

        /** @var array<int, string> $autores */
        $autores = User::query()
            ->whereIn('id', $adjuntos->pluck('created_by')->filter()->unique()->all())
            ->get()
            ->mapWithKeys(fn (User $usuario): array => [$usuario->id => $usuario->name])
            ->all();

        $porSujeto = [];

        foreach ($adjuntos as $adjunto) {
            $porSujeto[$adjunto->subject_id][] = FineAttachmentData::fromModel(
                $adjunto,
                $adjunto->created_by === null ? null : ($autores[$adjunto->created_by] ?? null),
            );
        }

        return $porSujeto;
    }
}
