<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Support\AuditTimeline;
use Illuminate\Http\JsonResponse;

/**
 * Qué le pasó a este haber, quién y cuándo.
 *
 * Suele tener poco que contar, y eso **es** la respuesta: un haber se
 * reconoce, se anula y se reactiva, pero no se corrige —lo que se corrige
 * son sus cuotas, que tienen su propio historial—. Que la lista sea corta
 * dice que nadie tocó el derecho reconocido, que es exactamente lo que
 * alguien viene a comprobar.
 */
final class HaberHistoryController extends Controller
{
    public function __construct(private readonly AuditTimeline $linea) {}

    /**
     * El expediente está en la dirección para resolver al haber —su
     * ordinal solo es único adentro de él— y no se usa más allá de eso.
     */
    public function __invoke(Expediente $expediente, Haber $haber): JsonResponse
    {
        return response()->json([
            'events' => $this->linea->shape($this->linea->eventsFor('Haber', $haber->id)),
        ]);
    }
}
