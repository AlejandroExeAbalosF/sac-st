<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Actions\AdjustFeeCharge;
use App\Modules\Aranceles\Actions\CreateFeeCharge;
use App\Modules\Aranceles\Actions\IdentifyFeeCredits;
use App\Modules\Aranceles\Actions\RecordVerifiedFeeBankPayment;
use App\Modules\Aranceles\Data\FeeChargeListItemData;
use App\Modules\Aranceles\Data\FeeTypeData;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Aranceles\Http\Requests\AdjustFeeChargeRequest;
use App\Modules\Aranceles\Http\Requests\CreateFeeChargeRequest;
use App\Modules\Aranceles\Http\Requests\IdentifyFeeCreditsRequest;
use App\Modules\Aranceles\Http\Requests\RecordVerifiedFeePaymentRequest;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Support\FeeBankCredits;
use App\Modules\Aranceles\Support\FeeChargeRows;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que espera un pago por el banco: el alta en ventanilla, la cola con lo que
 * falta, identificar los créditos y aceptar otro importe (reglas A50 a A63).
 *
 * Valida y delega: lo que mueve dinero o emite un comprobante vive en los Actions.
 */
final class FeePendingController extends Controller
{
    public function __construct(
        private readonly FeeChargeRows $filas,
        private readonly FeeBankCredits $creditos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        return Inertia::render('aranceles/pendientes', [
            'charges' => FeeCharge::query()
                ->where('status', FeeChargeStatus::Pending)
                ->with(['items', 'balance', 'chargeReceipts.receipt'])
                ->orderBy('id')
                ->paginate(25)
                ->through(fn (FeeCharge $cobro): FeeChargeListItemData => $this->filas->row($cobro)),
            'firmantes' => $this->filas->signers(),
            'motivos' => FeeReasonAct::options(),
            'can' => [
                'create' => $usuario?->can('aranceles.cobrar') ?? false,
                'identify' => $usuario?->can('aranceles.verificar-pagos') ?? false,
                'adjust' => $usuario?->can('aranceles.modificar-importe') ?? false,
                'void' => $usuario?->can('aranceles.anular') ?? false,
            ],
        ]);
    }

    public function store(CreateFeeChargeRequest $request, CreateFeeCharge $crear): RedirectResponse
    {
        $datos = $request->validated();

        $crear->handle(
            (string) $datos['payerName'],
            $datos['payerCuit'] ?? null,
            array_values($datos['items']),
            (string) $datos['idempotencyKey'],
            $request->user()?->id,
            $request->user()?->can('aranceles.modificar-importe') ?? false,
        );

        Toast::success('Cobro pendiente registrado.', 'El recibo sale cuando se identifique el depósito en el extracto.');

        return to_route('aranceles.pendientes.index');
    }

    /** Los créditos para elegir, por importe, operación, descripción o quien depositó. */
    public function credits(Request $request): JsonResponse
    {
        return response()->json($this->creditos->search((string) $request->query('q', '')));
    }

    public function identify(IdentifyFeeCreditsRequest $request, FeeCharge $feeCharge, IdentifyFeeCredits $identificar): RedirectResponse
    {
        $cobro = $identificar->handle(
            $feeCharge,
            $request->credits(),
            (string) $request->validated('idempotencyKey'),
            $this->firmante($request->validated('signedById')),
            $request->user()?->id,
        );

        return $this->segun($cobro);
    }

    public function adjust(AdjustFeeChargeRequest $request, FeeCharge $feeCharge, AdjustFeeCharge $ajustar): RedirectResponse
    {
        $cobro = $ajustar->handle(
            $feeCharge,
            $request->amounts(),
            $request->reason(),
            $this->firmante($request->validated('signedById')),
            $request->user()?->id,
        );

        // Ajustar no mueve dinero: si no se completó, solo cambió lo que falta.
        if ($cobro->status !== FeeChargeStatus::Paid) {
            $cobro->load('balance');

            Toast::success(
                'Importe ajustado.',
                'Faltan $ '.Decimal::format(Decimal::sub((string) $cobro->balance?->total, (string) $cobro->balance?->funded)).' para emitir el recibo.',
            );

            return to_route('aranceles.pendientes.index');
        }

        return $this->segun($cobro);
    }

