<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\CashDepositCreditOptions;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\HaberesCashBox;
use App\Modules\Haberes\Support\IncomeEvidence;
use App\Modules\Haberes\Support\InstallmentDeposit;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use App\Support\Validation\ScannedDocument;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El efectivo que nadie retiró, camino al banco.
 *
 * Dos actos separados porque son dos hechos separados: el depósito lo hace
 * una persona y el banco lo confirma días después. Entre uno y otro el
 * dinero está en tránsito, que es un lugar real y no un estado de trámite.
 */
final class CashTransferController extends Controller
{
    /**
     * La pantalla para transcribir el ticket del cajero.
     *
     * Es una pantalla y no un diálogo por lo mismo que la carga del
     * comprobante del expediente: el operador vuelve del banco con un
     * papel y lo copia mirándolo, y la foto al lado del formulario no
     * entra en un modal sin apretarlo hasta volverlo incómodo.
     */
    public function create(BeneficiaryInstallment $installment): Response
    {
        $installment->loadMissing('haber.beneficiary', 'haber.expediente');

        $recibo = IncomeEvidence::of($installment);

        /*
         * Sin recibo no hay nada que trasladar, y el Action lo rechazaria
         * igual: se corta antes para no dibujar un formulario que no puede
         * enviarse.
         */
        abort_if($recibo === null, 404);

        $haber = $installment->haber;
        $expediente = $haber->expediente;

        // Lo mismo que va a asentar el Action: un cheque viaja entero.
        $items = app(InstallmentDeposit::class)->items($installment);

        return Inertia::render('haberes/traslados/create', [
            'cuota' => [
                'id' => $installment->id,
                'number' => $installment->installment_number,
                'amount' => InstallmentDeposit::total($items),
                // El resto de un cheque que la cuota tiene en parte.
                'carriedAmount' => InstallmentDeposit::carried($items),
                'concept' => $installment->description ?? $haber->concept,
                // Un recibo de papel no tiene número del sistema: va el del talonario.
                'receiptNumber' => $recibo->systemNumber() ?? $recibo->talonarioNumber(),
            ],
            'haber' => [
                'id' => $haber->id,
                'expedienteId' => $expediente->id,
                'beneficiaryName' => $haber->beneficiary->name,
            ],
            'expediente' => [
                'id' => $expediente->id,
                'displayNumber' => $expediente->display_number,
            ],
            // Solo las de la moneda del haber: el depósito no cambia de moneda.
            'accounts' => BankAccount::query()
                ->where('is_active', true)
                ->where('currency', $haber->currency)
                ->orderBy('label')
                ->get(['id', 'label', 'currency']),
        ]);
    }

    /**
     * Registra el depósito del efectivo de una cuota. La foto del ticket
     * es obligatoria: ver `DepositCashToBank`.
     */
    public function store(
        Request $request,
        BeneficiaryInstallment $installment,
        DepositCashToBank $depositar,
    ): RedirectResponse {
        $validado = $request->validate([
            'bankAccountId' => ['required', 'integer', 'exists:bank_accounts,id'],
            'depositDate' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'depositTime' => ['nullable', 'date_format:H:i'],
            'operationNumber' => ['nullable', 'string', 'max:40'],
            'terminal' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ticket' => ScannedDocument::rules(required: true),
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'ticket.required' => 'El ticket del cajero es obligatorio: es la prueba de que el efectivo salió de la caja.',
            'depositDate.before_or_equal' => 'La fecha del depósito no puede ser futura.',
            ...ScannedDocument::messages('ticket'),
        ]);

        $traslado = $depositar->handle(
            installment: $installment,
            ticket: [
                'bankAccountId' => (int) $validado['bankAccountId'],
                'depositDate' => CarbonImmutable::parse((string) $validado['depositDate']),
                'depositTime' => isset($validado['depositTime'])
                    ? $validado['depositTime'].':00'
                    : null,
                'operationNumber' => $validado['operationNumber'] ?? null,
                'terminal' => $validado['terminal'] ?? null,
                'notes' => $validado['notes'] ?? null,
            ],
            foto: $request->file('ticket'),
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        $haber = $installment->haber;

        return to_route('haberes.haber.show', [$haber->loadMissing('expediente')->expediente, $haber])->with(
            'status',
            'Depósito registrado por '.Decimal::format($traslado->amount)
            .'. Queda en tránsito hasta que el extracto lo confirme.',
        );
    }

    /**
     * Los créditos del extracto que podrían ser esta acreditación.
     *
     * Propone, no vincula. Confirmar es de una persona porque un crédito
     * mal atribuido cierra una ventana de tránsito que sigue abierta, y
     * deja un depósito perdido que nadie va a reclamar.
     */
    public function candidates(
        Request $request,
        CashToBankTransfer $transfer,
        CashDepositCreditOptions $opciones,
    ): JsonResponse {
        $this->assertDeHaberes($transfer);

        return response()->json($opciones->for($transfer, $request->boolean('todos')));
    }

    /**
     * Deshace un traslado que no ocurrió: el efectivo vuelve a la caja.
     *
     * Sólo lo que sigue en tránsito. Un traslado acreditado está
     * confirmado por el extracto, y decir que no ocurrió sería inventar.
     */
    public function cancel(
        Request $request,
        CashToBankTransfer $transfer,
        CancelCashToBankTransfer $cancelar,
    ): RedirectResponse {
        $this->assertDeHaberes($transfer);

        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'reason.required' => 'Hay que decir por qué se cancela el traslado.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $cancelar->handle(
            transfer: $transfer,
            reason: (string) $validado['reason'],
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('Traslado cancelado.', 'El efectivo vuelve a figurar en la caja.');

        return back();
    }

    /** Confirma la acreditación contra el crédito del extracto. */
    public function confirm(
        Request $request,
        CashToBankTransfer $transfer,
        ConfirmCashDepositCredit $confirmar,
    ): RedirectResponse {
        $this->assertDeHaberes($transfer);

        $validado = $request->validate([
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        /** @var BankTransaction $movimiento */
        $movimiento = BankTransaction::query()->findOrFail($validado['bankTransactionId']);

        $confirmar->handle(
            transfer: $transfer,
            transaction: $movimiento,
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('El extracto confirmó el depósito.', 'El dinero ya está en el banco.');

        return back();
    }

    /**
     * Un traslado de otra caja no existe para estas pantallas: lo opera la
     * suya, con sus permisos. Mismo criterio que recepciones y recibos.
     */
    private function assertDeHaberes(CashToBankTransfer $transfer): void
    {
        abort_unless(app(HaberesCashBox::class)->owns($transfer->cash_box_id), 404);
    }
}
