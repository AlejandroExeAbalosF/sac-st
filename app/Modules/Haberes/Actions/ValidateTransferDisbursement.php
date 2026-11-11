<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Banking\Support\BankMovementAfterOpening;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\InstallmentCashBox;
use App\Modules\Haberes\Support\TransferDebitMatch;
use App\Modules\Haberes\Support\TransferDebitPair;
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
 *
 * **Con comisión, el banco tiene dos patas.** La comisión la absorbe el
 * beneficiario, así que el egreso sigue siendo por lo que autorizó la
 * Orden, y sale de la cuenta en dos renglones:
 *
 * ```text
 *   Débito   BENEFICIARY_FUNDS   891.962,00   la Orden
 *   Crédito  BANK_ACCOUNT        891.841,00   la transferencia
 *   Crédito  BANK_ACCOUNT            121,00   la comisión
 * ```
 *
 * Cada crédito se imputa a su movimiento, con su rol: el extracto queda
 * conciliado completo y dice por separado cuánto se transfirió y cuánto
 * cobró el banco.
 */
final class ValidateTransferDisbursement
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly AllocatableAmount $disponible,
        private readonly TransferDebitPair $par,
        private readonly TransferStage $etapa,
        private readonly InstallmentCashBox $cajaDeLaCuota,
        private readonly RecordAuditEvent $auditar,
        private readonly BankMovementAfterOpening $posteriorALaApertura,
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
             * Un egreso por transferencia sin Orden no llega acá —lo impide
             * `disbursements_transfer_needs_order_check`—, pero el tipo no
             * lo sabe.
             */
            if ($orden === null) {
                throw ValidationException::withMessages([
                    'installmentId' => 'El egreso por transferencia no tiene su Orden de Pago.',
                ]);
            }

            $par = $this->parVigente($egreso, $movimiento, $orden);
            $comision = $par->fee;

            /*
             * La fecha del pago es la del débito: cuándo salió el dinero lo
             * fija el banco, y queda en el egreso. El asiento, en cambio, va
             * con el día en que se valida —ver más abajo—, para que un
             * débito de un día ya cerrado no obligue a reabrirlo.
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
            $moneda = $cuota->currency();

            $this->posteriorALaApertura->assertAllows($movimiento, $caja, $moneda);

            $evento = $this->asentar->handle(
                type: FinancialEventType::BankDisbursement,
                idempotencyKey: "egreso:{$egreso->id}:validacion",
                lines: [
                    EntryLine::debit(LedgerAccount::BeneficiaryFunds, $importe)
                        ->in($moneda)
                        ->forInstallment($cuota->haber_id, $cuota->id)
                        ->onBankAccount($orden->organism_bank_account_id)
                        ->onCashBox($caja),
                    EntryLine::credit(LedgerAccount::BankAccount, $par->transferAmount())
                        ->in($moneda)
                        ->onBankAccount($orden->organism_bank_account_id)
                        ->onCashBox($caja)
                        ->describedAs($comision === null ? null : 'Transferencia al beneficiario'),
                    ...($comision === null ? [] : [
                        EntryLine::credit(LedgerAccount::BankAccount, (string) $par->feeAmount())
                            ->in($moneda)
                            ->onBankAccount($orden->organism_bank_account_id)
                            ->onCashBox($caja)
                            ->describedAs('Comisión bancaria a cargo del beneficiario'),
                    ]),
                ],
                date: BusinessDate::today(),
                cashBoxId: $caja,
                description: "Egreso por transferencia — Orden {$orden->formatted_number}",
                actorId: $actorId,
            );

            BankTransactionAllocation::query()->create([
                'bank_transaction_id' => $movimiento->id,
                'financial_event_id' => $evento->id,
                'allocation_role' => BankAllocationRole::PaymentConfirmation,
                'amount' => $par->transferAmount(),
                'allocated_by' => $actorId,
                'allocated_at' => now(),
            ]);

            $this->updateReconciliation($movimiento);

            if ($comision !== null) {
                BankTransactionAllocation::query()->create([
                    'bank_transaction_id' => $comision->id,
                    'financial_event_id' => $evento->id,
                    'allocation_role' => BankAllocationRole::BankFee,
                    'amount' => $par->feeAmount(),
                    'allocated_by' => $actorId,
                    'allocated_at' => now(),
                ]);

                $this->updateReconciliation($comision);
            }

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
            $orden->forceFill(['status' => PaymentOrderStatus::Completed])->save();

            $this->auditar->handle('egreso.validado', $egreso, after: [
                'beneficiary_installment_id' => $cuota->id,
                'payment_order_id' => $orden->id,
                'bank_transaction_id' => $movimiento->id,
                'fee_bank_transaction_id' => $comision?->id,
                'transfer_amount' => $par->transferAmount(),
                'fee_amount' => $par->feeAmount(),
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
     * El par que se vinculó, comprobado de nuevo bajo lock.
     *
     * Entre vincular y validar pueden pasar días, y la regla es la misma que
     * aplicó la vinculación: los dos movimientos tienen que seguir enteros,
     * libres y sumando la Orden. Si la comisión que la regla encuentra hoy
     * no es la que se vinculó —se importó después, o quedó fuera del
     * circuito—, el egreso se desvincula y se vuelve a elegir.
     *
     * @throws ValidationException
     */
    private function parVigente(Disbursement $egreso, BankTransaction $movimiento, PaymentOrder $orden): TransferDebitMatch
    {
        $par = $this->par->resolve($movimiento, $orden, $egreso->amount, $egreso->id, lock: true);

        if (! $par->matches()) {
            throw ValidationException::withMessages([
                'installmentId' => 'El débito vinculado ya no cierra con la Orden: '.$par->rejection()
                    .' Hay que desvincularlo y volver a elegirlo.',
            ]);
        }

        if ($par->fee?->id !== $egreso->fee_bank_transaction_id) {
            throw ValidationException::withMessages([
                'installmentId' => 'La comisión de esa operación no es la que se vinculó. '
                    .'Hay que desvincular el débito y volver a elegirlo.',
            ]);
        }

        return $par;
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
