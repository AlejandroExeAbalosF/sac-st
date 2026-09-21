<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

/**
 * Corrige la ficha del expediente.
 *
 * Toca lo que el papel puede traer mal o incompleto: la carátula, la fecha
 * de recepción, el empleador, su representante, el total declarado y las
 * observaciones.
 *
 * **El número no está**, y no por olvido. El número es la identidad del
 * expediente: por él lo busca el operador, sobre él hay un índice único, y
 * a él apuntan los eventos de auditoría. Un número equivocado no es una
 * ficha con un error, es otro expediente —el que se cargó no existe—, y
 * eso se resuelve anulando y cargando el correcto.
 */
final class UpdateExpediente
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(Expediente $expediente, array $datos): Expediente
    {
        return DB::transaction(function () use ($expediente, $datos): Expediente {
            $bloqueado = Expediente::query()->lockForUpdate()->findOrFail($expediente->id);
            $antes = $this->valoresAuditables($bloqueado);

            $bloqueado->fill([
                'subject' => $datos['subject'] ?? null,
                'received_date' => $datos['receivedDate'],
                'employer_id' => $datos['employerId'],
                'employer_role' => 'employer',
                'employer_representative' => $datos['employerRepresentative'] ?? null,
                'declared_total_amount' => $datos['declaredTotalAmount'] ?? null,
                'external_reference' => $datos['externalReference'] ?? null,
                'notes' => $datos['notes'] ?? null,
            ])->save();

            [$viejos, $nuevos] = RecordAuditEvent::diff(
                $antes,
                $this->valoresAuditables($bloqueado),
            );

            if ($viejos !== []) {
                $this->auditar->handle('expediente.corregido', $bloqueado, $viejos, $nuevos);
            }

            return $bloqueado->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function valoresAuditables(Expediente $expediente): array
    {
        return [
            'subject' => $expediente->subject,
            'received_date' => $expediente->received_date?->toDateString(),
            'employer_id' => $expediente->employer_id,
            'employer_representative' => $expediente->employer_representative,
            'declared_total_amount' => $expediente->declared_total_amount,
            'external_reference' => $expediente->external_reference,
            'notes' => $expediente->notes,
        ];
    }
}
