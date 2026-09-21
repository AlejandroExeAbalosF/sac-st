<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Support\AuditTimeline;
use App\Modules\Shared\Models\Person;
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
    /**
     * @var array<string, string>
     */
    private const CAMPOS = [
        'beneficiary_id' => 'Beneficiario',
        'assigned_amount' => 'Importe reconocido',
        'expected_installment_count' => 'Cuotas previstas',
        'workflow_status' => 'Estado',
        'block_reason' => 'Motivo del bloqueo',
        'concept' => 'Concepto',
        'notes' => 'Observaciones',
    ];

    /** @var array<string, string> */
    private const ESTADOS = [
        'active' => 'Activo',
        'suspended' => 'Suspendido',
        'blocked' => 'Bloqueado',
        'cancelled' => 'Anulado',
        'closed' => 'Cerrado',
    ];

    public function __construct(private readonly AuditTimeline $linea) {}

    /**
     * El expediente está en la dirección para resolver al haber —su
     * ordinal solo es único adentro de él— y no se usa más allá de eso.
     */
    public function __invoke(Expediente $expediente, Haber $haber): JsonResponse
    {
        $eventos = $this->linea->eventsFor('Haber', $haber->id);

        return response()->json([
            'events' => $this->linea->shape($eventos, self::CAMPOS, [
                'workflow_status' => self::ESTADOS,
                'beneficiary_id' => $this->nombresDe(
                    $this->linea->idsMencionados($eventos, 'beneficiary_id'),
                ),
            ]),
        ]);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function nombresDe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $nombres */
        $nombres = Person::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $nombres;
    }
}
