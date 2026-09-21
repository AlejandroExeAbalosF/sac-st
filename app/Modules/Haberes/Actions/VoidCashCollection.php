<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un cobro por mostrador: el dinero nunca entró.
 *
 * **No es lo mismo que liberar.** Liberar mueve plata que existe de una
 * cuota al pozo de no identificados. Esto deshace la afirmación de que
 * entró: se usa cuando el importe se tipeó mal y el sistema asentó en la
 * caja un dinero que nadie trajo.
 *
 * El caso concreto que lo vuelve necesario: el cobro por mostrador toma el
 * importe **de la cuota**. Si la cuota decía $1.000.000 y el empleador
 * trajo $100.000, el libro quedó afirmando que hay $1.000.000 en la caja.
 * Liberar el excedente lo empeoraría —moverían $900.000 inexistentes al
 * pozo, donde quedarían ofreciéndose para otra cuota—. Lo que hay que
 * deshacer es la recepción misma.
 *
 * **Son dos reversiones, no una.** La imputación vuelve al pozo y la
 * recepción sale de la caja; el neto deja `CASH_ON_HAND` y
 * `BENEFICIARY_FUNDS` como estaban antes del error, y el arqueo del día
 * vuelve a cerrar.
 *
 * El recibo se anula con su número: **ninguna anulación libera un
 * número**. El reemplazante toma el siguiente disponible de la serie —el
 * que esté libre en ese momento, no el que seguía— y apunta al anulado.
 */
