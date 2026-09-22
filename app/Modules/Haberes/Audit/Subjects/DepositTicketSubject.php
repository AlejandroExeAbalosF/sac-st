<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Haberes\Audit\InstallmentLinks;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\EnumLabels;

/**
 * El comprobante de depósito que llega con el expediente.
 *
 * Puede no tener cuota todavía —se carga antes de saber a cuál va—, así
 * que el enlace lleva al expediente, que siempre lo tiene.
 */
final class DepositTicketSubject extends BaseAuditSubjectResolver
{
    public function __construct(private readonly InstallmentLinks $links) {}

    public function subjectType(): string
    {
        return 'DepositTicket';
    }

    public function label(): string
    {
        return 'Ticket de depósito';
    }

    public function fields(): array
    {
        return [
            'amount' => 'Importe',
            'deposited_at' => 'Fecha del depósito',
            'deposited_time' => 'Hora del depósito',
            'operation_number' => 'Número de operación',
            'terminal' => 'Terminal',
            'bank_account_id' => 'Cuenta',
            'bank_transaction_id' => 'Movimiento del extracto',
            'deposit_kind' => 'Tipo de depósito',
            'notes' => 'Observaciones',
            'status' => 'Estado',
            'discarded_reason' => 'Motivo del descarte',
            'match_signals' => 'Señales del cotejo',
        ];
    }

    public function valueLabels(): array
    {
        return ['status' => EnumLabels::of(DepositTicketStatus::class)];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $tickets = DepositTicket::query()
            ->with('expediente:id,display_number')
            ->whereIn('id', $ids)
            ->get(['id', 'expediente_id', 'operation_number']);

        $descripciones = [];

        foreach ($tickets as $ticket) {
            $nombre = $ticket->operation_number === null
                ? "Ticket n.º {$ticket->id}"
                : "Ticket operación {$ticket->operation_number}";

            $descripciones[$ticket->id] = new AuditSubjectDescription(
                $nombre.($ticket->expediente === null ? '' : ' · '.$this->links->expedienteLabel($ticket->expediente)),
                $ticket->expediente === null ? null : $this->links->expedienteUrl($ticket->expediente, $viewer),
            );
        }

        return $descripciones;
    }
}