    /** Un crédito de Banco › Movimientos: a qué pendiente corresponde, o qué cobro nace. */
    public function showCredit(Request $request, BankTransaction $transaction): Response|RedirectResponse
    {
        $apertura = $this->creditos->book();
        abort_if($apertura === null || ! in_array($transaction->bank_account_id, $this->creditos->accounts($apertura), true), 404);

        $lista = route('banco.movimientos.index', ['cuenta' => $transaction->bank_account_id]);

        if (! $this->creditos->identifiable($apertura)->whereKey($transaction->id)->exists()) {
            // El aviso del acto que lo identificó tiene que llegar a la lista.
            $request->session()->reflash();

            return redirect($lista);
        }

        $transaction->load('account');
        $usuario = $request->user();
        $hoy = BusinessDate::today();

        return Inertia::render('aranceles/identificar-credito', [
            'credit' => $this->creditos->data($transaction),
            'charges' => array_values(FeeCharge::query()
                ->where('status', FeeChargeStatus::Pending)
                ->with(['items', 'balance', 'chargeReceipts.receipt'])
                ->orderBy('id')
                ->get()
                ->map(fn (FeeCharge $cobro): FeeChargeListItemData => $this->filas->row($cobro))
                ->all()),
            // El precio de un cobro sin ventanilla es el de la fecha del crédito.
            'feeTypes' => FeeType::query()
                ->where('is_active', true)
                ->with(['prices' => fn ($q) => $q->orderByDesc('valid_from')->orderByDesc('id')->with('creator:id,name')])
                ->orderBy('code')
                ->get()
                ->map(fn (FeeType $tipo): FeeTypeData => FeeTypeData::fromModel($tipo, $hoy))
                ->values()
                ->all(),
            'canChangeAmount' => $usuario?->can('aranceles.modificar-importe') ?? false,
            'firmantes' => $this->filas->signers(),
            'motivos' => FeeReasonAct::options(),
            'backHref' => $lista,
        ]);
    }

    public function storeVerified(RecordVerifiedFeePaymentRequest $request, RecordVerifiedFeeBankPayment $registrar): RedirectResponse
    {
        $datos = $request->validated();

        $cobro = $registrar->handle(
            (string) $datos['payerName'],
            $datos['payerCuit'] ?? null,
            array_values($datos['items']),
            array_values(array_map('intval', $datos['credits'])),
            (string) $datos['idempotencyKey'],
            $this->firmante($datos['signedById'] ?? null),
            $request->user()?->id,
            $request->user()?->can('aranceles.modificar-importe') ?? false,
        );

        return $this->segun($cobro);
    }

    /** Pagado, se abre su recibo; todavía pendiente, se ve lo que falta. */
    private function segun(FeeCharge $cobro): RedirectResponse
    {
        if ($cobro->status !== FeeChargeStatus::Paid) {
            $cobro->load('balance');

            Toast::success(
                'Ingreso parcial registrado.',
                'Faltan $ '.Decimal::format(Decimal::sub((string) $cobro->balance?->total, (string) $cobro->balance?->funded)).' para emitir el recibo.',
            );

            return to_route('aranceles.pendientes.index');
        }

        $recibo = $cobro->chargeReceipts()
            ->whereRelation('receipt', 'status', ReceiptStatus::Issued)
            ->with('receipt')
            ->firstOrFail();

        Toast::success('Pago identificado.', 'Se emitió el recibo '.$recibo->receipt->formatted_number.'.');

        return to_route('aranceles.cobros.index', ['recibo' => $recibo->receipt_id]);
    }

    private function firmante(mixed $id): ?int
    {
        return $id === null ? null : (int) $id;
    }
}
