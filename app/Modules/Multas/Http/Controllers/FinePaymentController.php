<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Http\Requests\CollectFinePaymentRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Multas\Pdf\FineReceiptPdf;
use App\Modules\Shared\Models\Receipt;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Cobrar una multa en la caja, y mirar o imprimir su recibo.
 *
 * El recibo de Multas no pasa por el visor de Haberes: tiene su propio
 * formulario y dice lo que decía la multa al emitirlo.
 */
final class FinePaymentController extends Controller
{
    public function store(CollectFinePaymentRequest $request, Fine $fine, CollectFinePayment $cobrar): RedirectResponse
    {
        $datos = $request->validated();
        /** @var array{number?: string|null, bank?: string|null, issueDate?: string|null}|null $cheque */
        $cheque = $datos['cheque'] ?? null;

        $pago = $cobrar->handle(
            multa: $fine,
            medio: FinePaymentMedium::from((string) $datos['medium']),
            amount: (string) $datos['amount'],
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $request->user()?->id,
            receivedOn: isset($datos['receivedOn']) ? CarbonImmutable::parse((string) $datos['receivedOn']) : null,
            cheque: $datos['medium'] === FinePaymentMedium::Cheque->value ? [
                'number' => (string) ($cheque['number'] ?? ''),
                'bank' => $cheque['bank'] ?? null,
                'issueDate' => $cheque['issueDate'] ?? null,
            ] : null,
            judicialReference: $datos['judicialReference'] ?? null,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
        );

        $recibo = $pago->paymentReceipt()->with('receipt')->first()?->receipt;

        Toast::success('Cobrado.', $recibo === null ? null : 'Se emitió el recibo '.$recibo->formatted_number.'.');

        return back();
    }

    /** El recibo en HTML, para el visor. */
    public function view(Request $request, Receipt $receipt, FineReceiptPdf $pdf): SymfonyResponse
    {
        return response($pdf->preview($this->comprobante($receipt), conFondo: ! $request->boolean('talonario'))->render());
    }

    /** El recibo en PDF, en línea para mandarlo a imprimir. */
    public function print(Request $request, Receipt $receipt, FineReceiptPdf $pdf): SymfonyResponse
    {
        $comprobante = $this->comprobante($receipt);

        return $pdf->render($comprobante, conFondo: ! $request->boolean('talonario'))->stream($pdf->filename($comprobante));
    }

    /** Solo los recibos que documentan un pago de una multa. */
    private function comprobante(Receipt $receipt): FinePaymentReceipt
    {
        return FinePaymentReceipt::query()->where('receipt_id', $receipt->id)->with(['receipt', 'payment'])->firstOrFail();
    }
}
