<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Http\Requests\RecordFeeDepositTicketRequest;
use App\Modules\Aranceles\Http\Requests\StartFeeTransferRequest;
use App\Modules\Aranceles\Support\FeeDeposits;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\RecordTransferTicket;
use App\Modules\Banking\Actions\StartCashTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Banking\Support\CashDepositCreditOptions;
use App\Modules\Banking\Support\DepositableCash;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El efectivo de Aranceles camino al banco, en dos pasos (reglas A82 a A89a).
 *
 * El traspaso y el comprobante son de quien atiende la caja
 * (`aranceles.depositar`); acreditar contra el extracto, de quien concilia
 * (`aranceles.verificar-pagos`). Cancelar tiene su propio permiso. Un traslado de
 * otra caja no existe acá.
 */
final class FeeDepositController extends Controller
{
    /** Cuántos traspasos terminados se muestran. */
    private const HISTORIAL = 50;

    public function __construct(private readonly FeeDeposits $depositos) {}

    public function index(Request $request, CashBoxBankAccounts $cuentas, DepositableCash $efectivo): Response
    {
        $usuario = $request->user();
        $caja = $this->depositos->cashBoxId();
        $hoy = BusinessDate::today();

        $pestana = (string) $request->query('pestana', 'comprobante');

        return Inertia::render('aranceles/depositos', [
            'tab' => in_array($pestana, ['comprobante', 'transito', 'acreditados', 'cancelados'], true) ? $pestana : 'comprobante',
            'available' => $efectivo->maximum($caja, Currency::Ars, $hoy, $this->depositos->available($hoy)),
            'isOpen' => CashBookOpening::for($caja, Currency::Ars) !== null,
            'canOpenCash' => $usuario?->can('aranceles.caja.abrir') ?? false,
            'cuentas' => $cuentas->depositOptions($caja, Currency::Ars),
            'sinComprobante' => $this->depositos->inStatus(CashTransferStatus::AwaitingTicket),
            'enTransito' => $this->depositos->inStatus(CashTransferStatus::Deposited),
            'acreditados' => $this->depositos->inStatus(CashTransferStatus::BankConfirmed, self::HISTORIAL),
            'cancelados' => $this->depositos->inStatus(CashTransferStatus::Cancelled, self::HISTORIAL),
            'can' => [
                'deposit' => $usuario?->can('aranceles.depositar') ?? false,
                'adjustTransfer' => $usuario?->can('aranceles.ajustar-traspaso') ?? false,
                'confirm' => $usuario?->can('aranceles.verificar-pagos') ?? false,
                'cancel' => $usuario?->can('aranceles.cancelar-traspaso') ?? false,
                'viewAttachments' => $usuario?->can('adjuntos.ver') ?? false,
            ],
        ]);
    }

    /** Lo disponible a esa fecha y cómo se repartiría el importe, sin escribir nada. */
    public function preview(Request $request, DepositableCash $efectivo): JsonResponse
    {
        $datos = $request->validate([
            'transferDate' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
        ]);

        // Como al registrarlo: sin el permiso, la fecha es la del día.
        $fecha = isset($datos['transferDate']) && $request->user()?->can('aranceles.ajustar-traspaso')
            ? CarbonImmutable::parse((string) $datos['transferDate'])
            : BusinessDate::today();

        $disponible = $this->depositos->available($fecha);
        $maximo = $efectivo->maximum($this->depositos->cashBoxId(), Currency::Ars, $fecha, $disponible);
        $importe = (string) ($datos['amount'] ?? $maximo);
        $excede = Decimal::isNegative(Decimal::sub($maximo, $importe));

        return response()->json([
            'available' => $maximo,
            'exceeds' => $excede,
            'shares' => $this->depositos->shares($efectivo->distribute($disponible, $excede ? $maximo : $importe)),
        ]);
    }

