<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un ticket de depósito, tal como lo ve la pantalla. */
#[TypeScript]
final class DepositTicketData extends Data
{
    public function __construct(
        public int $id,
        public int $expedienteId,
        public string $expedienteNumber,
        public ?string $employerName,
        public ?int $installmentId,
        public ?string $installmentLabel,
        public string $accountLabel,
        public string $depositedAt,
        public ?string $depositedTime,
        /** @var numeric-string */
        public string $amount,
        public ?string $operationNumber,
        public DepositKind $depositKind,
        public DepositTicketStatus $status,
        public ?string $notes,
        public ?int $bankTransactionId,
        /** @var array<string, string>|null */
        public ?array $matchSignals,
        public ?string $matchedAt,
        public ?string $discardedReason,
        /** La foto del comprobante, para verla al lado del formulario. */
        public ?int $attachmentId,
        /** Cuántos días lleva esperando. Un ticket viejo sin aparecer es una señal. */
        public int $waitingDays,
    ) {}

    public static function fromModel(DepositTicket $ticket): self
    {
        $cuota = $ticket->installment;

        return new self(
            id: $ticket->id,
            expedienteId: $ticket->expediente_id,
            expedienteNumber: $ticket->expediente->display_number,
            employerName: $ticket->expediente->employer?->name,
            installmentId: $ticket->beneficiary_installment_id,
            installmentLabel: $cuota === null
                ? null
                : 'Cuota '.$cuota->installment_number,
            accountLabel: $ticket->account->label,
            depositedAt: $ticket->deposited_at->format('Y-m-d'),
            // Sin segundos: PostgreSQL devuelve `10:32:00` para un `time`
            // y lo que el ticket informa son horas y minutos.
            depositedTime: $ticket->deposited_time === null
                ? null
                : mb_substr($ticket->deposited_time, 0, 5),
            amount: $ticket->amount,
            operationNumber: $ticket->operation_number,
            depositKind: $ticket->deposit_kind,
            status: $ticket->status,
            notes: $ticket->notes,
            bankTransactionId: $ticket->bank_transaction_id,
            matchSignals: $ticket->match_signals,
            matchedAt: $ticket->matched_at?->toIso8601String(),
            discardedReason: $ticket->discarded_reason,
            /*
             * La última foto, no la primera. Corregir un ticket puede
             * traer una foto mejor —la anterior salió borrosa— y como
             * `attachments` es inmutable las dos quedan. La vigente es la
             * más reciente.
             */
            attachmentId: Attachment::query()
                ->forSubject(AttachmentSubject::DepositTicket, $ticket->id)
                ->latest('id')
                ->value('id'),
            waitingDays: (int) $ticket->deposited_at->diffInDays(BusinessDate::today(), false),
        );
    }
}
