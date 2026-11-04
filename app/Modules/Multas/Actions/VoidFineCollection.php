<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentVoid;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Multas\Support\FineEventReversal;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un cobro de la caja (P13, en parte): un cobro cuyo dinero nunca entró, o
 * un cheque rechazado que se va a pagar por otro medio.
 *
 * **Sin rechazo**, solo mientras siga en la caja: lo depositado sí entró. Revierte
 * la aplicación y después la recepción.
 *
 * **Con rechazo**, solo si el último cheque de la cadena está rechazado, sin
 * reemplazo y sin acreditación registrada. Revierte la aplicación y saca lo que
 * había que regularizar: el papel ya salió con el rechazo, así que la recepción
 * no se revierte. Anular no afirma nada sobre el banco: lo que el extracto traiga
 * después se registra sobre el rechazo, sin reactivar el pago.
 *
 * En los dos casos el recibo se anula con su número, que no se reutiliza, y la
 * hoja del talonario sigue usada.
 *
 * El orden importa: `fund_receipts_keep_fine_applications` deja revertir la
 * recepción solo si la anulación ya está escrita. El bloqueo de la recepción es
 * el mismo que toma un depósito, y el del último cheque el que toma un reemplazo:
 * el segundo espera y encuentra lo que hizo el primero.
 *
 * Anular el cobro de un recurso en trámite lo hace caer. Si el recurso tiene
 * veredicto, primero se revierte: ese veredicto se dio sobre este dinero.
 */
final class VoidFineCollection
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly FineEventReversal $reversion,
        private readonly FineDeposits $depositos,
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(FinePayment $pago, Reason $motivo, ?int $actorId): FinePayment
    {
        return DB::transaction(function () use ($pago, $motivo, $actorId): FinePayment {
            $multa = Fine::query()->whereKey($pago->fine_id)->lockForUpdate()->firstOrFail();
            $pago = FinePayment::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            // Un segundo envío del mismo formulario.
            if ($pago->status === FinePaymentStatus::Voided) {
                return $pago;
            }

            if ($pago->status !== FinePaymentStatus::Applied || ! $pago->medium->isCounter()) {
                throw ValidationException::withMessages([
                    'reason' => 'Se anula un cobro de la caja, en efectivo o con cheque, ya aplicado.',
                ]);
            }

            $recurso = $pago->fine_appeal_id === null ? null : FineAppeal::query()->whereKey($pago->fine_appeal_id)->lockForUpdate()->first();

            if ($recurso !== null && $recurso->status !== FineAppealStatus::Open) {
                throw ValidationException::withMessages([
                    'reason' => 'El recurso de este cobro ya tiene veredicto: primero revertí el veredicto.',
                ]);
            }

            $aplicacion = $pago->application()->firstOrFail();
            $recepcion = FundReceipt::query()->lockForUpdate()->findOrFail($aplicacion->fund_receipt_id);
            $conRechazo = $this->depositos->hasRejections($recepcion);

            $this->assertAnulable($recepcion, $conRechazo, $motivo);

            $antes = (string) FineBalance::query()->whereKey($multa->id)->value('debt');

            $reversionAplicacion = $this->reversion->of($aplicacion->application_event_id, 'multa-pago-'.$pago->id.'-anulacion:aplicacion', $motivo, $actorId);
            $salida = $conRechazo
                ? $this->sacarLoARegularizar($pago, $recepcion, $motivo, $actorId)
                : $this->reversion->of($recepcion->financial_event_id, 'multa-pago-'.$pago->id.'-anulacion:recepcion', $motivo, $actorId);

            FinePaymentVoid::query()->create([
                'fine_payment_id' => $pago->id,
                'fine_id' => $multa->id,
                'reason_code' => $motivo->code,
                'reason' => $motivo->detail,
                'application_reversal_event_id' => $reversionAplicacion->id,
                'funds_exit_event_id' => $salida->id,
                'voided_by' => $actorId,
                'voided_at' => now(),
            ]);

            if (! $conRechazo) {
                $recepcion->forceFill([
                    'reversal_event_id' => $salida->id,
                    'reversed_at' => now(),
                    'reversed_by' => $actorId,
                    'reversal_reason' => $motivo->describe(),
                ])->save();
            }

            $recibo = $pago->paymentReceipt()->firstOrFail()->receipt()->firstOrFail();
            $recibo->forceFill([
                'status' => ReceiptStatus::Voided,
                'void_reason' => $motivo->describe(),
                'voided_by' => $actorId,
                'voided_at' => now(),
            ])->save();

            $pago->forceFill(['status' => FinePaymentStatus::Voided])->save();

            $this->reclasificar->handle($multa, 'Anulación de un cobro de la caja', $actorId);

            $this->auditar->handle(
                'multa.cobro-anulado',
                $multa,
                before: ['debt' => $antes],
                after: ['debt' => (string) FineBalance::query()->whereKey($multa->id)->value('debt')],
                metadata: [
                    'medio' => $pago->medium->label(),
                    'amount' => $pago->amount,
                    'recibo' => $recibo->formatted_number,
                    ...$motivo->auditMetadata(),
                ],
                actorId: $actorId,
            );

            if ($recurso !== null) {
                $recurso->forceFill(['status' => FineAppealStatus::Dropped])->save();

                $this->auditar->handle('multa.recurso-caido', $multa, metadata: [
                    'amount' => $pago->amount,
                    ...$motivo->auditMetadata(),
                ], actorId: $actorId);
            }

            return $pago;
        });
    }

    /** @throws ValidationException */
    private function assertAnulable(FundReceipt $recepcion, bool $conRechazo, Reason $motivo): void
    {
        if ($conRechazo) {
            $impedimento = $this->depositos->rejectionVoidBlock($recepcion);

            if ($impedimento !== null) {
                throw ValidationException::withMessages(['reason' => $impedimento]);
            }

            return;
        }

        if (! $this->depositos->isAtTheCounter($recepcion)) {
            throw ValidationException::withMessages([
                'reason' => 'Ese cobro ya no está en la caja: se depositó, y no se puede afirmar que el dinero nunca entró.',
            ]);
        }

        if ($motivo->code === FineReasonCode::MediumChange) {
            throw ValidationException::withMessages([
                'reasonCode' => 'Pagar por otro medio es el motivo de un cheque rechazado: este cobro no tiene rechazo.',
            ]);
        }
    }

    /** Lo que la empresa tenía que regularizar deja de deberse: el pago ya no existe. */
    private function sacarLoARegularizar(FinePayment $pago, FundReceipt $recepcion, Reason $motivo, ?int $actorId): FinancialEvent
    {
        $moneda = Currency::from($recepcion->currency);

        return $this->asentar->handle(
            type: FinancialEventType::RejectedChequeVoided,
            idempotencyKey: 'multa-pago-'.$pago->id.'-anulacion:rechazado',
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, $recepcion->amount)->in($moneda)->onCashBox($recepcion->cash_box_id),
                EntryLine::credit(LedgerAccount::RejectedCheques, $recepcion->amount)->in($moneda)->onCashBox($recepcion->cash_box_id),
            ],
            date: BusinessDate::today(),
            cashBoxId: $recepcion->cash_box_id,
            description: 'Anulación del cheque rechazado n.º '.$recepcion->cheque_number.': '.$motivo->describe(),
            actorId: $actorId,
        );
    }
}
