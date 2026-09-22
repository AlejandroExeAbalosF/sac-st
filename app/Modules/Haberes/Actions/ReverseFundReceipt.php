<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace una recepción que no ocurrió: el dinero sale de los libros.
 *
 * El trigger de `fund_receipts` viene diciendo desde el principio «una
 * recepcion de fondos no se borra: se revierte» y «los datos no se editan:
 * se revierte y se registra de nuevo». Esto es esa puerta, que hasta acá
 * no existía.
 *
 * El caso que la hizo falta: el crédito de nuestro propio depósito de
 * efectivo llega al extracto como cualquier otro. Si alguien lo registra
 * como recepción en vez de acreditarlo contra el traslado, la misma plata
 * queda contada dos veces —una en tránsito desde la caja y otra como si
 * hubiera entrado de la nada— y el traslado ya no se puede acreditar,
 * porque el movimiento se quedó sin saldo libre.
 *
 * **El asiento inverso se arma dando vuelta el original, línea por
 * línea.** No se escriben las cuentas a mano: una recepción bancaria
 * asienta contra `BANK_ACCOUNT` y una de mostrador contra `CASH_ON_HAND`,
 * y el día que aparezca una tercera vía esto seguiría siendo correcto sin
 * que nadie se acuerde de tocarlo. Lo que se deshace es exactamente lo que
 * se hizo.
 *
 * **Y devuelve el saldo libre al movimiento del extracto**, que es lo que
 * permite volver a imputarlo bien. Sin eso la reversión limpiaría los
 * libros y dejaría el crédito bancario trabado para siempre.
 *
 * No revierte lo que ya tiene dueño: para eso está desasignar, y el orden
 * importa —primero se saca la plata de las cuotas, después se deshace su
 * entrada—.
 */
final class ReverseFundReceipt
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly InstallmentFunding $financiacion,
        private readonly AllocatableAmount $disponible,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  string  $reason  Obligatorio: el CHECK
     *                          `financial_events_reversal_reason_check` ata
     *                          el motivo a toda reversión, y el contrato
     *                          del Action tiene que decir lo mismo que la
     *                          base.
     *
     * @throws ValidationException
     */
    public function handle(
        FundReceipt $receipt,
        string $reason,
        string $idempotencyKey,
        ?int $actorId = null,
    ): FundReceipt {
        return DB::transaction(function () use ($receipt, $reason, $idempotencyKey, $actorId): FundReceipt {
            $bloqueada = FundReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            // Un segundo envío del mismo formulario.
            if ($bloqueada->reversal_event_id !== null) {
                return $bloqueada;
            }

            $this->assertSinDueño($bloqueada);

            $evento = $this->asentar->handle(
                type: FinancialEventType::Reversal,
                idempotencyKey: $idempotencyKey,
                lines: $this->inversoDe($bloqueada),
                date: BusinessDate::today(),
                cashBoxId: $bloqueada->cash_box_id,
                description: $reason,
                actorId: $actorId,
                reversalOfId: $bloqueada->financial_event_id,
                reversalReason: $reason,
            );

            $this->devolverElSaldoDelMovimiento($bloqueada, $evento->id, $reason, $actorId);

            $bloqueada->forceFill([
                'reversal_event_id' => $evento->id,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => $reason,
            ])->save();

            $this->auditar->handle('recepcion.revertida', $bloqueada, null, null, [
                'amount' => $bloqueada->amount,
                'reversal_event_id' => $evento->id,
                'motivo' => $reason,
            ], actorId: $actorId);

            return $bloqueada->refresh();
        });
    }

    /**
     * Nada de esta recepción puede estar financiando una cuota.
     *
     * Se rechaza en vez de desasignar por cuenta propia: sacarle el dinero
     * a una cuota es una decisión con su propio permiso y su propio
     * motivo, y encadenarla acá la dejaría ocurrir sin que nadie la pida.
     *
     * @throws ValidationException
     */
    private function assertSinDueño(FundReceipt $receipt): void
    {
        $asignado = $this->financiacion->allocatedFrom($receipt);

        if (! Decimal::equals($asignado, '0')) {
            throw ValidationException::withMessages([
                'receiptId' => sprintf(
                    'Esta recepción ya financia cuotas por $ %s. Primero hay que desasignar esos fondos.',
                    Decimal::format($asignado),
                ),
            ]);
        }
    }

    /**
     * El asiento original, dado vuelta.
     *
     * @return list<EntryLine>
     */
    private function inversoDe(FundReceipt $receipt): array
    {
        $original = JournalLine::query()
            ->where('financial_event_id', $receipt->financial_event_id)
            ->orderBy('id')
            ->get();

        /** @var list<EntryLine> $lineas */
        $lineas = $original->map(function (JournalLine $linea): EntryLine {
            // `account_code` ya llega como enum: el modelo lo castea.
            $cuenta = $linea->account_code;
            $esDebito = ! Decimal::equals(Decimal::scale($linea->debit), '0');

            $invertida = $esDebito
                ? EntryLine::credit($cuenta, Decimal::scale($linea->debit))
                : EntryLine::debit($cuenta, Decimal::scale($linea->credit));

            return $invertida
                ->onCashBox($linea->cash_box_id)
                ->onBankAccount($linea->bank_account_id)
                ->from($linea->depositor_id);
        })->all();

        return $lineas;
    }

    /**
     * El crédito del extracto vuelve a tener saldo libre.
     *
     * La imputación no se borra —nada del libro se borra—: se le agrega su
     * reversa, que es lo que `AllocatableAmount` netea. Y el estado de
     * conciliación se recalcula, porque un movimiento que vuelve a estar
     * sin imputar tiene que volver a ofrecerse.
     */
    private function devolverElSaldoDelMovimiento(
        FundReceipt $receipt,
        int $eventoId,
        string $reason,
        ?int $actorId,
    ): void {
        $imputaciones = BankTransactionAllocation::query()
            ->where('financial_event_id', $receipt->financial_event_id)
            ->whereNull('reversal_of_id')
            ->get();

        foreach ($imputaciones as $imputacion) {
            BankTransactionAllocation::query()->create([
                'bank_transaction_id' => $imputacion->bank_transaction_id,
                'financial_event_id' => $eventoId,
                'allocation_role' => BankAllocationRole::FundsReceived,
                'reversal_of_id' => $imputacion->id,
                'amount' => $imputacion->amount,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
                'notes' => $reason,
            ]);
        }

        $movimientos = BankTransaction::query()
            ->whereIn('id', $imputaciones->pluck('bank_transaction_id')->unique())
            ->get();

        foreach ($movimientos as $movimiento) {
            $imputado = $this->disponible->allocated($movimiento);
            $total = Decimal::abs($movimiento->amount);

            $movimiento->forceFill([
                'reconciliation_status' => match (true) {
                    Decimal::equals($imputado, '0') => ReconciliationStatus::Pending,
                    Decimal::equals($imputado, $total) => ReconciliationStatus::Reconciled,
                    default => ReconciliationStatus::Partial,
                },
            ])->save();
        }
    }
}