    public function store(StartFeeTransferRequest $request, StartCashTransfer $iniciar, DepositableCash $efectivo): RedirectResponse
    {
        $datos = $request->validated();

        if (! $request->user()?->can('aranceles.ajustar-traspaso')) {
            $this->assertTakesEverything((string) $datos['amount'], (string) $datos['idempotencyKey'], $efectivo);
        }

        $traslado = $iniciar->handle(
            cashBoxId: $this->depositos->cashBoxId(),
            currency: Currency::Ars,
            importe: (string) $datos['amount'],
            fecha: $request->transferDate(),
            cuentaElegida: isset($datos['bankAccountId']) ? (int) $datos['bankAccountId'] : null,
            notas: $datos['notes'] ?? null,
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success(
            'Traspaso registrado por $ '.Decimal::format($traslado->amount).'.',
            'El efectivo salió de la caja. Falta cargar el comprobante del banco.',
        );

        return to_route('aranceles.depositos.index', ['pestana' => 'comprobante']);
    }

    /** Como en las otras cajas, se transcribe mirando la foto del papel. */
    public function createTicket(CashToBankTransfer $transfer): Response
    {
        abort_unless($this->depositos->owns($transfer) && $transfer->status === CashTransferStatus::AwaitingTicket, 404);

        $transfer->load('bankAccount');

        return Inertia::render('aranceles/comprobante-de-deposito', [
            'traslado' => [
                'id' => $transfer->id,
                'amount' => $transfer->amount,
                'transferDate' => $transfer->deposit_date->toDateString(),
                'accountLabel' => $transfer->bankAccount->label,
            ],
        ]);
    }

    public function storeTicket(RecordFeeDepositTicketRequest $request, CashToBankTransfer $transfer, RecordTransferTicket $cargar): RedirectResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        /** @var UploadedFile $foto */
        $foto = $request->file('ticket');

        $cargar->handle(
            $transfer,
            (string) $request->validated('amount'),
            $request->ticket(),
            $foto,
            (string) $request->validated('idempotencyKey'),
            $request->user()?->id,
        );

        Toast::success('Comprobante cargado.', 'Queda en tránsito hasta que el extracto lo confirme.');

        return to_route('aranceles.depositos.index', ['pestana' => 'transito']);
    }

    /**
     * Sin el permiso, el traspaso es de todo lo disponible. Se coteja lo que vio la
     * persona y no se completa solo: si entró un cobro mientras tanto, confirma el
     * importe nuevo. Un segundo envío del mismo formulario lo resuelve el Action.
     *
     * @throws ValidationException
     */
    private function assertTakesEverything(string $importe, string $idempotencyKey, DepositableCash $efectivo): void
    {
        if (CashToBankTransfer::query()->whereRelation('depositEvent', 'idempotency_key', $idempotencyKey)->exists()) {
            return;
        }

        $hoy = BusinessDate::today();
        $todo = $efectivo->maximum($this->depositos->cashBoxId(), Currency::Ars, $hoy, $this->depositos->available($hoy));

        if (! Decimal::equals($importe, $todo)) {
            throw ValidationException::withMessages([
                'amount' => 'Lo disponible ahora es $ '.Decimal::format($todo).'. Revisá el importe y volvé a confirmar.',
            ]);
        }
    }

    public function candidates(Request $request, CashToBankTransfer $transfer, CashDepositCreditOptions $opciones): JsonResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        return response()->json($opciones->for($transfer, $request->boolean('todos')));
    }

    public function confirm(Request $request, CashToBankTransfer $transfer, ConfirmCashDepositCredit $confirmar): RedirectResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        $validado = $request->validate([
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        $confirmar->handle(
            $transfer,
            BankTransaction::query()->findOrFail((int) $validado['bankTransactionId']),
            (string) $validado['idempotencyKey'],
            $request->user()?->id,
        );

        Toast::success('El extracto confirmó el depósito.', 'La plata ya está en el banco.');

        return back();
    }

    public function cancel(Request $request, CashToBankTransfer $transfer, CancelCashToBankTransfer $cancelar): RedirectResponse
    {
        abort_unless($this->depositos->owns($transfer), 404);

        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'reason.required' => 'Hay que decir por qué se cancela el traspaso.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $cancelar->handle($transfer, (string) $validado['reason'], (string) $validado['idempotencyKey'], $request->user()?->id);

        Toast::success('Traspaso cancelado.', 'El efectivo vuelve a figurar en la caja.');

        return back();
    }
}
