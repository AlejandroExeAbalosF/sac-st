<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\InverseEntry;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula el cobro de una cuota y su recibo.
 *
 * **Por banco, deshace la conciliación entera** con un solo motivo: libera la
 * cuota, revierte la recepción, anula el recibo y desvincula el comprobante. El
 * depósito existe, así que el movimiento vuelve a quedar libre en el extracto
 * para conciliarlo con la cuota que corresponda.
 *
 * **Por mostrador, el dinero nunca entró.**
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
        private readonly InverseEntry $inverso,
        private readonly ReverseFundReceipt $revertir,
        private readonly LinkDepositTicket $vinculo,
        private readonly AllocatableAmount $disponible,
    ) {}

    /** @throws ValidationException */
    public function handle(
        BeneficiaryInstallment $installment,
        Reason $motivo,
        string $idempotencyKey,
        ?int $actorId = null,
    ): void {
        DB::transaction(function () use ($installment, $motivo, $idempotencyKey, $actorId): void {
            $this->assertNoSeEntrego($installment);

            $reason = $motivo->describe();

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
                $this->anularElRecibo($installment, $motivo, $actorId);

                $this->auditar->handle('cobro.anulado', $installment, null, null, [
                    'sin_reversiones' => 'la plata ya estaba liberada',
                    ...$motivo->auditMetadata('motivo'),
                ], actorId: $actorId);

                return;
            }

            $recepciones = $imputaciones
                ->map(fn (FundingAllocation $a): FundReceipt => $a->fundReceipt)
                ->unique('id')
                ->values();

            if ($recepciones->every(fn (FundReceipt $r): bool => $r->medium === PaymentMedium::Bank)) {
                $this->deshacerLaConciliacion($installment, $imputaciones, $recepciones, $motivo, $idempotencyKey, $actorId);

                return;
            }

            $recepcion = $this->recepcionDeMostrador($imputaciones);

            $this->assertSigueEnLaCaja($imputaciones);
            $this->liberarLaCuota($imputaciones, $motivo, $idempotencyKey, $actorId);
            $this->assertSinOtraCuota($recepcion);
            $this->revertirLaRecepcion($recepcion, $idempotencyKey, $actorId, $motivo);
            $this->anularElRecibo($installment, $motivo, $actorId);

            $this->auditar->handle('cobro.anulado', $installment, null, null, [
                'fund_receipt_id' => $recepcion->id,
                'amount' => $recepcion->amount,
                ...$motivo->auditMetadata('motivo'),
            ], actorId: $actorId);
        });
    }

    /**
     * Deshace un cobro por banco: el movimiento vuelve a quedar libre.
     *
     * @param  Collection<int, FundingAllocation>  $imputaciones
     * @param  Collection<int, FundReceipt>  $recepciones
     *
     * @throws ValidationException
     */
    private function deshacerLaConciliacion(
        BeneficiaryInstallment $installment,
        Collection $imputaciones,
        Collection $recepciones,
        Reason $motivo,
        string $idempotencyKey,
        ?int $actorId,
    ): void {
        foreach ($recepciones as $recepcion) {
            $this->assertNoEsDelSistemaAnterior($recepcion);
        }

        $this->liberarLaCuota($imputaciones, $motivo, $idempotencyKey, $actorId);

        foreach ($recepciones as $recepcion) {
            $this->assertSinOtraCuota($recepcion);
            $this->revertir->handle($recepcion, $motivo, $idempotencyKey.':recepcion:'.$recepcion->id, $actorId);
        }

        $this->anularElRecibo($installment, $motivo, $actorId);
        $this->desvincularLosComprobantes($installment, $motivo, $actorId);

        $this->auditar->handle('cobro.anulado', $installment, null, null, [
            'fund_receipt_ids' => $recepciones->pluck('id')->all(),
            'conciliacion_deshecha' => true,
            ...$motivo->auditMetadata('motivo'),
        ], actorId: $actorId);
    }

    /**
     * Primero la imputación y después la recepción, en ese orden: al revés, el
     * pozo de no identificados quedaría en descubierto en el medio de la
     * transacción.
     *
     * @param  Collection<int, FundingAllocation>  $imputaciones
     */
    private function liberarLaCuota(Collection $imputaciones, Reason $motivo, string $idempotencyKey, ?int $actorId): void
    {
        foreach ($imputaciones as $i => $imputacion) {
            $this->liberar->handle(
                allocation: $imputacion,
                amount: $this->liberar->vigente($imputacion),
                idempotencyKey: $idempotencyKey.':imputacion:'.$i,
                motivo: $motivo,
                actorId: $actorId,
            );
        }
    }

    /**
     * El comprobante vuelve a la cola de espera. Si el depósito también
     * respalda otra recepción, el comprobante sigue siendo su papel y se queda.
     */
    private function desvincularLosComprobantes(BeneficiaryInstallment $installment, Reason $motivo, ?int $actorId): void
    {
        $comprobantes = DepositTicket::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->where('status', DepositTicketStatus::Matched)
            ->get();

        foreach ($comprobantes as $comprobante) {
            $movimiento = BankTransaction::query()->findOrFail($comprobante->bank_transaction_id);

            if (Decimal::equals($this->disponible->allocated($movimiento), '0')) {
                $this->vinculo->unlink($comprobante, $motivo->describe(), (int) $actorId, $motivo->code);
            }
        }
    }

    /** @throws ValidationException */
    private function assertNoEsDelSistemaAnterior(FundReceipt $recepcion): void
    {
        if ($recepcion->origin->isLegacy()) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esa plata es del sistema anterior: no se cobró acá. Lo que se puede hacer es liberarla de esta cuota.',
            ]);
        }
    }

    /**
     * Un cobro no se anula después de pagarle al beneficiario.
     *
     * Anular afirma que el dinero nunca entró, y el egreso afirma que salió:
     * quedarían los dos en pie, sin recibo que explique lo que se entregó.
     *
     * @throws ValidationException
     */
    private function assertNoSeEntrego(BeneficiaryInstallment $installment): void
    {
        $entregada = Disbursement::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->exists();

        if ($entregada) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota tiene un egreso registrado: no se puede anular su cobro ni su recibo.',
            ]);
        }
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

        // Anular el cobro saca efectivo del cajón: lo del sistema anterior no se cobró acá.
        $this->assertNoEsDelSistemaAnterior($recepcion);

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

    /**
     * Revertir saca toda la recepción: no puede estar financiando otra cuota.
     *
     * @throws ValidationException
     */
    private function assertSinOtraCuota(FundReceipt $recepcion): void
    {
        $otra = FundingAllocation::query()
            ->withRemainingBalance()
            ->where('fund_receipt_id', $recepcion->id)
            ->with(['installment', 'haber.expediente'])
            ->first();

        if ($otra !== null) {
            throw ValidationException::withMessages([
                'installmentId' => sprintf(
                    'Esa recepción también financia la cuota %s del expediente %s: hay que liberarla de ahí antes de anular este cobro.',
                    $otra->installment->installment_number,
                    $otra->haber->expediente->display_number,
                ),
            ]);
        }
    }

    /**
     * El asiento inverso al de la recepción, y la marca en la recepción.
     *
     * Sin la marca seguía ofreciendo un dinero que ya salió de los libros, y
     * volver a imputarla dejaba la cuota financiada con plata que no existe.
     */
    private function revertirLaRecepcion(
        FundReceipt $recepcion,
        string $idempotencyKey,
        ?int $actorId,
        Reason $motivo,
    ): void {
        $reason = $motivo->describe();

        // El cheque vuelve a salir de custodia y el efectivo de la caja, en
        // la moneda en que entraron: lo dice el asiento original.
        $evento = $this->asentar->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: $idempotencyKey.':recepcion',
            lines: $this->inverso->of($recepcion->financial_event_id),
            date: BusinessDate::today(),
            cashBoxId: $recepcion->cash_box_id,
            description: $reason,
            actorId: $actorId,
            reversalOfId: $recepcion->financial_event_id,
            reversalReason: $reason,
        );

        $recepcion->forceFill([
            'reversal_event_id' => $evento->id,
            'reversed_at' => now(),
            'reversed_by' => $actorId,
            'reversal_reason' => $reason,
            'reversal_reason_code' => $motivo->code->value,
        ])->save();
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
        Reason $motivo,
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
            'void_reason' => $motivo->describe(),
            'void_reason_code' => $motivo->code->value,
            'voided_by' => $actorId,
            'voided_at' => now(),
        ])->save();
    }
}
