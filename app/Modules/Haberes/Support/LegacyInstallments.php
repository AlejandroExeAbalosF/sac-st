<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Data\InstallmentLegacyData;
use App\Modules\Haberes\Data\LegacyDocumentData;
use App\Modules\Haberes\Data\LegacySettlementData;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;

/**
 * Lo del sistema anterior de cada cuota, en unas pocas consultas.
 *
 * Va en lote por lo mismo que la financiación y las etapas: la ficha del
 * haber muestra todas sus cuotas y un plan puede tener sesenta.
 */
final class LegacyInstallments
{
    public function __construct(private readonly InstallmentMovements $movimientos) {}

    /**
     * @param  list<int>  $installmentIds
     * @return array<int, InstallmentLegacyData>
     */
    public function forMany(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $registros = LegacySettlement::query()
            ->current()
            ->with(['recorder:id,name'])
            ->whereIn('beneficiary_installment_id', $installmentIds)
            ->get()
            ->keyBy('beneficiary_installment_id');

        $documentos = LegacyDocument::query()
            ->current()
            ->whereIn('beneficiary_installment_id', $installmentIds)
            ->orderBy('id')
            ->get();

        $fotos = $this->photos(array_values($documentos->pluck('id')->map(intval(...))->all()));
        $obstaculos = $this->movimientos->obstaclesFor($installmentIds);

        $porCuota = [];

        foreach ($installmentIds as $id) {
            $registro = $registros->get($id);

            $porCuota[$id] = new InstallmentLegacyData(
                settlement: $registro instanceof LegacySettlement ? LegacySettlementData::fromModel($registro) : null,
                documents: array_values($documentos
                    ->where('beneficiary_installment_id', $id)
                    ->map(fn (LegacyDocument $documento): LegacyDocumentData => LegacyDocumentData::fromModel(
                        $documento,
                        $fotos[$documento->id] ?? null,
                    ))
                    ->all()),
                obstacle: $obstaculos[$id] ?? null,
            );
        }

        return $porCuota;
    }

    /**
     * La foto más reciente de cada papel.
     *
     * @param  list<int>  $documentIds
     * @return array<int, int>
     */
    private function photos(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $adjuntos = Attachment::query()
            ->where('subject_type', AttachmentSubject::LegacyDocument->value)
            ->whereIn('subject_id', $documentIds)
            ->orderBy('id')
            ->get(['id', 'subject_id']);

        $porPapel = [];

        foreach ($adjuntos as $adjunto) {
            $porPapel[$adjunto->subject_id] = $adjunto->id;
        }

        return $porPapel;
    }
}
