<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\ExpedienteNumber;
use App\Modules\Shared\Actions\RecordAuditEvent;

/**
 * Da de alta el expediente, sin sus haberes.
 *
 * El alta se parte en dos a propósito. El expediente vuelve con el tiempo
 * trayendo el ticket de la cuota siguiente, así que el sistema tiene que
 * saber abrir uno ya cargado y completarlo; el alta usa ese mismo camino
 * en vez de uno propio.
 */
final class CreateExpediente
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(array $datos, ExpedienteNumber $numero, int $employerId, ?int $userId): Expediente
    {
        $expediente = Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'external_id' => $datos['externalId'] ?? null,
            'canonical_number' => $numero->canonical,
            'display_number' => $numero->display,
            'external_reference' => $datos['externalReference'] ?? null,
            'year' => (int) $numero->period,
            'received_date' => $datos['receivedDate'],
            'employer_id' => $employerId,
            // Constante. La FK compuesta contra `person_roles` la usa para
            // exigir que esa persona esté registrada como empleador.
            'employer_role' => 'employer',
            'employer_representative' => $datos['employerRepresentative'] ?? null,
            // `?? null` y no acceso directo: al pasar a opcional, `validated()`
            // deja de traer la clave cuando el formulario no la manda.
            'subject' => $datos['subject'] ?? null,
            'notes' => $datos['notes'] ?? null,
            'declared_total_amount' => $datos['declaredTotalAmount'] ?? null,
            'status' => ExpedienteStatus::Active,
            'created_by' => $userId,
        ]);

        $this->auditar->handle(
            'expediente.registrado',
            $expediente,
            after: [
                'canonical_number' => $expediente->canonical_number,
                'employer_id' => $expediente->employer_id,
                'declared_total_amount' => $expediente->declared_total_amount,
            ],
            actorId: $userId,
        );

        return $expediente;
    }
}
