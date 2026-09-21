<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vincula el ticket con el movimiento que el operador reconoció.
 *
 * **Todavía no hay recepción.** Esto dice «este papel y este crédito son
 * el mismo hecho»; convertir eso en dinero imputado a una cuota necesita
 * `fund_receipts` y `funding_allocations`, que son la tanda siguiente. La
 * separación no es burocracia: reconocer el movimiento es un acto de
 * lectura, imputarlo es un acto contable, y los firma gente distinta.
 *
 * **Las señales quedan guardadas.** No para la interfaz —ahí ya se
 * vieron— sino para poder responder con datos, dentro de unos meses, si el
 * número de operación del ticket sirvió alguna vez para encontrar el
 * movimiento. Es lo que convierte esa duda en una medición.
 */
final class LinkDepositTicket
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly FindTicketCandidates $buscar,
    ) {}

    public function handle(DepositTicket $ticket, BankTransaction $movimiento, int $userId): DepositTicket
    {
        $this->assertLinkable($ticket, $movimiento);

        $senales = $this->signalsFor($ticket, $movimiento);

        return DB::transaction(function () use ($ticket, $movimiento, $senales, $userId): DepositTicket {
            $ticket->forceFill([
                'bank_transaction_id' => $movimiento->id,
                'status' => DepositTicketStatus::Matched,
                'match_signals' => $senales,
                'matched_by' => $userId,
                'matched_at' => now(),
            ])->save();

            $this->auditar->handle('ticket.vinculado', $ticket, after: [
                'bank_transaction_id' => $movimiento->id,
                'match_signals' => $senales,
            ], actorId: $userId);

            return $ticket;
        });
    }

    /** Deshace el vínculo, dejando el ticket otra vez a la espera. */
    public function unlink(DepositTicket $ticket, string $reason, int $userId): DepositTicket
    {
        if ($ticket->status !== DepositTicketStatus::Matched) {
            throw new RuntimeException('El ticket no está vinculado a ningún movimiento.');
        }

        /*
         * Si de ese movimiento ya nació una recepción, el ticket dejó de
         * ser una propuesta: es el respaldo de dinero asentado. Desvincular
         * no rompe el rastro —la recepción apunta al movimiento, no al
         * ticket— pero deja el asiento sin el papel que lo explica.
         */
        $respalda = BankTransactionAllocation::query()
            ->where('bank_transaction_id', $ticket->bank_transaction_id)
            ->exists();

        if ($respalda) {
            throw new RuntimeException(
                'Ese movimiento ya tiene una recepción registrada: el ticket es su respaldo. '
                .'Hay que revertir la recepción antes de desvincularlo.'
            );
        }

        $anterior = $ticket->bank_transaction_id;

        return DB::transaction(function () use ($ticket, $anterior, $reason, $userId): DepositTicket {
            $ticket->forceFill([
                'bank_transaction_id' => null,
                'status' => DepositTicketStatus::Waiting,
                'match_signals' => null,
                'matched_by' => null,
                'matched_at' => null,
            ])->save();

            $this->auditar->handle(
                'ticket.desvinculado',
                $ticket,
                before: ['bank_transaction_id' => $anterior],
                metadata: ['motivo' => $reason, 'usuario' => $userId],
                actorId: $userId,
            );

            return $ticket;
        });
    }

    private function assertLinkable(DepositTicket $ticket, BankTransaction $movimiento): void
    {
        if ($ticket->status === DepositTicketStatus::Matched) {
            throw new RuntimeException('El ticket ya está vinculado a un movimiento.');
        }

        if ($ticket->status === DepositTicketStatus::Discarded) {
            throw new RuntimeException('El ticket está descartado: hay que reabrirlo antes de vincularlo.');
        }

        if ($movimiento->bank_account_id !== $ticket->bank_account_id) {
            throw new RuntimeException('El movimiento es de otra cuenta.');
        }

        if (! Decimal::equals($movimiento->amount, $ticket->amount)) {
            throw new RuntimeException(sprintf(
                'El movimiento es por $%s y el ticket por $%s.',
                Decimal::format($movimiento->amount),
                Decimal::format($ticket->amount),
            ));
        }

        /*
         * La base ya lo impide con un índice único parcial. Esto existe
         * para que el operador reciba una explicación en lugar de una
         * excepción de PostgreSQL.
         */
        $tomado = DepositTicket::query()
            ->where('bank_transaction_id', $movimiento->id)
            ->where('id', '!=', $ticket->id)
            ->exists();

        if ($tomado) {
            throw new RuntimeException('Ese movimiento ya está vinculado a otro ticket.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function signalsFor(DepositTicket $ticket, BankTransaction $movimiento): array
    {
        foreach ($this->buscar->handle($ticket)->candidates as $candidato) {
            if ($candidato->transaction->id === $movimiento->id) {
                return $candidato->signals;
            }
        }

        /*
         * El operador eligió un movimiento que el buscador no propuso.
         * Pasa cuando lo encontró a mano y está fuera de la ventana de
         * fechas: es legítimo, y merece quedar señalado como tal.
         */
        return ['vínculo' => 'elegido a mano, fuera de las sugerencias'];
    }
}
