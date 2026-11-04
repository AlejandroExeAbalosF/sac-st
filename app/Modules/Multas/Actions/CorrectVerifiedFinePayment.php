<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentApplication;
use App\Modules\Multas\Models\FinePaymentCorrection;
use App\Modules\Multas\Support\FineAppeals;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige un pago bancario verificado: otra multa, otro crédito o los dos.
 *
 * Nada se edita: el pago anterior queda anulado, a la vista, con su recibo anulado
 * y su número, y nace otro pago que se verifica como cualquiera. Por eso el importe
 * no se escribe: un pago es por el crédito entero, hasta que el área responda P14.
 *
 * Todo pasa en una transacción: si algo falla, también al emitir el recibo nuevo,
 * el pago original sigue vigente.
 *
 * - **La presentación se conserva**, porque el comprobante se presentó ese día.
 * - **El término:** en la misma multa, el que ya tenía, porque su fecha de registro
 *   pudo corregirse después; en otra multa, se juzga contra esa.
 * - **Con recurso en trámite**, reemplaza su propio pago en la misma multa: es la
 *   única excepción al congelamiento, y la base la repite
 *   (`fine_payment_corrections_coherent`). Con veredicto, primero se revierte.
 *
 * Ninguna de estas reglas sale del relevamiento: están para confirmar con el área.
 */
