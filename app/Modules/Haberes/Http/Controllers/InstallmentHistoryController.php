<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Support\AuditTimeline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Qué le pasó a esta cuota, quién y cuándo.
 *
 * El rastro se venía guardando desde el principio —cada corrección deja su
 * `cuota.corregida` con el antes y el después— pero no había dónde
 * leerlo. Un registro que nadie puede consultar no es auditoría: es un
 * archivo que crece.
 *
 * Va acotado a una cuota porque es la pregunta que aparece en el momento:
 * «esta cuota decía otra cosa la semana pasada, ¿quién la cambió?». La
 * mirada transversal está en la auditoría de operaciones; cómo se nombra
 * cada campo lo dice el catálogo de auditoría, el mismo para las dos.
 *
 * **Incluye lo que le pasó a su efectivo camino al banco.** El traslado se
 * audita con el traslado como sujeto, no con la cuota, así que quedaba
 * afuera: un depósito cargado y después cancelado desaparecía de la
 * pantalla —la tarjeta deja de mostrar los cancelados— y no había dónde
 * ver que había existido. El rastro estaba en la base y no llegaba a
 * nadie, que es el mismo problema que este historial vino a resolver.
 */
final class InstallmentHistoryController extends Controller
{
    public function __construct(private readonly AuditTimeline $linea) {}

    public function __invoke(BeneficiaryInstallment $installment): JsonResponse
    {
        $traslados = $this->trasladosDe($installment);

        $eventos = AuditEvent::query()
            ->with('user:id,name')
            ->where(function (Builder $consulta) use ($installment, $traslados): void {
                $consulta->where(fn (Builder $q) => $q
                    ->where('subject_type', 'BeneficiaryInstallment')
                    ->where('subject_id', $installment->id));

                if ($traslados !== []) {
                    $consulta->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', 'CashToBankTransfer')
                        ->whereIn('subject_id', $traslados));
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json([
            'events' => $this->linea->shape($eventos),
        ]);
    }

    /**
     * Los traslados de esta cuota, cancelados incluidos.
     *
     * Se llega por la imputación: el traslado no apunta a la cuota, apunta
     * a lo que se le había asignado. Y se miran todas las imputaciones, no
     * solo las vigentes, porque el historial tiene que poder contar
     * justamente lo que se deshizo.
     *
     * @return list<int>
     */
    private function trasladosDe(BeneficiaryInstallment $installment): array
    {
        /** @var list<int> $ids */
        $ids = CashToBankTransferItem::query()
            ->whereIn(
                'funding_allocation_id',
                FundingAllocation::query()
                    ->where('beneficiary_installment_id', $installment->id)
                    ->select('id'),
            )
            ->distinct()
            ->pluck('cash_to_bank_transfer_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
