<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\CorrectFeeCharge;
use App\Modules\Aranceles\Actions\ReplaceFeeReceipt;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Data\FeeChargeLineData;
use App\Modules\Aranceles\Data\FeeChargeListItemData;
use App\Modules\Aranceles\Data\FeeTypeData;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Http\Requests\CollectFeeRequest;
use App\Modules\Aranceles\Http\Requests\CorrectFeeChargeRequest;
use App\Modules\Aranceles\Http\Requests\ReplaceFeeReceiptRequest;
use App\Modules\Aranceles\Http\Requests\VoidFeeCollectionRequest;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Pdf\FeeReceiptPdf;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use App\Support\Database\Like;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request, FeeChargeFunds $fondos): Response
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
            ->through(fn (FeeCharge $cobro): FeeChargeListItemData => $this->fila($cobro, $fondos));

        return Inertia::render('aranceles/cobros', [
            'charges' => $cobros,
            'filters' => ['q' => $buscado, 'cobro' => $cobroId],
            'openReceipt' => $request->integer('recibo') ?: null,
            'firmantes' => $this->firmantes(),
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

    public function editCorrection(Request $request, FeeCharge $feeCharge, FeeChargeFunds $fondos): Response
    {
        abort_unless($feeCharge->status === FeeChargeStatus::Paid, 404);

        return $this->formulario($request, $this->fila($feeCharge->load(['items', 'balance', 'chargeReceipts.receipt']), $fondos));
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
        $anular->handle($feeCharge, $request->reason(), $request->user()?->id);

        Toast::success('Cobro anulado.', 'El recibo quedó anulado y su número no se reutiliza.');

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
            'firmantes' => $this->firmantes(),
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

        Toast::success($mensaje, $recibo === null ? null : 'Se emitió el recibo '.$recibo->receipt->formatted_number.'.');

        return to_route('aranceles.cobros.index', ['recibo' => $recibo?->receipt_id]);
    }

    private function fila(FeeCharge $cobro, FeeChargeFunds $fondos): FeeChargeListItemData
    {
        $vigente = $cobro->chargeReceipts->first(fn (FeeChargeReceipt $c): bool => $c->receipt->status === ReceiptStatus::Issued);
        $ultimo = $vigente ?? $cobro->chargeReceipts->first();
        $papel = $ultimo?->snapshot;
        $importes = DB::table('fee_charge_item_amounts')->where('fee_charge_id', $cobro->id)->pluck('amount', 'fee_charge_item_id');

        $recepciones = FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->pluck('fund_receipt_id');

        return new FeeChargeListItemData(
            id: $cobro->id,
            payerName: $papel['payerName'] ?? $cobro->payer_name,
            // Un CUIT quitado en el reemplazo es nulo en el papel: no vuelve el original.
            payerCuit: $papel === null ? $cobro->payer_cuit : ($papel['payerCuit'] ?? null),
            medium: $cobro->medium,
            status: $cobro->status,
            paidOn: $cobro->paid_on?->toDateString(),
            pricingDate: $cobro->pricing_date->toDateString(),
            total: Decimal::scale((string) $cobro->balance?->total),
            lines: array_values($cobro->items->map(fn (FeeChargeItem $i): FeeChargeLineData => new FeeChargeLineData(
                feeTypeId: $i->fee_type_id,
                code: $i->code_snapshot,
                name: $i->name_snapshot,
                quantity: $i->quantity,
                baseAmount: $i->base_amount,
                calculatedAmount: $i->calculated_amount,
                amount: Decimal::scale((string) ($importes[$i->id] ?? $i->original_amount)),
                reasonCode: $i->change_reason_code,
                reason: $i->change_reason,
            ))->all()),
            receiptId: $ultimo?->receipt_id,
            receiptNumber: $ultimo?->receipt->formatted_number,
            talonarioNumber: $ultimo?->receipt->talonario_number,
            voidReason: $this->motivoDeAnulacion($cobro),
            deposited: $recepciones->contains(fn (int $id): bool => $fondos->isDeposited($id)),
        );
    }

    /** Se lee junto: «Error de carga: el importe tenía un cero de más». */
    private function motivoDeAnulacion(FeeCharge $cobro): ?string
    {
        if ($cobro->void_reason_code === null) {
            return null;
        }

        $codigo = FeeReasonCode::tryFrom($cobro->void_reason_code)?->label() ?? $cobro->void_reason_code;

        return $cobro->void_reason === null ? $codigo : $codigo.': '.$cobro->void_reason;
    }

    /**
     * Quienes pueden firmar el recibo, con su cargo. Opcional: sin firmante el
     * pie queda para firmar y sellar a mano (regla A92).
     *
     * @return list<array{id: int, name: string, position: string|null}>
     */
    private function firmantes(): array
    {
        return array_values(User::query()
            ->where('is_active', true)
            ->whereNotNull('position')
            ->orderBy('name')
            ->get(['id', 'name', 'position'])
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'position' => $u->position])
            ->all());
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