final class CorrectVerifiedFinePayment
{
    public function __construct(
        private readonly VoidVerifiedFinePayment $anular,
        private readonly VerifyFineBankPayment $verificar,
        private readonly FineAppeals $recursos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        FinePayment $pago,
        Fine $destino,
        int $bankTransactionId,
        Reason $motivo,
        ?string $talonarioNumber,
        ?int $signedById,
        ?int $actorId,
    ): FinePaymentCorrection {
        return DB::transaction(function () use ($pago, $destino, $bankTransactionId, $motivo, $talonarioNumber, $signedById, $actorId): FinePaymentCorrection {
            // Multas en orden de id: dos correcciones cruzadas no se traban entre sí.
            $multas = Fine::query()->whereIn('id', [$pago->fine_id, $destino->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $origen = $multas->get($pago->fine_id);
            $destino = $multas->get($destino->id);

            if (! $origen instanceof Fine || ! $destino instanceof Fine) {
                throw ValidationException::withMessages(['fineId' => 'Esa multa ya no está disponible.']);
            }

            $pago = FinePayment::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            // Un segundo envío: la corrección ya está.
            $yaCorregido = FinePaymentCorrection::query()->where('corrected_payment_id', $pago->id)->first();

            if ($yaCorregido !== null) {
                return $yaCorregido;
            }

            if ($pago->status !== FinePaymentStatus::Applied || $pago->medium->isCounter()) {
                throw ValidationException::withMessages(['reason' => 'Se corrige un pago por el banco ya verificado.']);
            }

            $aplicacion = $pago->application()->firstOrFail();
            $creditoActual = $this->creditoDe($aplicacion);
            $mismaMulta = $origen->id === $destino->id;

            if ($mismaMulta && $creditoActual === $bankTransactionId) {
                throw ValidationException::withMessages(['bankTransactionId' => 'Elegí otra multa u otro crédito: así no cambia nada.']);
            }

            $this->assertDestino($pago, $origen, $destino, $mismaMulta);

            // Créditos en orden de id, por la misma razón que las multas.
            BankTransaction::query()->whereIn('id', [$creditoActual, $bankTransactionId])->orderBy('id')->lockForUpdate()->get();
            $credito = BankTransaction::query()->find($bankTransactionId);

            if ($credito === null) {
                throw ValidationException::withMessages(['bankTransactionId' => 'Ese crédito no está disponible.']);
            }

            $reciboAnterior = $pago->paymentReceipt()->with('receipt')->first()?->receipt->formatted_number;

            $this->anular->handle($pago, $motivo, $actorId, seReemplaza: true);

            $nuevo = FinePayment::query()->create([
                'fine_id' => $destino->id,
                'medium' => $pago->medium,
                'judicial_origin' => $pago->judicial_origin,
                'judicial_reference' => $pago->judicial_reference,
                'status' => FinePaymentStatus::Reported,
                'amount' => Decimal::scale($credito->amount),
                'presented_at' => $pago->presented_at,
                'presented_precision' => $pago->presented_precision,
                // La presentación es anterior a esta carga: como en la corrección de un informado, se dice por qué.
                'presentation_evidence' => $pago->presentation_evidence ?? 'Presentado con el pago verificado que este corrige.',
                'reported_at' => now(),
                'declared_paid_on' => $pago->declared_paid_on?->toDateString(),
                'payer_person_id' => $destino->person_id,
                'payer_name' => $destino->caption,
                'appeal_kind' => $pago->appeal_kind,
                'appeal_base_amount' => $pago->appeal_base_amount,
                'appeal_required_amount' => $pago->appeal_required_amount,
                'fine_appeal_id' => $pago->fine_appeal_id,
                'external_receipt_reference' => $pago->external_receipt_reference,
                'idempotency_key' => 'multa-pago-'.$pago->id.'-correccion',
                'created_by' => $actorId,
            ]);

            $this->verificar->handle(
                $nuevo,
                $bankTransactionId,
                $actorId,
                $talonarioNumber,
                $signedById,
                $mismaMulta ? [
                    'timeliness' => $aplicacion->timeliness,
                    'termEndsOn' => $aplicacion->term_ends_on === null ? null : CarbonImmutable::parse($aplicacion->term_ends_on),
                ] : null,
            );

            $correccion = FinePaymentCorrection::query()->create([
                'corrected_payment_id' => $pago->id,
                'new_payment_id' => $nuevo->id,
                'from_fine_id' => $origen->id,
                'to_fine_id' => $destino->id,
                'corrected_by' => $actorId,
                'corrected_at' => now(),
            ]);

            $this->auditar->handle('multa.pago-corregido', $destino, metadata: array_filter([
                'multa_anterior' => $origen->caption,
                'multa_nueva' => $destino->caption,
                'recibo_anterior' => $reciboAnterior,
                'recibo_nuevo' => $nuevo->paymentReceipt()->with('receipt')->first()?->receipt->formatted_number,
                'amount' => $nuevo->amount,
                ...$motivo->auditMetadata(),
            ]), actorId: $actorId);

            return $correccion;
        });
    }

    /** @throws ValidationException */
    private function assertDestino(FinePayment $pago, Fine $origen, Fine $destino, bool $mismaMulta): void
    {
        if ($pago->fine_appeal_id !== null) {
            $recurso = FineAppeal::query()->whereKey($pago->fine_appeal_id)->lockForUpdate()->firstOrFail();

            if ($recurso->status !== FineAppealStatus::Open) {
                throw ValidationException::withMessages(['reason' => 'El recurso de este pago ya tiene veredicto: primero revertí el veredicto.']);
            }

            if (! $mismaMulta) {
                throw ValidationException::withMessages(['fineId' => 'El pago de un recurso se corrige dentro de su multa: se puede cambiar el crédito, no la multa.']);
            }

            return;
        }

        $this->recursos->assertNotFrozen($destino, 'fineId');

        if ($mismaMulta) {
            return;
        }

        if ($destino->currency !== $origen->currency) {
            throw ValidationException::withMessages(['fineId' => 'La multa de destino está en otra moneda.']);
        }

        if (Decimal::equals((string) FineBalance::query()->whereKey($destino->id)->value('debt'), '0')) {
            throw ValidationException::withMessages(['fineId' => 'La multa de destino no tiene saldo pendiente: no hay deuda a la que aplicar el pago.']);
        }
    }

    private function creditoDe(FinePaymentApplication $aplicacion): int
    {
        return (int) DB::table('bank_transaction_allocations AS imputacion')
            ->join('fund_receipts AS recepcion', 'recepcion.financial_event_id', '=', 'imputacion.financial_event_id')
            ->where('recepcion.id', $aplicacion->fund_receipt_id)
            ->whereNull('imputacion.reversal_of_id')
            ->value('imputacion.bank_transaction_id');
    }
}
