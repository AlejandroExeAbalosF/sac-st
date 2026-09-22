<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El comprobante visto desde su cuota.
 *
 * Es `DepositTicketData` sin el contexto que la pantalla del haber ya
 * tiene —expediente, empleador, beneficiario— y con lo que el formulario
 * de corrección necesita y aquel no lleva: la cuenta y la terminal.
 *
 * La diferencia no es cosmética: traer el DTO completo obligaría a cargar
 * expediente y empleador **por cada cuota** de un plan que puede tener
 * sesenta, para repetir sesenta veces un dato que está en el encabezado.
 */
#[TypeScript]
final class InstallmentTicketData extends Data
{
    public function __construct(
        public int $id,
        public string $depositedAt,
        public ?string $depositedTime,
        /** @var numeric-string */
        public string $amount,
        public ?string $operationNumber,
        public ?string $terminal,
        public DepositKind $depositKind,
        public DepositTicketStatus $status,
        public ?string $notes,
        public int $bankAccountId,
        public string $accountLabel,
        /** La foto vigente. Puede faltar: el área no la exige. */
        public ?int $attachmentId,
        /** El movimiento con el que se lo reconoció, si el cruce ya se hizo. */
        public ?int $bankTransactionId,
        /**
         * La recepción nacida de ese movimiento, si ya se registró.
         *
         * **Cruzar el ticket y registrar la recepción son dos actos**, y
         * desde la tarjeta se ven iguales: en los dos casos el papel figura
         * «encontrado en el extracto». Sin esto, la pantalla no puede
         * distinguir «falta registrar» de «falta asignar», y el cartel que
         * dice qué hacer termina diciendo lo que no es.
         */
        public ?int $fundReceiptId,
        /**
         * Si todavía admite correcciones.
         *
         * Vinculado, sus datos son la base de una afirmación ya hecha y
         * cambiarlos por debajo dejaría un cruce injustificable. Hay que
         * desvincular primero.
         */
        public bool $editable,
        /** Cuántos días lleva esperando aparecer en el extracto. */
        public int $waitingDays,
    ) {}

    /**
     * Los identificadores derivados llegan calculados en lote: buscarlos
     * por ticket serían consultas extra por cada fila de un plan largo.
     *
     * @param  array<int, int>  $fundReceipts  id de ticket => id de recepción
     * @param  array<int, int>  $attachments  id de ticket => id de adjunto
     */
    public static function fromModel(
        DepositTicket $ticket,
        array $fundReceipts = [],
        array $attachments = [],
    ): self {
        return new self(
            id: $ticket->id,
            depositedAt: $ticket->deposited_at->format('Y-m-d'),
            /*
             * Sin segundos. PostgreSQL devuelve `10:32:00` para un `time`,
             * y ese formato no es el que valida el alta —`H:i`— ni el que
             * un `<input type="time">` devuelve al editar. Recortarlo acá
             * evita que corregir cualquier otro campo arrastre un error
             * en uno que nadie tocó.
             */
            depositedTime: self::sinSegundos($ticket->deposited_time),
            amount: $ticket->amount,
            operationNumber: $ticket->operation_number,
            terminal: $ticket->terminal,
            depositKind: $ticket->deposit_kind,
            status: $ticket->status,
            notes: $ticket->notes,
            bankAccountId: $ticket->bank_account_id,
            accountLabel: $ticket->account->label,
            attachmentId: $attachments[$ticket->id] ?? null,
            bankTransactionId: $ticket->bank_transaction_id,
            fundReceiptId: $fundReceipts[$ticket->id] ?? null,
            editable: $ticket->status === DepositTicketStatus::Waiting,
            waitingDays: (int) $ticket->deposited_at->diffInDays(BusinessDate::today(), false),
        );
    }

    private static function sinSegundos(?string $hora): ?string
    {
        return $hora === null ? null : mb_substr($hora, 0, 5);
    }
}
