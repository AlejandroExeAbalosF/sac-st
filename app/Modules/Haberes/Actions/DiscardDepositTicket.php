<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Shared\Actions\RecordAuditEvent;
use RuntimeException;

/**
 * Declara que ese depósito no va a aparecer.
 *
 * Es la salida para el ticket que quedaría esperando para siempre: el
 * comprobante estaba mal, el depósito se anuló, o se cargó por error. Sin
 * ella la cola de espera se llena de casos muertos y deja de servir como
 * cola.
 *
 * **No borra nada.** El ticket sigue existiendo con su motivo y su
 * comprobante adjunto: que un expediente haya traído un papel que después
 * no valía es información, y borrarlo dejaría al expediente pareciendo que
 * nunca lo trajo. Es la misma posición que el DER toma con los recibos
 * anulados —conservan número y evidencia— y con la anulación de
 * expedientes del punto 14.
 */
final class DiscardDepositTicket
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(DepositTicket $ticket, string $reason, int $userId): DepositTicket
    {
        if ($ticket->status === DepositTicketStatus::Matched) {
            throw new RuntimeException(
                'El ticket ya está vinculado a un movimiento: hay que desvincularlo antes de descartarlo.'
            );
        }

        if ($ticket->status === DepositTicketStatus::Discarded) {
            throw new RuntimeException('El ticket ya estaba descartado.');
        }

        $ticket->forceFill([
            'status' => DepositTicketStatus::Discarded,
            'discarded_reason' => mb_substr(trim($reason), 0, 300),
        ])->save();

        $this->auditar->handle(
            'ticket.descartado',
            $ticket,
            before: ['status' => DepositTicketStatus::Waiting->value],
            after: [
                'status' => DepositTicketStatus::Discarded->value,
                'discarded_reason' => $ticket->discarded_reason,
            ],
            metadata: ['usuario' => $userId],
            actorId: $userId,
        );

        return $ticket;
    }

    /** Lo devuelve a la cola: el depósito apareció después de todo. */
    public function reopen(DepositTicket $ticket, int $userId): DepositTicket
    {
        if ($ticket->status !== DepositTicketStatus::Discarded) {
            throw new RuntimeException('El ticket no está descartado.');
        }

        $motivo = $ticket->discarded_reason;

        $ticket->forceFill([
            'status' => DepositTicketStatus::Waiting,
            'discarded_reason' => null,
        ])->save();

        $this->auditar->handle(
            'ticket.reabierto',
            $ticket,
            before: ['discarded_reason' => $motivo],
            metadata: ['usuario' => $userId],
            actorId: $userId,
        );

        return $ticket;
    }
}
