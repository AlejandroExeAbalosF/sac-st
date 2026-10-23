<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Los movimientos del extracto de un cheque rechazado: el crédito que el banco
 * hizo antes de rechazarlo y el débito con que lo revierte.
 *
 * Cada uno es un movimiento entero de la cuenta del traslado, desde la
 * apertura, y sin otro uso: el remanente de otro movimiento no
 * es el débito de este cheque. Y de fecha posible: el banco no acredita ni
 * debita un cheque antes de que se deposite —se admite un día antes, por si
 * el ticket se cargó con la fecha mal—, ni lo debita antes de haberlo
 * acreditado. El aviso del rechazo no es un límite: puede llegar después del
 * débito. La base lo exige igual
 * (`cheque_rejection_movement_whole`); esto lo dice con palabras y deja la
 * imputación.
 */
final class RejectedChequeMovements
{
    public function __construct(
        private readonly AllocatableAmount $disponible,
        private readonly TransactionReconciliation $conciliacion,
        private readonly BankMovementAfterOpening $posteriorALaApertura,
    ) {}

    /** @throws ValidationException */
    public function assertUsable(ChequeRejection $rechazo, BankTransaction $movimiento, TransactionDirection $sentido): void
    {
        $cheque = FundReceipt::query()->findOrFail($rechazo->fund_receipt_id);
        $cuenta = $rechazo->transfer?->bank_account_id;
        $campo = $sentido === TransactionDirection::Debit ? 'debitTransactionId' : 'creditTransactionId';
        $que = $sentido === TransactionDirection::Debit ? 'débito' : 'crédito';

        if ($cuenta === null || $movimiento->bank_account_id !== $cuenta) {
            throw ValidationException::withMessages([$campo => "El {$que} tiene que ser de la cuenta a la que se depositó el cheque."]);
        }

        if ($movimiento->direction !== $sentido) {
            throw ValidationException::withMessages([$campo => "Ese movimiento no es un {$que}."]);
        }

        if ($movimiento->reconciliation_status === ReconciliationStatus::Ignored) {
            throw ValidationException::withMessages([$campo => 'Ese movimiento está marcado como ignorado.']);
        }

        if (! Decimal::equals(Decimal::abs($movimiento->amount), $cheque->amount)
            || ! Decimal::equals($this->disponible->forUpdate($movimiento), Decimal::abs($movimiento->amount))) {
            throw ValidationException::withMessages([
                $campo => "El {$que} de un cheque rechazado es un movimiento entero, por el importe del cheque y sin otro uso.",
            ]);
        }

        $this->assertCronologia($rechazo, $movimiento, $sentido, $campo, $que);

        $this->posteriorALaApertura->assertAllows($movimiento, (int) $cheque->cash_box_id, Currency::from($cheque->currency));
    }

    /**
     * La fecha más temprana posible del movimiento: el día anterior al
     * depósito y, para el débito, el día del crédito si se conoce.
     */
    public function earliest(ChequeRejection $rechazo, TransactionDirection $sentido): ?CarbonInterface
    {
        return $this->earliestFor($rechazo->transfer, $rechazo->credit_event_id ?? $rechazo->transfer?->credit_event_id, $sentido);
    }

    /** Lo mismo para el traslado del cheque, antes de que exista el rechazo. */
    public function earliestFor(?CashToBankTransfer $traslado, ?int $eventoDeCredito, TransactionDirection $sentido): ?CarbonInterface
    {
        $desde = $traslado?->deposit_date->copy()->subDay();

        if ($sentido !== TransactionDirection::Debit || $eventoDeCredito === null) {
            return $desde;
        }

        $credito = $this->creditDate($eventoDeCredito);

        return $credito !== null && ($desde === null || $credito->gt($desde)) ? $credito : $desde;
    }

    /** @throws ValidationException */
    private function assertCronologia(ChequeRejection $rechazo, BankTransaction $movimiento, TransactionDirection $sentido, string $campo, string $que): void
    {
        $fecha = $movimiento->transaction_date;
        $desde = $this->earliest($rechazo, $sentido);

        if ($fecha !== null && $desde !== null && $fecha->lt($desde)) {
            throw ValidationException::withMessages([
                $campo => "Ese {$que} es del ".$fecha->format('d/m/Y').', anterior a lo que el banco hizo con este cheque: no puede ser el suyo.',
            ]);
        }
    }

    /** El día del crédito del cheque en el extracto: el de su depósito acreditado o el registrado después del rechazo. */
    private function creditDate(int $evento): ?CarbonInterface
    {
        $fecha = BankTransactionAllocation::query()
            ->where('financial_event_id', $evento)
            ->whereNull('reversal_of_id')
            ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_allocations.bank_transaction_id')
            ->value('bank_transactions.transaction_date');

        return $fecha === null ? null : CarbonImmutable::parse((string) $fecha);
    }

    /** Imputa el movimiento al asiento y recalcula su conciliación. */
    public function allocate(BankTransaction $movimiento, FinancialEvent $evento, BankAllocationRole $rol, ?int $actorId): void
    {
        BankTransactionAllocation::query()->create([
            'bank_transaction_id' => $movimiento->id,
            'financial_event_id' => $evento->id,
            'allocation_role' => $rol,
            'amount' => Decimal::abs($movimiento->amount),
            'allocated_by' => $actorId,
            'allocated_at' => now(),
        ]);

        $this->conciliacion->refresh($movimiento);
    }
}
