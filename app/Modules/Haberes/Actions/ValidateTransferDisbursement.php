<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Support\InstallmentCashBox;
use App\Modules\Haberes\Support\TransferStage;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El contador coteja Orden, informe y débito, y el pago pasa a ser un
 * hecho — §2.3.4 y §2.3.5 del DER.
 *
 * ```text
 *   Débito   BENEFICIARY_FUNDS   deja de estar asignado a la cuota
 *   Crédito  BANK_ACCOUNT        y salió de la cuenta del organismo
 * ```
 *
 * **Es el único acto que postea el egreso bancario**, y esa exclusividad
 * es la regla entera: hasta acá hay dos papeles que dicen que se pagó —el
 * aviso del organismo y una línea del extracto— y nadie que haya afirmado
 * que son el mismo pago. El §2.3.5 lo ordena así: *«solo después de esa
 * validación se postea el evento financiero, se considera pagada la cuota
 * y se emite el recibo de egreso»*.
 *
 * Las tres cosas ocurren acá, en ese orden, dentro de una transacción.
 *
 * **Y recién acá se escribe la imputación bancaria.** `LinkTransferDebit`
 * dejó apuntado el movimiento; la fila de `bank_transaction_allocations`
 * necesita el evento financiero, que nace en este momento. Con ella el
 * débito queda sin saldo libre y deja de ofrecerse como candidato de otra
 * Orden: es lo que impide que la misma transferencia pague dos cuotas.
 */
final class ValidateTransferDisbursement
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly AllocatableAmount $disponible,
        private readonly TransferStage $etapa,
        private readonly InstallmentCashBox $cajaDeLaCuota,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(Disbursement $disbursement, ?int $actorId = null, ?string $notes = null): Disbursement
    {
        return DB::transaction(function () use ($disbursement, $actorId, $notes): Disbursement {
            /*
             * Bajo lock y releído: dos contadores validando a la vez
             * postearían dos asientos por el mismo pago. El segundo
             * encuentra el egreso ya confirmado y se va sin hacer nada,
             * que es lo que el operador esperaba.
             */
            $egreso = Disbursement::query()->lockForUpdate()->findOrFail($disbursement->id);

            if ($egreso->status === DisbursementStatus::Confirmed) {
                return $egreso;
            }

            $this->assertValidatable($egreso);

            $egreso->loadMissing(['paymentOrder', 'installment', 'bankTransaction']);

            /** @var BankTransaction $movimiento */
            $movimiento = $egreso->bankTransaction;
            $orden = $egreso->paymentOrder;
            $cuota = $egreso->installment;
            $importe = Decimal::scale($egreso->amount);

            /*
             * La fecha del pago es la del débito, no la de hoy: lo que el
             * libro tiene que decir es cuándo salió el dinero, y eso lo
             * fija el banco.
             */
            $fecha = $movimiento->transaction_date
                ?? ($egreso->report_received_at === null ? null : BusinessDate::fromInstant($egreso->report_received_at))
                ?? BusinessDate::today();

            /*
             * La caja, aunque el dinero no pase por el cajón.
             *
             * «DEPOSITOS DIRECTOS» es la tercera columna de la planilla y
             * se calcula sobre `BANK_ACCOUNT` filtrando por caja, igual
             * que el efectivo. Sin esto el egreso no entra en ningún
             * cierre: la columna sumaría los depósitos de las empresas y
             * no restaría nunca las transferencias al beneficiario. Es
             * además lo que somete al asiento a la guarda de período
             * cerrado, que no puede frenar un evento sin caja.
             */
            $caja = $this->cajaDeLaCuota->for($cuota);

            $evento = $this->asentar->handle(
                type: FinancialEventType::BankDisbursement,
                idempotencyKey: "egreso:{$egreso->id}:validacion",
                lines: [
                    EntryLine::debit(LedgerAccount::BeneficiaryFunds, $importe)
                        ->forInstallment($cuota->haber_id, $cuota->id)
                        ->onBankAccount($orden?->organism_bank_account_id)
                        ->onCashBox($caja),
                    EntryLine::credit(LedgerAccount::BankAccount, $importe)
                        ->onBankAccount($orden?->organism_bank_account_id)
                        ->onCashBox($caja),
                ],
                date: $fecha,
                cashBoxId: $caja,
                description: 'Egreso por transferencia'
                    .($orden === null ? '' : " — Orden {$orden->formatted_number}"),
                actorId: $actorId,
            );

            BankTransactionAllocation::query()->create([
                'bank_transaction_id' => $movimiento->id,
                'financial_event_id' => $evento->id,
                'allocation_role' => BankAllocationRole::PaymentConfirmation,
                'amount' => $importe,
                'allocated_by' => $actorId,
                'allocated_at' => now(),
            ]);

            $this->updateReconciliation($movimiento);

            $egreso->forceFill([
                'financial_event_id' => $evento->id,
                'payment_date' => $fecha,
                'validated_by' => $actorId,
                'validated_at' => now(),
                'status' => DisbursementStatus::Confirmed,
                'notes' => $notes ?? $egreso->notes,
            ])->save();

            /*
             * `paid` no significa «se cobró la cuota»: significa que el
             * egreso al beneficiario está confirmado.
             */
            $cuota->forceFill(['workflow_status' => InstallmentWorkflowStatus::Paid])->save();

            /*
             * La Orden cumplió su cometido y sale de circulación. Deja de
             * ocupar el lugar de la cuota, que es lo que el índice único
             * parcial administra.
             */
            $orden?->forceFill(['status' => PaymentOrderStatus::Completed])->save();

            $this->auditar->handle('egreso.validado', $egreso, after: [
                'beneficiary_installment_id' => $cuota->id,
                'payment_order_id' => $orden?->id,
                'bank_transaction_id' => $movimiento->id,
                'financial_event_id' => $evento->id,
                'amount' => $importe,
                'payment_date' => $fecha->toDateString(),
            ], actorId: $actorId);

            return $egreso;
        });
    }

    /** @throws ValidationException */
    private function assertValidatable(Disbursement $egreso): void
    {
        if (! $egreso->status->isLive()) {
            throw ValidationException::withMessages([
                'installmentId' => "El egreso está «{$egreso->status->label()}»: no hay nada que validar.",
            ]);
        }

        $falta = $this->etapa->missing($egreso);

        if ($falta !== null) {
            throw ValidationException::withMessages([
                'installmentId' => $falta,
            ]);
        }
    }

    /**
     * El estado del movimiento sale de lo que ya se le imputó — se
     * recalcula en vez de acumularse, igual que en las recepciones y en la
     * acreditación de los depósitos.
     */
    private function updateReconciliation(BankTransaction $transaction): void
    {
        $imputado = $this->disponible->allocated($transaction);
        $total = Decimal::abs($transaction->amount);

        $estado = match (true) {
            Decimal::equals($imputado, '0') => ReconciliationStatus::Pending,
            Decimal::equals($imputado, $total) => ReconciliationStatus::Reconciled,
            default => ReconciliationStatus::Partial,
        };

        $transaction->forceFill(['reconciliation_status' => $estado])->save();
    }
}
