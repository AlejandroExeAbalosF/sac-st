<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Multas\Data\FineBankCreditData;
use App\Modules\Multas\Data\FinePendingPaymentData;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Multas\Support\FineDeposits;
use App\Support\Money\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Identificar un crédito de la cuenta de Multas, desde Banco › Movimientos.
 *
 * Un crédito sin identificar es una de dos cosas (M08): el pago de una multa
 * —informado o no— o la acreditación de un depósito de la caja. La pantalla
 * ofrece las dos, con lo informado y lo depositado **por el mismo importe**
 * primero: un pago, un crédito (P14), y un depósito, un cobro entero (M06).
 *
 * Ya identificado, la dirección vuelve sola a la lista: es lo que pasa
 * después de confirmar, porque cada acción vuelve a la página anterior.
 */
final class FineBankCreditController extends Controller
{
    public function __construct(
        private readonly FineBankCredits $creditos,
        private readonly FineDeposits $depositos,
    ) {}

    public function show(Request $request, BankTransaction $transaction): Response|RedirectResponse
    {
        $apertura = $this->creditos->book();
        abort_if($apertura === null || $transaction->bank_account_id !== $apertura->bank_account_id, 404);

        $lista = route('banco.movimientos.index', ['cuenta' => $transaction->bank_account_id, 'estado' => 'pending']);

        if (! $this->creditos->pending($apertura)->whereKey($transaction->id)->exists()) {
            // El aviso del acto que lo identificó tiene que llegar a la lista.
            $request->session()->reflash();

            return redirect($lista);
        }

        $importe = Decimal::abs($transaction->amount);

        $informados = FinePayment::query()
            ->where('status', FinePaymentStatus::Reported)
            // Lo que llega por el banco: el cobro de mostrador no se informa.
            ->whereNotIn('medium', [FinePaymentMedium::Cash, FinePaymentMedium::Cheque])
            ->where('amount', $importe)
            ->with(['fine.fineCase', 'creator:id,name'])
            ->orderBy('presented_at')
            ->orderBy('id')
            ->get();

        $enTransito = $this->depositos->counterPayments(FineCollectionPlace::InTransit)
            ->where('fine_payments.amount', $importe)
            ->with(['fine.fineCase', 'application.fundReceipt', 'paymentReceipt.receipt', 'creator:id,name'])
            ->orderBy('id')
            ->get();
        $depositos = $this->depositos->forPayments($enTransito);

        return Inertia::render('multas/identificar-credito', [
            'credit' => FineBankCreditData::fromModel($transaction),
            'reported' => array_values($informados
                ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago))
                ->all()),
            'deposits' => array_values($enTransito
                ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago, $depositos[$pago->id] ?? null))
                ->all()),
            'firmantes' => User::query()
                ->where('is_active', true)
                ->whereNotNull('position')
                ->orderBy('name')
                ->get(['id', 'name', 'position']),
            'backHref' => $lista,
        ]);
    }
}
