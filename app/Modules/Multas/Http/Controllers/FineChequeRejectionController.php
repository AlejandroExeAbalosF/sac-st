<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Enums\ChequeDisposition;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Banking\Support\RejectedChequeMovementOptions;
use App\Modules\Multas\Actions\CompleteFineChequeRejection;
use App\Modules\Multas\Actions\ConfirmFineChequeNeverCredited;
use App\Modules\Multas\Actions\RecordFineChequeMovements;
use App\Modules\Multas\Actions\RejectFineCheque;
use App\Modules\Multas\Actions\ReplaceFineCheque;
use App\Modules\Multas\Http\Requests\CompleteFineChequeRejectionRequest;
use App\Modules\Multas\Http\Requests\RejectFineChequeRequest;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineDeposits;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El cheque rechazado de un cobro de multa (M03).
 *
 * Registrar el rechazo, imputar lo que el extracto trae y completar el aviso
 * y la disposición es de quien concilia (`multas.gestionar-rechazos`);
 * recibir el reemplazo, de la caja (`multas.cobrar`). Un rechazo de otra caja
 * no existe acá.
 */
final class FineChequeRejectionController extends Controller
{
    public function __construct(private readonly FineDeposits $depositos) {}

    public function store(RejectFineChequeRequest $request, FinePayment $payment, RejectFineCheque $rechazar): RedirectResponse
    {
        $datos = $request->validated();

        $rechazo = $rechazar->handle(
            pago: $payment,
            rejectedOn: CarbonImmutable::parse((string) $datos['rejectedOn']),
            reason: (string) $datos['reason'],
            evidence: $request->file('evidence'),
            debit: isset($datos['debitTransactionId']) ? BankTransaction::query()->findOrFail((int) $datos['debitTransactionId']) : null,
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success(
            'Cheque rechazado.',
            'El pago sigue aplicado; queda pendiente de regularizar hasta que llegue su reemplazo. Banco: '
                .mb_strtolower($rechazo->bank_status->label()).'.',
        );

        return back();
    }

    /** Los débitos del extracto que podrían acompañar el rechazo de un cheque ya acreditado. */
    public function debitOptions(FinePayment $payment, RejectedChequeMovementOptions $opciones): JsonResponse
    {
        abort_unless(config('multas.cheque_bank_followup_enabled', false), 404);

        $cheque = $this->depositos->receiptOf($payment->loadMissing('application'));
        abort_if($cheque === null || $cheque->cash_box_id !== $this->depositos->cashBoxId(), 404);

        $traslado = CashToBankTransfer::query()
            ->whereHas('items', fn ($q) => $q->where('fund_receipt_id', $cheque->id))
            ->where('status', CashTransferStatus::BankConfirmed)
            ->first();

        return response()->json([
            'movements' => $traslado === null ? [] : $opciones->forTransfer($traslado, $cheque->amount, TransactionDirection::Debit),
        ]);
    }

    /** Los créditos o débitos del extracto que podrían ser los de este rechazo. */
    public function movementOptions(Request $request, ChequeRejection $rejection, RejectedChequeMovementOptions $opciones): JsonResponse
    {
        abort_unless(config('multas.cheque_bank_followup_enabled', false), 404);

        $this->assertDeMultas($rejection);

        return response()->json([
            'movements' => $opciones->for(
                $rejection,
                $request->query('sentido') === 'credit' ? TransactionDirection::Credit : TransactionDirection::Debit,
            ),
        ]);
    }

    public function movements(Request $request, ChequeRejection $rejection, RecordFineChequeMovements $registrar): RedirectResponse
    {
        $this->assertDeMultas($rejection);

        $validado = $request->validate([
            'creditTransactionId' => ['nullable', 'integer', 'exists:bank_transactions,id'],
            'debitTransactionId' => ['nullable', 'required_without:creditTransactionId', 'integer', 'exists:bank_transactions,id'],
            'idempotencyKey' => ['required', 'string', 'max:110'],
        ], [
            'debitTransactionId.required_without' => 'Elegí el movimiento del extracto.',
        ]);

        $registrar->handle(
            rechazo: $rejection,
            credit: isset($validado['creditTransactionId']) ? BankTransaction::query()->findOrFail((int) $validado['creditTransactionId']) : null,
            debit: isset($validado['debitTransactionId']) ? BankTransaction::query()->findOrFail((int) $validado['debitTransactionId']) : null,
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('Movimiento del extracto registrado.');

        return back();
    }

    public function neverCredited(Request $request, ChequeRejection $rejection, ConfirmFineChequeNeverCredited $confirmar): RedirectResponse
    {
        $this->assertDeMultas($rejection);

        $validado = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'note.required' => 'Anotá cómo se comprobó que el banco no lo acreditó.',
        ]);

        $confirmar->handle($rejection, (string) $validado['note'], $request->user()?->id);

        Toast::success('Seguimiento bancario cerrado.', 'El banco no acreditó el cheque.');

        return back();
    }

    public function replace(Request $request, ChequeRejection $rejection, ReplaceFineCheque $reemplazar): RedirectResponse
    {
        $this->assertDeMultas($rejection);

        $validado = $request->validate([
            'chequeNumber' => ['required', 'string', 'max:50'],
            'chequeBank' => ['nullable', 'string', 'max:120'],
            'chequeIssueDate' => ['nullable', 'date_format:Y-m-d'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'chequeNumber.required' => 'Anotá el número del cheque de reemplazo.',
        ]);

        $reemplazar->handle($rejection, [
            'number' => (string) $validado['chequeNumber'],
            'bank' => $validado['chequeBank'] ?? null,
            'issueDate' => $validado['chequeIssueDate'] ?? null,
        ], (string) $validado['idempotencyKey'], $request->user()?->id);

        Toast::success('Reemplazo recibido.', 'El cheque nuevo queda en custodia para depositarlo.');

        return back();
    }

    public function complete(CompleteFineChequeRejectionRequest $request, ChequeRejection $rejection, CompleteFineChequeRejection $completar): RedirectResponse
    {
        $this->assertDeMultas($rejection);
        $datos = $request->validated();

        $completar->handle(
            rechazo: $rejection,
            companyNotifiedOn: isset($datos['companyNotifiedOn']) ? CarbonImmutable::parse((string) $datos['companyNotifiedOn']) : null,
            notice: $request->file('notice'),
            disposition: isset($datos['disposition']) ? ChequeDisposition::from((string) $datos['disposition']) : null,
            dispositionProof: $request->file('dispositionProof'),
            actorId: $request->user()?->id,
        );

        Toast::success('Rechazo completado.');

        return back();
    }

    private function assertDeMultas(ChequeRejection $rechazo): void
    {
        abort_unless($this->depositos->ownsRejection($rechazo), 404);
    }
}
