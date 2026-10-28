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

        $pago = $cobrar->handle($fine, ...[
            ...$this->cobro($datos),
            'idempotencyKey' => (string) $datos['idempotencyKey'],
            'actorId' => $request->user()?->id,
        ]);

        $recibo = $pago->paymentReceipt()->with('receipt')->first()?->receipt;

        Toast::success('Cobrado.', $recibo === null ? null : 'Se emitió el recibo '.$recibo->formatted_number.'.');

        return back();
    }

    /**
     * El recibo que saldría del cobro, antes de cobrar: el paso «Revisar
     * recibo» del modal. Recibe los mismos datos que el cobro y no escribe
     * nada.
     *
     * Pedida como JSON, solo comprueba los datos y devuelve el número que
     * le tocaría: el modal la consulta así antes de cambiar de paso, para
     * marcar los errores en el formulario y no dentro del visor.
     */
    public function preview(CollectFinePaymentRequest $request, Fine $fine, CollectFinePayment $cobrar, FineReceiptPdf $pdf): SymfonyResponse
    {
        $comprobante = $cobrar->preview($fine, ...$this->cobro($request->validated()));

        if ($request->expectsJson()) {
            return response()->json(['numero' => $comprobante->receipt->formatted_number]);
        }

        return response($pdf->preview($comprobante, conFondo: ! $request->boolean('talonario'))->render());
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

    /**
     * Los datos del cobro como los espera el Action, iguales para cobrar y
     * para previsualizar.
     *
     * @param  array<string, mixed>  $datos
     * @return array{medio: FinePaymentMedium, amount: string, receivedOn: CarbonImmutable|null, cheque: array{number: string, bank: string|null, issueDate: string|null}|null, judicialReference: string|null, talonarioNumber: string|null, signedById: int|null}
     */
    private function cobro(array $datos): array
    {
        /** @var array{number?: string|null, bank?: string|null, issueDate?: string|null}|null $cheque */
        $cheque = $datos['cheque'] ?? null;
        $medio = FinePaymentMedium::from((string) $datos['medium']);

        return [
            'medio' => $medio,
            'amount' => (string) $datos['amount'],
            'receivedOn' => isset($datos['receivedOn']) ? CarbonImmutable::parse((string) $datos['receivedOn']) : null,
            'cheque' => $medio === FinePaymentMedium::Cheque ? [
                'number' => (string) ($cheque['number'] ?? ''),
                'bank' => $cheque['bank'] ?? null,
                'issueDate' => $cheque['issueDate'] ?? null,
            ] : null,
            'judicialReference' => isset($datos['judicialReference']) ? (string) $datos['judicialReference'] : null,
            'talonarioNumber' => isset($datos['talonarioNumber']) ? (string) $datos['talonarioNumber'] : null,
            'signedById' => isset($datos['signedById']) ? (int) $datos['signedById'] : null,
        ];
    }

    /** Solo los recibos que documentan un pago de una multa. */
    private function comprobante(Receipt $receipt): FinePaymentReceipt
    {
        return FinePaymentReceipt::query()->where('receipt_id', $receipt->id)->with(['receipt', 'payment'])->firstOrFail();
    }
}
