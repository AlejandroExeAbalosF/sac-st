<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\ReleaseFundReceiptAllocations;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentVoid;
use App\Modules\Multas\Support\FineEventReversal;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un pago bancario verificado: se reconoció por error (P13, en parte).
 *
 * El crédito sigue en el banco, así que lo que se deshace es haberlo reconocido
 * como este pago: se revierten la aplicación y la recepción, la imputación recibe
 * su reversa y el crédito vuelve a quedar sin identificar. El recibo se anula con
 * su número. La deuda vuelve.
 *
 * El orden importa: `fund_receipts_keep_fine_applications` deja revertir la
 * recepción solo si la anulación ya está escrita.
 *
 * Con un recurso en trámite, anular solo lo hace caer; la corrección lo conserva,
 * porque reemplaza el pago en la misma transacción. Con veredicto, primero se
 * revierte: ese veredicto se dio sobre este dinero.
 */
final class VoidVerifiedFinePayment
{
    public function __construct(
        private readonly FineEventReversal $reversion,
        private readonly ReleaseFundReceiptAllocations $liberar,
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(FinePayment $pago, Reason $motivo, ?int $actorId, bool $seReemplaza = false): FinePayment
    {
        return DB::transaction(function () use ($pago, $motivo, $actorId, $seReemplaza): FinePayment {
            $multa = Fine::query()->whereKey($pago->fine_id)->lockForUpdate()->firstOrFail();
            $pago = FinePayment::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            // Un segundo envío del mismo formulario.
            if ($pago->status === FinePaymentStatus::Voided) {
                return $pago;
            }

            if ($pago->status !== FinePaymentStatus::Applied || $pago->medium->isCounter()) {
                throw ValidationException::withMessages([
                    'reason' => 'Se corrige un pago por el banco ya verificado.',
                ]);
            }

            $recurso = $pago->fine_appeal_id === null ? null : FineAppeal::query()->whereKey($pago->fine_appeal_id)->lockForUpdate()->first();

            if ($recurso !== null && $recurso->status !== FineAppealStatus::Open) {
                throw ValidationException::withMessages([
                    'reason' => 'El recurso de este pago ya tiene veredicto: primero revertí el veredicto.',
                ]);
            }

            $aplicacion = $pago->application()->firstOrFail();
            $recepcion = FundReceipt::query()->lockForUpdate()->findOrFail($aplicacion->fund_receipt_id);
            $antes = (string) FineBalance::query()->whereKey($multa->id)->value('debt');
            $credito = $this->credito($recepcion);

            $reversionAplicacion = $this->reversion->of($aplicacion->application_event_id, 'multa-pago-'.$pago->id.'-anulacion:aplicacion', $motivo, $actorId);
            $reversionRecepcion = $this->reversion->of($recepcion->financial_event_id, 'multa-pago-'.$pago->id.'-anulacion:recepcion', $motivo, $actorId);

            FinePaymentVoid::query()->create([
                'fine_payment_id' => $pago->id,
                'fine_id' => $multa->id,
                'reason_code' => $motivo->code,
                'reason' => $motivo->detail,
                'application_reversal_event_id' => $reversionAplicacion->id,
                'funds_exit_event_id' => $reversionRecepcion->id,
                'voided_by' => $actorId,
                'voided_at' => now(),
            ]);

            $this->liberar->handle($recepcion, $reversionRecepcion->id, $motivo->describe(), $actorId);

            $recepcion->forceFill([
                'reversal_event_id' => $reversionRecepcion->id,
                'reversed_at' => now(),
                'reversed_by' => $actorId,
                'reversal_reason' => $motivo->describe(),
            ])->save();

            $recibo = $pago->paymentReceipt()->firstOrFail()->receipt()->firstOrFail();
            $recibo->forceFill([
                'status' => ReceiptStatus::Voided,
                'void_reason' => $motivo->describe(),
                'voided_by' => $actorId,
                'voided_at' => now(),
            ])->save();

            $pago->forceFill(['status' => FinePaymentStatus::Voided])->save();

            $this->reclasificar->handle($multa, 'Anulación de un pago por el banco', $actorId);

            $this->auditar->handle(
                'multa.pago-bancario-anulado',
                $multa,
                before: ['debt' => $antes],
                after: ['debt' => (string) FineBalance::query()->whereKey($multa->id)->value('debt')],
                metadata: array_filter([
                    'medio' => $pago->medium->label(),
                    'amount' => $pago->amount,
                    'recibo' => $recibo->formatted_number,
                    'credito' => $credito,
                    ...$motivo->auditMetadata(),
                ]),
                actorId: $actorId,
            );

            if ($recurso !== null && ! $seReemplaza) {
                $recurso->forceFill(['status' => FineAppealStatus::Dropped])->save();

                $this->auditar->handle('multa.recurso-caido', $multa, metadata: [
                    'amount' => $pago->amount,
                    ...$motivo->auditMetadata(),
                ], actorId: $actorId);
            }

            return $pago;
        });
    }

    private function credito(FundReceipt $recepcion): ?string
    {
        $credito = DB::table('bank_transaction_allocations AS imputacion')
            ->join('bank_transactions AS movimiento', 'movimiento.id', '=', 'imputacion.bank_transaction_id')
            ->where('imputacion.financial_event_id', $recepcion->financial_event_id)
            ->whereNull('imputacion.reversal_of_id')
            ->selectRaw("to_char(movimiento.transaction_date, 'DD/MM/YYYY') AS fecha, COALESCE(movimiento.operation_id, movimiento.description) AS referencia")
            ->first();

        return $credito === null ? null : trim($credito->fecha.' '.$credito->referencia);
    }
}
