<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\AttachFineFile;
use App\Modules\Multas\Actions\DiscardFinePayment;
use App\Modules\Multas\Actions\RecordVerifiedFineBankPayment;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Actions\VerifyFineBankPayment;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Http\Requests\ReasonRequest;
use App\Modules\Multas\Http\Requests\RecordVerifiedFineBankPaymentRequest;
use App\Modules\Multas\Http\Requests\ReportFineBankPaymentRequest;
use App\Modules\Multas\Http\Requests\StoreFineAttachmentRequest;
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