final class VoidCashCollection
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly UnallocateFunds $liberar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        BeneficiaryInstallment $installment,
        string $reason,
        string $idempotencyKey,
        ?int $actorId = null,
    ): void {
        DB::transaction(function () use ($installment, $reason, $idempotencyKey, $actorId): void {
            /*
             * Las que todavía sostienen plata.
             *
             * `live()` filtra por tipo —excluye las filas `reversal`— pero
             * una imputación **revertida por completo** sigue siendo de
             * tipo `allocation`, así que pasa el filtro con saldo cero. Sin
             * descartarla, una cuota cobrada, anulada y vuelta a cobrar
             * parecía tener dos recepciones y el Action se negaba a anular
             * la segunda.
             */
            $imputaciones = FundingAllocation::query()
                ->live()
                ->with('fundReceipt')
                ->where('beneficiary_installment_id', $installment->id)
                ->lockForUpdate()
                ->get()
                ->filter(fn (FundingAllocation $a): bool => ! Decimal::equals(
                    $this->liberar->vigente($a),
                    '0',
                ))
                ->values();

            /*
             * Sin imputaciones en pie no hay plata que revertir, pero el
             * recibo puede seguir vigente: pasa cuando se libero todo
             * antes de anular. Antes eso era un callejon —«esta cuota no
             * tiene ningun cobro que anular» sobre un comprobante que si
             * existia y que nadie podia dar de baja—.
             */
            if ($imputaciones->isEmpty()) {
                $this->assertHayReciboQueAnular($installment);
                $this->anularElRecibo($installment, $reason, $actorId);

                $this->auditar->handle('cobro.anulado', $installment, null, null, [
                    'sin_reversiones' => 'la plata ya estaba liberada',
                    'motivo' => $reason,
                ], actorId: $actorId);

                return;
            }

            $recepcion = $this->recepcionDeMostrador($imputaciones);

            $this->assertSigueEnLaCaja($imputaciones);

            /*
             * Primero la imputación y después la recepción, en ese orden:
             * al revés, el pozo de no identificados quedaría en descubierto
             * en el medio de la transacción.
             */
            foreach ($imputaciones as $i => $imputacion) {
                $vigente = $this->liberar->vigente($imputacion);

                if (Decimal::equals($vigente, '0')) {
                    continue;
                }

                $this->liberar->handle(
                    allocation: $imputacion,
                    amount: $vigente,
                    idempotencyKey: $idempotencyKey.':imputacion:'.$i,
                    notes: $reason,
                    actorId: $actorId,
                );
            }

            $this->revertirLaRecepcion($recepcion, $idempotencyKey, $actorId, $reason);
            $this->anularElRecibo($installment, $reason, $actorId);

            $this->auditar->handle('cobro.anulado', $installment, null, null, [
                'fund_receipt_id' => $recepcion->id,
                'amount' => $recepcion->amount,
                'motivo' => $reason,
            ], actorId: $actorId);
        });
    }

    /**
     * Sin plata ni comprobante no hay nada que anular.
     *
     * @throws ValidationException
     */
    private function assertHayReciboQueAnular(BeneficiaryInstallment $installment): void
    {
        $tiene = Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->exists();

        if (! $tiene) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota no tiene ningún cobro ni recibo que anular.',
            ]);
        }
    }

    /**
     * La recepción por mostrador que financia esta cuota.
     *
     * @param  Collection<int, FundingAllocation>  $imputaciones
     *
     * @throws ValidationException
     */
    private function recepcionDeMostrador($imputaciones): FundReceipt
    {
        if ($imputaciones->isEmpty()) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota no tiene ningún cobro que anular.',
            ]);
        }

        $recepciones = $imputaciones
            ->map(fn (FundingAllocation $a): FundReceipt => $a->fundReceipt)
            ->unique('id');

        if ($recepciones->count() > 1) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota se financió con más de una recepción: '
                    .'hay que liberarlas una por una en vez de anular el cobro entero.',
            ]);
        }

        /** @var FundReceipt $recepcion */
        $recepcion = $recepciones->first();

        if ($recepcion->medium === PaymentMedium::Bank) {
            throw ValidationException::withMessages([
                'installmentId' => 'Ese dinero entró por el banco y el extracto lo confirma: '
                    .'existe. Lo que se puede hacer es liberarlo de esta cuota.',
            ]);
        }

        return $recepcion;
    }

    /**
     * Ese efectivo no puede haberse ido ya al banco.
     *
     * Anular afirma que el dinero **nunca entró**. Si el traslado lo llevó
     * a la cuenta del organismo, esa afirmación es falsa: el depósito
     * ocurrió, hay un ticket del cajero y el extracto lo va a confirmar.
     *
     * Sin esta guarda quedaba un traslado huérfano —apuntando a una
     * imputación revertida— y el libro afirmando a la vez que el dinero
     * nunca entró y que se depositó.
     *
     * @param  Collection<int, FundingAllocation>  $imputaciones
     *
     * @throws ValidationException
     */
    private function assertSigueEnLaCaja($imputaciones): void
    {
        $trasladada = CashToBankTransferItem::query()
            ->whereIn('funding_allocation_id', $imputaciones->pluck('id'))
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled->value)
            ->exists();

        if ($trasladada) {
            throw ValidationException::withMessages([
                'installmentId' => 'Ese efectivo ya se depositó en el banco: no se puede anular '
                    .'el cobro como si nunca hubiera entrado. Hay que revertir primero el traslado.',
            ]);
        }
    }

    /** El asiento inverso al de la recepción: ese dinero no está en la caja. */
    private function revertirLaRecepcion(
        FundReceipt $recepcion,
        string $idempotencyKey,
        ?int $actorId,
        string $reason,
    ): void {
        $importe = Decimal::scale($recepcion->amount);

        $origen = $recepcion->medium === PaymentMedium::Cheque
            ? LedgerAccount::ChequesInCustody
            : LedgerAccount::CashOnHand;

        $this->asentar->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: $idempotencyKey.':recepcion',
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, $importe)
                    ->from($recepcion->depositor_id)
                    ->onCashBox($recepcion->cash_box_id),
                EntryLine::credit($origen, $importe)
                    ->onCashBox($recepcion->cash_box_id),
            ],
            date: now(),
            cashBoxId: $recepcion->cash_box_id,
            description: $reason,
            actorId: $actorId,
            reversalOfId: $recepcion->financial_event_id,
            reversalReason: $reason,
        );
    }

    /**
     * El recibo se anula con su número.
     *
     * No se borra ni se reescribe: el papel existió y alguien lo tuvo en
     * la mano. Queda `voided` con su motivo, y el reemplazante lo va a
     * referenciar cuando se emita.
     */
    private function anularElRecibo(
        BeneficiaryInstallment $installment,
        string $reason,
        ?int $actorId,
    ): void {
        $recibo = Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($recibo === null) {
            return;
        }

        $recibo->forceFill([
            'status' => ReceiptStatus::Voided,
            'void_reason' => $reason,
            'voided_by' => $actorId,
            'voided_at' => now(),
        ])->save();
    }
}
