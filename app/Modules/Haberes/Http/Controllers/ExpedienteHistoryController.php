<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Support\AuditTimeline;
use Illuminate\Http\JsonResponse;

/**
 * Qué le pasó a este expediente, quién y cuándo.
 *
 * El listado ya dice **cuándo** se tocó por última vez; acá está el resto
 * de la respuesta, que es qué se cambió y por qué. Las dos salen de
 * `audit_events`, así que no pueden contradecirse.
 *
 * Es la pregunta que aparece en el momento —«esto decía otra cosa, ¿quién
 * lo corrigió?»—; la mirada transversal está en la auditoría de
 * operaciones. Cómo se nombra cada campo lo dice el catálogo de
 * auditoría, el mismo para las dos.
 */
final class ExpedienteHistoryController extends Controller
{
    public function __construct(private readonly AuditTimeline $linea) {}

    public function __invoke(Expediente $expediente): JsonResponse
    {
        return response()->json([
            'events' => $this->linea->shape($this->linea->eventsFor('Expediente', $expediente->id)),
        ]);
    }
}
