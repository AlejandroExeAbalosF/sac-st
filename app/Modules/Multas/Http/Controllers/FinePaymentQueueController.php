<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Data\FinePendingPaymentData;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagos por verificar: lo informado que espera su crédito del extracto, y los
 * recursos cuya diferencia espera al administrador.
 *
 * Es la cola de quien concilia. Cada fila lleva a la ficha de la multa, que
 * es donde se verifica, se descarta o se resuelve.
 */
final class FinePaymentQueueController extends Controller
{
    public function index(Request $request): Response
    {
        $pendientes = FinePayment::query()
            ->whereIn('status', [FinePaymentStatus::Reported, FinePaymentStatus::UnderReview])
            ->with(['fine.fineCase', 'creator:id,name'])
            // Lo más viejo primero: es lo que lleva más tiempo esperando.
            ->orderBy('reported_at')
            ->orderBy('id')
            ->get();

        $porEstado = fn (FinePaymentStatus $estado): array => $pendientes
            ->filter(fn (FinePayment $pago): bool => $pago->status === $estado)
            ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago))
            ->values()
            ->all();

        return Inertia::render('multas/pagos', [
            'informados' => $porEstado(FinePaymentStatus::Reported),
            'enRevision' => $porEstado(FinePaymentStatus::UnderReview),
            'canResolve' => $request->user()?->can('multas.resolver-recurso') ?? false,
        ]);
    }
}
