<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\AttachFineFile;
use App\Modules\Multas\Actions\CorrectVerifiedFinePayment;
use App\Modules\Multas\Actions\DiscardFinePayment;
use App\Modules\Multas\Actions\RecordFinePaymentProof;
use App\Modules\Multas\Actions\RecordVerifiedFineBankPayment;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Actions\VerifyFineBankPayment;
use App\Modules\Multas\Actions\VoidVerifiedFinePayment;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Http\Requests\CorrectFineBankPaymentRequest;
use App\Modules\Multas\Http\Requests\ReasonRequest;
use App\Modules\Multas\Http\Requests\RecordVerifiedFineBankPaymentRequest;
use App\Modules\Multas\Http\Requests\ReportFineBankPaymentRequest;
use App\Modules\Multas\Http\Requests\StoreFineAttachmentRequest;
use App\Modules\Multas\Http\Requests\StoreFinePaymentProofRequest;
use App\Modules\Multas\Http\Requests\VerifyFineBankPaymentRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Los pagos de multas que llegan por el banco (M02).
 *
 * Informarlo es registrar que alguien presentó el comprobante: lo hace quien
 * atiende el mostrador o quien concilia. Verificarlo contra el extracto, o
 * descartarlo, es de quien concilia (`multas.verificar-pagos`).
 */
final class FineBankPaymentController extends Controller
{
    public function store(ReportFineBankPaymentRequest $request, Fine $fine, ReportFineBankPayment $informar): RedirectResponse
    {
        $this->puedeInformar($request);
        $datos = $request->validated();

        $informar->handle(
            multa: $fine,
            medio: FinePaymentMedium::from((string) $datos['medium']),
            amount: (string) $datos['amount'],
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $request->user()?->id,
            presentedOn: isset($datos['presentedOn']) ? CarbonImmutable::parse((string) $datos['presentedOn']) : null,
            presentationEvidence: $datos['presentationEvidence'] ?? null,
            declaredPaidOn: isset($datos['declaredPaidOn']) ? CarbonImmutable::parse((string) $datos['declaredPaidOn']) : null,
            judicialReference: $datos['judicialReference'] ?? null,
            replaces: isset($datos['replacesPaymentId']) ? FinePayment::query()->findOrFail((int) $datos['replacesPaymentId']) : null,
            correctionReason: $datos['correctionReason'] ?? null,
        );

        Toast::success(isset($datos['replacesPaymentId']) ? 'Pago corregido.' : 'Pago informado.', 'Queda por verificar contra el extracto: todavía no reduce el saldo.');

        return back();
    }

    public function storeVerified(
        RecordVerifiedFineBankPaymentRequest $request,
        Fine $fine,
        RecordVerifiedFineBankPayment $registrar,
    ): RedirectResponse {
        $datos = $request->validated();

        $pago = $registrar->handle(
            multa: $fine,
            medio: FinePaymentMedium::from((string) $datos['medium']),
            bankTransactionId: (int) $datos['bankTransactionId'],
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $request->user()?->id,
            judicialReference: $datos['judicialReference'] ?? null,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
        );

        $this->avisarRecibo($pago);

        return back();
    }

    public function verify(VerifyFineBankPaymentRequest $request, FinePayment $payment, VerifyFineBankPayment $verificar): RedirectResponse
    {
        $datos = $request->validated();

        $pago = $verificar->handle(
            pago: $payment,
            bankTransactionId: (int) $datos['bankTransactionId'],
            actorId: $request->user()?->id,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
        );

        $this->avisarRecibo($pago);

        return back();
    }

    public function discard(ReasonRequest $request, FinePayment $payment, DiscardFinePayment $descartar): RedirectResponse
    {
        $descartar->handle($payment, $request->reason(), $request->user()?->id);

        Toast::success('Pago descartado.');

        return back();
    }

    /** Pasa un pago verificado a otra multa o a otro crédito: el anterior queda anulado, a la vista. */
    public function correct(CorrectFineBankPaymentRequest $request, FinePayment $payment, CorrectVerifiedFinePayment $corregir): RedirectResponse
    {
        $datos = $request->validated();

        $correccion = $corregir->handle(
            pago: $payment,
            destino: Fine::query()->findOrFail((int) $datos['fineId']),
            bankTransactionId: (int) $datos['bankTransactionId'],
            motivo: $request->reason(),
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            actorId: $request->user()?->id,
        );

        $recibo = $correccion->newPayment()->firstOrFail()->paymentReceipt()->with('receipt')->first()?->receipt;

        Toast::success('Pago corregido.', $recibo === null ? null : 'El recibo anterior quedó anulado y se emitió el '.$recibo->formatted_number.'.');

        return back();
    }

    /** El pago no corresponde: se anula y su crédito queda libre, sin pago nuevo. */
    public function voidVerified(ReasonRequest $request, FinePayment $payment, VoidVerifiedFinePayment $anular): RedirectResponse
    {
        $anular->handle($payment, $request->reason(), $request->user()?->id);

        Toast::success('Pago anulado.', 'El recibo quedó anulado y el crédito volvió a estar sin identificar.');

        return back();
    }

    /** El comprobante del pago: la transferencia, la boleta, el recibo externo del recurso. */
    public function attach(StoreFineAttachmentRequest $request, FinePayment $payment, AttachFineFile $adjuntar): RedirectResponse
    {
        $this->puedeInformar($request);

        $archivo = $request->file('file');
        abort_unless($archivo instanceof UploadedFile, 422);

        $adjuntar->handle($payment, $archivo, $request->validated('title'), $request->user()?->id);

        Toast::success('Comprobante agregado al pago.');

        return back();
    }

    /** La fecha de pago del comprobante y su archivo, juntos o por separado. */
    public function proof(StoreFinePaymentProofRequest $request, FinePayment $payment, RecordFinePaymentProof $registrar): RedirectResponse
    {
        $this->puedeInformar($request);

        $fecha = $request->validated('declaredPaidOn');
        $archivo = $request->file('file');

        $registrar->handle(
            $payment,
            conFecha: $request->has('declaredPaidOn'),
            pagadoEl: is_string($fecha) ? CarbonImmutable::parse($fecha) : null,
            archivo: $archivo instanceof UploadedFile ? $archivo : null,
            title: $request->validated('title'),
            actorId: $request->user()?->id,
        );

        Toast::success('Comprobante del pago actualizado.');

        return back();
    }

    private function puedeInformar(Request $request): void
    {
        $usuario = $request->user();

        abort_unless(($usuario?->can('multas.cobrar') ?? false) || ($usuario?->can('multas.verificar-pagos') ?? false), 403);
    }

    private function avisarRecibo(FinePayment $pago): void
    {
        $recibo = $pago->paymentReceipt()->with('receipt')->first()?->receipt;

        Toast::success('Pago verificado.', $recibo === null ? null : 'Se emitió el recibo '.$recibo->formatted_number.'.');
    }
}
