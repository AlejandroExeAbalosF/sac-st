<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Models\User;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Data\AttachmentHistoryData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;

/**
 * Los archivos de una tanda de sujetos, listos para la pantalla.
 */
final class Attachments
{
    /** Lo que `AttachmentData` lee de cada adjunto. */
    private const RELATIONS = ['uploader:id,name', 'voider:id,name'];

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
            // Lo anulado no es vigente: al anular el último, vuelve el anterior.
            ->whereNull('voided_at')
            ->groupBy('subject_id');

        $porSujeto = [];

        foreach (Attachment::query()->with(self::RELATIONS)->whereIn('id', $ultimos)->get() as $adjunto) {
            $porSujeto[$adjunto->subject_id] = AttachmentData::fromModel($adjunto, $viewer);
        }

        return $porSujeto;
    }

    /**
     * Todos los archivos de cada sujeto, del más viejo al más nuevo.
     *
     * Para las fichas que muestran el legajo entero —una multa, sus pagos
     * anteriores, sus documentos—: no hay uno vigente, están todos. Los
     * anulados vienen marcados (`voided`): la pantalla los aparta.
     *
     * @param  array<int>  $subjectIds
     * @return array<int, list<AttachmentData>>
     */
    public function allFor(AttachmentSubject $subject, array $subjectIds, ?User $viewer): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $porSujeto = [];

        $adjuntos = Attachment::query()
            ->with(self::RELATIONS)
            ->where('subject_type', $subject->value)
            ->whereIn('subject_id', $subjectIds)
            ->orderBy('id')
            ->get();

        foreach ($adjuntos as $adjunto) {
            $porSujeto[$adjunto->subject_id][] = AttachmentData::fromModel($adjunto, $viewer);
        }

        return $porSujeto;
    }

    /**
     * Archivos puntuales, por su propio id.
     *
     * Para cuando el dominio ya eligió cuál vale —el comprobante de un cobro
     * guarda el id de su foto— y no hay que deducirlo del sujeto.
     *
     * @param  array<int>  $ids
     * @return array<int, AttachmentData> por id del adjunto
     */
    public function forIds(array $ids, ?User $viewer): array
    {
        if ($ids === []) {
            return [];
        }

        $porId = [];

        foreach (Attachment::query()->with(self::RELATIONS)->whereIn('id', $ids)->get() as $adjunto) {
            $porId[$adjunto->id] = AttachmentData::fromModel($adjunto, $viewer);
        }

        return $porId;
    }

    /**
     * Cuántos archivos tiene cada sujeto y cuántos están anulados.
     *
     * Lo necesitan las pantallas que muestran un solo archivo —el ticket de
     * una cuota, la foto de un papel—: si se anula el único, la miniatura
     * desaparece, y sin este dato no quedaría desde dónde llegar a él.
     *
     * @param  array<int>  $subjectIds
     * @return array<int, AttachmentHistoryData> por id del sujeto; solo los que tienen alguno
     */
    public function historyFor(AttachmentSubject $subject, array $subjectIds, ?string $documentType = null): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $filas = Attachment::query()
            ->selectRaw('subject_id, count(*) AS total, count(voided_at) AS anulados')
            ->where('subject_type', $subject->value)
            ->whereIn('subject_id', $subjectIds)
            ->when($documentType !== null, fn ($query) => $query->where('document_type', $documentType))
            ->groupBy('subject_id')
            ->toBase()
            ->get();

        $porSujeto = [];

        foreach ($filas as $fila) {
            $porSujeto[(int) $fila->subject_id] = new AttachmentHistoryData(
                subjectType: $subject,
                subjectId: (int) $fila->subject_id,
                documentType: $documentType,
                total: (int) $fila->total,
                voided: (int) $fila->anulados,
            );
        }

        return $porSujeto;
    }

    /**
     * Todos los archivos de un sujeto, anulados incluidos, del más nuevo al
     * más viejo: lo que muestra el historial.
     *
     * @return list<AttachmentData>
     */
    public function historyOf(AttachmentSubject $subject, int $subjectId, ?string $documentType, ?User $viewer): array
    {
        return array_values(Attachment::query()
            ->with(self::RELATIONS)
            ->where('subject_type', $subject->value)
            ->where('subject_id', $subjectId)
            ->when($documentType !== null, fn ($query) => $query->where('document_type', $documentType))
            ->orderByDesc('id')
            ->get()
            ->map(fn (Attachment $adjunto): AttachmentData => AttachmentData::fromModel($adjunto, $viewer))
            ->all());
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
