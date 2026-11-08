<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Support\DepositTicketLinks;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\ReasonCode;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Vincula el ticket con el movimiento que el operador reconoció.
 *
 * **Todavía no hay recepción.** Esto dice «este papel y este crédito son
 * el mismo hecho»; convertir eso en dinero imputado a una cuota lo hace
 * `ReceiveAndAllocateTicket`, en un acto aparte: reconocer el movimiento
 * es un acto de lectura, imputarlo es un acto contable, y los firma gente
 * distinta.
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
        private readonly AllocatableAmount $disponible,
        private readonly DepositTicketLinks $enlaces,
    ) {}

    public function handle(DepositTicket $ticket, BankTransaction $movimiento, int $userId): DepositTicket
    {
        return $this->handleMany($ticket, [$movimiento], $userId);
    }

    /** @param list<BankTransaction> $movimientos */
    public function handleMany(DepositTicket $ticket, array $movimientos, int $userId): DepositTicket
    {
        $ids = array_map(fn (BankTransaction $m): int => $m->id, $movimientos);
        if ($ids === [] || count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('Elegí movimientos distintos para conciliar el comprobante.');
        }

        return DB::transaction(function () use ($ticket, $ids, $userId): DepositTicket {
            $ticket = DepositTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $movimientos = BankTransaction::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
            if ($movimientos->count() !== count($ids)) {
                throw new RuntimeException('Alguno de los movimientos ya no existe.');
            }

            $total = '0.00';
            foreach ($movimientos as $movimiento) {
                $this->assertLinkable($ticket, $movimiento);
                $total = Decimal::add($total, $movimiento->amount);
                if (Decimal::isNegative(Decimal::sub($ticket->amount, $total))) {
                    throw new RuntimeException('La suma de los movimientos supera el importe del comprobante.');
                }
            }
            if (! Decimal::equals($total, $ticket->amount)) {
                throw new RuntimeException('La suma de los movimientos debe coincidir exactamente con el importe del comprobante.');
            }

            $primero = $movimientos->firstOrFail();
            $senales = $this->signalsFor($ticket, $primero);
            $ticket->forceFill([
                'bank_transaction_id' => $primero->id,
                'status' => DepositTicketStatus::Matched,
                'match_signals' => $senales,
                'matched_by' => $userId,
                'matched_at' => now(),
            ])->save();

            foreach ($movimientos->skip(1) as $adicional) {
                DB::table('deposit_ticket_additional_transactions')->insert([
                    'deposit_ticket_id' => $ticket->id,
                    'bank_transaction_id' => $adicional->id,
                    'match_signals' => json_encode($this->signalsFor($ticket, $adicional), JSON_THROW_ON_ERROR),
                ]);
            }

            $this->auditar->handle('ticket.vinculado', $ticket, after: [
                'bank_transaction_id' => $primero->id,
                'bank_transaction_ids' => $ids,
                'match_signals' => $senales,
            ], actorId: $userId);

            return $ticket;
        });
    }

    /** Deshace el vínculo, dejando el ticket otra vez a la espera. */
    public function unlink(DepositTicket $ticket, string $reason, int $userId, ?ReasonCode $reasonCode = null): DepositTicket
    {
        if ($ticket->status !== DepositTicketStatus::Matched) {
            throw new RuntimeException('El ticket no está vinculado a ningún movimiento.');
        }

        /*
         * Si de ese movimiento ya nació una recepción, el ticket dejó de
         * ser una propuesta: es el respaldo de dinero asentado. Desvincular
         * no rompe el rastro —la recepción apunta al movimiento, no al
         * ticket— pero deja el asiento sin el papel que lo explica.
         *
         * Se mira lo imputado **neto**: revertir no borra la imputación, le
         * agrega su reversa. Preguntar si existe una fila dejaba el ticket
         * atado para siempre a un crédito que ya no respalda nada.
         */
        foreach ($ticket->transactionIds() as $id) {
            $movimiento = BankTransaction::query()->findOrFail($id);
            if (! Decimal::equals($this->disponible->allocated($movimiento), '0')) {
                throw new RuntimeException(
                    'Uno de los movimientos ya tiene una recepción registrada: hay que revertir la recepción antes de desvincular.'
                );
            }
        }

        $anteriores = $ticket->transactionIds();

        return DB::transaction(function () use ($ticket, $anteriores, $reason, $userId, $reasonCode): DepositTicket {
            DB::table('deposit_ticket_additional_transactions')->where('deposit_ticket_id', $ticket->id)->delete();
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
                before: ['bank_transaction_id' => $anteriores[0], 'bank_transaction_ids' => $anteriores],
                metadata: [
                    'motivo' => $reason,
                    'usuario' => $userId,
                    ...($reasonCode === null ? [] : ['motivo_codigo' => $reasonCode->value]),
                ],
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

        if ($movimiento->direction !== TransactionDirection::Credit || $movimiento->reconciliation_status === ReconciliationStatus::Ignored) {
            throw new RuntimeException('Solo se pueden conciliar créditos bancarios activos.');
        }

        $apertura = $this->enlaces->openingDate($ticket->bank_account_id);

        if ($apertura !== null && $movimiento->transaction_date?->format('Y-m-d') < $apertura) {
            throw new RuntimeException(sprintf(
                'Ese movimiento es anterior a la apertura de los libros (%s): ya está en el saldo inicial y no se concilia. '
                .'Esa plata va por «Cuota del sistema anterior».',
                CarbonImmutable::parse($apertura)->format('d/m/Y'),
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
        if (DB::table('deposit_ticket_additional_transactions')->where('bank_transaction_id', $movimiento->id)->exists()) {
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
