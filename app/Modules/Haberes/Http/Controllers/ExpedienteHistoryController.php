<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\AuditTimeline;
use App\Modules\Shared\Models\Person;
use Illuminate\Http\JsonResponse;

/**
 * Qué le pasó a este expediente, quién y cuándo.
 *
 * El listado ya dice **cuándo** se tocó por última vez; acá está el resto
 * de la respuesta, que es qué se cambió y por qué. Las dos salen de
 * `audit_events`, así que no pueden contradecirse.
 *
 * Va acotado al expediente y no a una pantalla general de auditoría
 * porque es la pregunta que aparece en el momento: «esto decía otra cosa,
 * ¿quién lo corrigió?».
 */
final class ExpedienteHistoryController extends Controller
{
    /**
     * Los nombres de los campos como los ve el operador.
     *
     * La columna se llama `declared_total_amount` y en la pantalla dice
     * «Total declarado». Mostrar el nombre técnico obligaría a traducir de
     * memoria justo cuando alguien trata de entender qué pasó.
     *
     * @var array<string, string>
     */
    private const CAMPOS = [
        'subject' => 'Carátula',
        'received_date' => 'Fecha de recepción',
        'employer_id' => 'Empleador',
        'employer_representative' => 'Representante',
        'declared_total_amount' => 'Total declarado',
        'external_reference' => 'Referencia externa',
        'notes' => 'Observaciones',
        'status' => 'Estado',
        'canonical_number' => 'Número de SiCE',
    ];

    /** @var array<string, string> */
    private const ESTADOS = [
        'active' => 'Activo',
        'suspended' => 'Suspendido',
        'closed' => 'Cerrado',
        'cancelled' => 'Anulado',
    ];

    public function __construct(private readonly AuditTimeline $linea) {}

    public function __invoke(Expediente $expediente): JsonResponse
    {
        $eventos = $this->linea->eventsFor('Expediente', $expediente->id);

        return response()->json([
            'events' => $this->linea->shape($eventos, self::CAMPOS, [
                'status' => self::ESTADOS,
                'employer_id' => $this->nombresDe(
                    $this->linea->idsMencionados($eventos, 'employer_id'),
                ),
            ]),
        ]);
    }

    /**
     * El nombre de las personas que el historial nombra, por su id.
     *
     * El registro guarda el id y hace bien: la razón social puede
     * corregirse y el historial tiene que seguir señalando al mismo
     * empleador. Un número en pantalla, en cambio, no le dice nada a
     * nadie.
     *
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
