<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\CashDepositCreditOptions;
use App\Modules\Multas\Actions\CancelFineDeposit;
use App\Modules\Multas\Actions\ConfirmFineDeposit;
use App\Modules\Multas\Actions\DepositFineCollection;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Http\Requests\DepositFineCollectionRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Multas\Support\FineDeposits;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo cobrado en la caja de Multas, camino al banco (M06).
 *
 * Depositar es de quien atiende la caja (`multas.depositar`); confirmar
 * contra el extracto, o cancelar, de quien concilia
 * (`multas.verificar-pagos`). Un traslado de otra caja no existe acá.
 */
final class FineDepositController extends Controller
{
    public function __construct(private readonly FineDeposits $depositos) {}

    /**
     * La pantalla para transcribir el ticket del cajero, con la foto al
     * lado: como en Haberes, el operador vuelve del banco con el papel y lo
     * copia mirándolo.
     */
    public function create(Request $request, FinePayment $payment, FineBankCredits $creditos): Response
    {
        $payment->load(['fine.fineCase', 'application.fundReceipt', 'paymentReceipt.receipt']);
        $deposito = $this->depositos->forPayments([$payment])[$payment->id] ?? null;
        $cuenta = $creditos->book()?->bank_account_id;

        // Solo lo que sigue en la caja; el Action lo rechazaría igual.
        abort_if($deposito?->place !== FineCollectionPlace::InCustody || $cuenta === null, 404);

        // El cheque vigente de la cadena: el que se deposita, aunque sea un reemplazo.
        $recepcion = $this->depositos->receiptOf($payment);
        $recibo = $payment->paymentReceipt?->receipt;

        return Inertia::render('multas/depositos/create', [
            'multa' => [
                'id' => $payment->fine_id,
                'caption' => $payment->fine->caption,
                'caseLabel' => $payment->fine->fineCase->label(),
            ],
            'cobro' => [
                'id' => $payment->id,
                'medium' => $payment->medium,
                'amount' => $payment->amount,
                'receivedOn' => $recepcion?->received_date->toDateString(),
                'receiptNumber' => $recibo->talonario_number ?? $recibo?->formatted_number,
                'chequeNumber' => $recepcion?->cheque_number,
                'chequeBank' => $recepcion?->cheque_bank,
            ],
            'cuenta' => BankAccount::query()->whereKey($cuenta)->value('label'),
            // A dónde volver: los pendientes de Multas, o la ficha de la multa.
            'desde' => $request->query('desde') === 'pendientes' ? 'pendientes' : 'multa',
        ]);
    }

    public function store(DepositFineCollectionRequest $request, FinePayment $payment, DepositFineCollection $depositar): RedirectResponse
    {
        /** @var UploadedFile $foto */
        $foto = $request->file('ticket');

        $traslado = $depositar->handle(
            pago: $payment,
            ticket: $request->ticket(),
            foto: $foto,
            idempotencyKey: (string) $request->validated('idempotencyKey'),
            actorId: $request->user()?->id,
        );

        Toast::success(
            'Depósito registrado por $ '.Decimal::format($traslado->amount).'.',
            'Queda en tránsito hasta que el extracto lo confirme.',
        );

        return $request->input('desde') === 'pendientes'
            ? to_route('multas.pendientes.index', ['pestana' => 'transito'])
            : to_route('multas.show', Fine::showParametersFor($payment->fine_id));
    }

    public function candidates(Request $request, CashToBankTransfer $transfer, CashDepositCreditOptions $opciones): JsonResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        return response()->json($opciones->for($transfer, $request->boolean('todos')));
    }

    public function confirm(Request $request, CashToBankTransfer $transfer, ConfirmFineDeposit $confirmar): RedirectResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        $validado = $request->validate([
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        $confirmar->handle(
            traslado: $transfer,
            credito: BankTransaction::query()->findOrFail((int) $validado['bankTransactionId']),
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('El extracto confirmó el depósito.', 'La plata ya está en el banco.');

        return back();
    }

    public function cancel(Request $request, CashToBankTransfer $transfer, CancelFineDeposit $cancelar): RedirectResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'reason.required' => 'Hay que decir por qué se cancela el depósito.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $cancelar->handle(
            traslado: $transfer,
            reason: (string) $validado['reason'],
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('Depósito cancelado.', 'El cobro vuelve a figurar en la caja.');

        return back();
    }
}
