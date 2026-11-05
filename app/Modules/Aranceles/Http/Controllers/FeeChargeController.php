<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\CorrectFeeCharge;
use App\Modules\Aranceles\Actions\ReplaceFeeReceipt;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Data\FeeChargeListItemData;
use App\Modules\Aranceles\Data\FeeTypeData;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Aranceles\Http\Requests\CollectFeeRequest;
use App\Modules\Aranceles\Http\Requests\CorrectFeeChargeRequest;
use App\Modules\Aranceles\Http\Requests\ReplaceFeeReceiptRequest;
use App\Modules\Aranceles\Http\Requests\VoidFeeCollectionRequest;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Pdf\FeeReceiptPdf;
use App\Modules\Aranceles\Support\FeeChargeRows;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use App\Support\Database\Like;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Cobrar aranceles, y anular, reemplazar o corregir lo cobrado. Valida y delega:
 * lo que mueve dinero o emite un comprobante vive en los Actions.
 */
final class FeeChargeController extends Controller
{
    public function __construct(private readonly FeeChargeRows $filas) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $buscado = trim((string) $request->query('q', ''));
        $cobroId = $request->integer('cobro') ?: null;

        $cobros = FeeCharge::query()
            ->with(['items', 'balance', 'chargeReceipts.receipt'])
            ->when($cobroId !== null, fn (Builder $q) => $q->whereKey($cobroId))
            ->when($buscado !== '', function (Builder $q) use ($buscado): void {
                $patron = '%'.Like::escape($buscado).'%';

                $q->where(fn (Builder $o) => $o
                    ->where('payer_name', 'ilike', $patron)
                    ->orWhereHas('chargeReceipts.receipt', fn (Builder $r) => $r
                        ->where('formatted_number', 'ilike', $patron)
                        ->orWhere('talonario_number', 'ilike', $patron)
                        ->orWhere('counterparty_name_snapshot', 'ilike', $patron)));
            })
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (FeeCharge $cobro): FeeChargeListItemData => $this->filas->row($cobro));

        return Inertia::render('aranceles/cobros', [
            'charges' => $cobros,
            'filters' => ['q' => $buscado, 'cobro' => $cobroId],
            'openReceipt' => $request->integer('recibo') ?: null,
            'firmantes' => $this->filas->signers(),
            'motivos' => FeeReasonAct::options(),
            'can' => [
                'collect' => $usuario?->can('aranceles.cobrar') ?? false,
                'void' => $usuario?->can('aranceles.anular') ?? false,
                'replace' => $usuario?->can('aranceles.reemplazar-recibos') ?? false,
                'correct' => $usuario?->can('aranceles.corregir') ?? false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->formulario($request, null);
    }

    public function editCorrection(Request $request, FeeCharge $feeCharge): Response
    {
        abort_unless($feeCharge->status === FeeChargeStatus::Paid, 404);

        return $this->formulario($request, $this->filas->row($feeCharge->load(['items', 'balance', 'chargeReceipts.receipt'])));
    }

    public function store(CollectFeeRequest $request, CollectFeeInCash $cobrar): RedirectResponse
    {
        $datos = $request->validated();

        $cobro = $cobrar->handle(
            (string) $datos['payerName'],
            $datos['payerCuit'] ?? null,
            array_values($datos['items']),
            (string) $datos['idempotencyKey'],
            $request->user()?->id,
            $this->fecha($datos),
            $datos['talonarioNumber'] ?? null,
            isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            $request->user()?->can('aranceles.modificar-importe') ?? false,
        );

        return $this->conRecibo($cobro, 'Cobrado.');
    }

    /** El recibo que saldría del cobro, sin escribir nada: como JSON comprueba los datos. */
    public function preview(CollectFeeRequest $request, CollectFeeInCash $cobrar, FeeReceiptPdf $pdf): SymfonyResponse
    {
        $datos = $request->validated();

        $comprobante = $cobrar->preview(
            (string) $datos['payerName'],
            $datos['payerCuit'] ?? null,
            array_values($datos['items']),
            $this->fecha($datos),
            $datos['talonarioNumber'] ?? null,
            isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            $request->user()?->can('aranceles.modificar-importe') ?? false,
        );

        if ($request->expectsJson()) {
            return response()->json(['numero' => $comprobante->receipt->formatted_number]);
        }

        return response($pdf->preview($comprobante)->render());
    }

    public function void(VoidFeeCollectionRequest $request, FeeCharge $feeCharge, VoidFeeCollection $anular): RedirectResponse
    {
        $pendiente = $feeCharge->status === FeeChargeStatus::Pending;

        $anular->handle($feeCharge, $request->reason(), $request->user()?->id);

        Toast::success('Cobro anulado.', $pendiente
            ? 'Si tenía un ingreso parcial, el crédito volvió a quedar libre.'
            : 'El recibo quedó anulado y su número no se reutiliza.');

        return back();
    }

    public function replace(ReplaceFeeReceiptRequest $request, FeeCharge $feeCharge, ReplaceFeeReceipt $reemplazar): RedirectResponse
    {
        $datos = $request->validated();

        /** @var array<int|string, string|null> $textos */
        $textos = $datos['wording'] ?? [];
        $redaccion = [];

        foreach ($textos as $renglon => $texto) {
            $redaccion[(int) $renglon] = (string) $texto;
        }

        $comprobante = $reemplazar->handle(
            $feeCharge,
            (string) $datos['payerName'],
            $datos['payerCuit'] ?? null,
            $redaccion,
            $request->reason(),
            isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            $request->user()?->id,
        );

        Toast::success('Recibo reemplazado.', 'El anterior queda a la vista, con su número.');

        return to_route('aranceles.cobros.index', ['cobro' => $feeCharge->id, 'recibo' => $comprobante->receipt_id]);
    }

    public function correct(CorrectFeeChargeRequest $request, FeeCharge $feeCharge, CorrectFeeCharge $corregir): RedirectResponse
    {
        $datos = $request->validated();

        $nuevo = $corregir->handle(
            $feeCharge,
            (string) $datos['payerName'],
            $datos['payerCuit'] ?? null,
            array_values($datos['items']),
            $request->reason(),
            isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            $request->user()?->id,
            $request->user()?->can('aranceles.modificar-importe') ?? false,
        );

        return $this->conRecibo($nuevo, 'Cobro corregido.');
    }

    public function viewReceipt(Receipt $receipt, FeeReceiptPdf $pdf): SymfonyResponse
    {
        return response($pdf->preview($this->comprobante($receipt))->render());
    }

    public function printReceipt(Receipt $receipt, FeeReceiptPdf $pdf): SymfonyResponse
    {
        $comprobante = $this->comprobante($receipt);

        return $pdf->render($comprobante)->stream($pdf->filename($comprobante));
    }

    private function formulario(Request $request, ?FeeChargeListItemData $corrige): Response
    {
        $hoy = BusinessDate::today();
        $caja = (int) CashBox::query()->active()->where('code', CashBox::ARANCELES)->firstOrFail()->id;
        $apertura = CashBookOpening::for($caja, Currency::Ars);

        return Inertia::render('aranceles/nuevo', [
            'feeTypes' => FeeType::query()
                ->where('is_active', true)
                ->with(['prices' => fn ($q) => $q->orderByDesc('valid_from')->orderByDesc('id')->with('creator:id,name')])
                ->orderBy('code')
                ->get()
                ->map(fn (FeeType $tipo): FeeTypeData => FeeTypeData::fromModel($tipo, $hoy))
                ->values()
                ->all(),
            'corrects' => $corrige,
            'isOpen' => $apertura !== null,
            'canChangeAmount' => $request->user()?->can('aranceles.modificar-importe') ?? false,
            'firmantes' => $this->filas->signers(),
            'motivos' => FeeReasonAct::options(),
            'idempotencyKey' => 'arancel-cobro:'.Str::ulid(),
        ]);
    }

    private function conRecibo(FeeCharge $cobro, string $mensaje): RedirectResponse
    {
        $recibo = FeeChargeReceipt::query()
            ->where('fee_charge_id', $cobro->id)
            ->whereRelation('receipt', 'status', ReceiptStatus::Issued)
            ->with('receipt')
            ->first();

        // Corregido por el banco con un importe mayor: sigue pendiente de lo que falta.
        if ($recibo === null) {
            Toast::success($mensaje, 'Los créditos no alcanzan: el cobro quedó pendiente de lo que falta.');

            return to_route('aranceles.pendientes.index');
        }

        Toast::success($mensaje, 'Se emitió el recibo '.$recibo->receipt->formatted_number.'.');

        return to_route('aranceles.cobros.index', ['recibo' => $recibo->receipt_id]);
    }

    /** @param array<string, mixed> $datos */
    private function fecha(array $datos): ?CarbonImmutable
    {
        return isset($datos['receivedOn']) ? CarbonImmutable::parse((string) $datos['receivedOn']) : null;
    }

    /** Solo los recibos que documentan un cobro de arancel. */
    private function comprobante(Receipt $receipt): FeeChargeReceipt
    {
        return FeeChargeReceipt::query()->where('receipt_id', $receipt->id)->with('receipt')->firstOrFail();
    }
}
