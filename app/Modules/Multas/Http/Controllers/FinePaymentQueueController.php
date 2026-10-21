<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Data\FinePendingPaymentData;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pagos por verificar: lo informado que espera su crédito del extracto.
 *
 * Es la cola de quien concilia. Cada fila lleva a la ficha de la multa, que
 * es donde se verifica, se corrige o se descarta.
 */
final class FinePaymentQueueController extends Controller
{
    public function index(): Response
    {
        $pendientes = FinePayment::query()
            ->where('status', FinePaymentStatus::Reported)
            ->with(['fine.fineCase', 'creator:id,name'])
            // Lo más viejo primero: es lo que lleva más tiempo esperando.
            ->orderBy('reported_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('multas/pagos', [
            'informados' => $pendientes
                ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago))
                ->values()
                ->all(),
        ]);
    }
}
