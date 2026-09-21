<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\ReverseFundReceipt;
use App\Modules\Haberes\Data\FundingAllocationListItemData;
use App\Modules\Haberes\Data\FundReceiptDetailData;
use App\Modules\Haberes\Data\FundReceiptListItemData;
use App\Modules\Haberes\Data\InstallmentCandidateData;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Http\Requests\AllocateFundsRequest;
use App\Modules\Haberes\Http\Requests\ReverseFundReceiptRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\ReceiptOrigin;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las recepciones: qué entró y de quién es.
 *
 * La pantalla vive en Haberes aunque `fund_receipts` sea de Ledger, y no
 * es una inconsistencia: lo que se muestra es la recepción **junto a las
 * cuotas que financia**, y las cuotas son el modelo jurídico de Haberes.
 * Ledger no puede verlas; Haberes ve los dos lados.
 */
final class FundReceiptController extends Controller
{
    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly ReceiptOrigin $origen,
    ) {}

    public function index(Request $request): Response
    {
        $busqueda = trim((string) $request->query('q', ''));
        $soloPendientes = $request->boolean('pendientes');

        $recepciones = FundReceipt::query()
            ->with(['depositor', 'cashBox'])
            /*
             * «Pendientes» es la cola de trabajo del área: lo que entró y
             * todavía no tiene dueño.
             *
             * Se filtra comparando contra la suma de asignaciones, no
             * contra una columna de saldo, porque esa columna no existe
             * (§5.1). Cuesta una subconsulta y a cambio nunca miente.
             */
            ->when($busqueda !== '', fn ($query) => $this->buscar($query, $busqueda))
            // Lo revertido no espera a nadie: salió de los libros.
            ->when($soloPendientes, fn ($query) => $query->whereNull('reversal_event_id'))
            ->when($soloPendientes, fn ($query) => $query->whereRaw(
                'fund_receipts.amount > (
                    SELECT COALESCE(SUM(CASE WHEN allocation_kind = ? THEN -amount ELSE amount END), 0)
                    FROM funding_allocations WHERE fund_receipt_id = fund_receipts.id
                )',
                [AllocationKind::Reversal->value],
            ))
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        /** @var list<int> $ids */
        $ids = $recepciones->getCollection()->map(fn (FundReceipt $r): int => $r->id)->all();
        $expedientes = $this->origen->expedientesFor($ids);

        $items = $recepciones->through(function (FundReceipt $receipt) use ($expedientes): FundReceiptListItemData {
            $expediente = $expedientes[$receipt->id] ?? null;

            return FundReceiptListItemData::fromModel(
                $receipt,
                $this->financiacion->unallocated($receipt),
                $expediente['number'] ?? null,
                $expediente['id'] ?? null,
            );
        });

        return Inertia::render('recepciones/index', [
            'receipts' => $items,
            'filters' => ['pendientes' => $soloPendientes, 'q' => $busqueda],
            'canAllocate' => $request->user()?->can('recepciones.asignar') ?? false,
        ]);
    }

    /**
     * Busca por expediente, depositante o importe.
     *
     * Son las tres preguntas que el área le hace a esta lista: «¿entró la
     * plata del 125958?», «¿qué depositó CIACSA?» y «¿de dónde salieron
     * estos $240.000?».
     *
     * El expediente no es una columna de la recepción: se llega por la
     * imputación bancaria y el comprobante que la trajo, la misma cadena
     * que usa el listado para mostrarlo. Por eso va como `EXISTS` y no
     * como `join` —un `join` multiplicaría filas y rompería el paginado—.
     *
     * @param  Builder<FundReceipt>  $query
     * @return Builder<FundReceipt>
     */
    private function buscar(Builder $query, string $busqueda): Builder
    {
        /*
         * `search_name` de las personas guarda el nombre en minúsculas y
         * sin acentos; el término se normaliza igual para que «garcia»
         * encuentre a «GARCÍA».
         */
        $texto = Str::lower(Str::ascii($busqueda));
        $importe = Decimal::parse($busqueda);

        return $query->where(function (Builder $recepcion) use ($busqueda, $texto, $importe): void {
            $recepcion
                ->whereExists(fn (QueryBuilder $sub) => $sub
                    ->from('bank_transaction_allocations as bta')
                    ->join('deposit_tickets as dt', 'dt.bank_transaction_id', '=', 'bta.bank_transaction_id')
                    ->join('expedientes as e', 'e.id', '=', 'dt.expediente_id')
                    ->whereColumn('bta.financial_event_id', 'fund_receipts.financial_event_id')
                    ->whereNull('bta.reversal_of_id')
                    ->where('e.display_number', 'ilike', '%'.$busqueda.'%'))
                ->orWhereHas('depositor', fn (Builder $persona) => $persona
                    ->where('search_name', 'like', '%'.$texto.'%'));

            // Solo cuando lo escrito es un importe: «240000» no tiene por
            // qué buscarse contra un nombre.
            if ($importe !== null) {
                $recepcion->orWhere('amount', $importe);
            }
        });
    }

    public function show(Request $request, FundReceipt $receipt): Response
    {
        $receipt->load(['depositor', 'cashBox', 'receivedBy', 'financialEvent']);

        $origen = $this->origen->for($receipt);

        return Inertia::render('recepciones/show', [
            'receipt' => FundReceiptDetailData::fromModel(
                $receipt,
                $this->financiacion->allocatedFrom($receipt),
                $this->financiacion->unallocated($receipt),
                bankTransactionId: $origen['bankTransactionId'] ?? null,
                bankTransactionDescription: $origen['bankTransactionDescription'] ?? null,
                bankAccountLabel: $origen['bankAccountLabel'] ?? null,
                expedienteId: $origen['expedienteId'] ?? null,
                expedienteNumber: $origen['expedienteNumber'] ?? null,
                employerName: $origen['employerName'] ?? null,
            ),
            'allocations' => $this->asignacionesDe($receipt),
            'candidates' => $this->cuotasCandidatas($receipt, $origen['expedienteId'] ?? null, $request),
            'searchedExpediente' => trim((string) $request->query('expediente', '')) ?: null,
            'idempotencyKey' => 'asignacion:'.$receipt->id.':'.Str::ulid(),
            'canAllocate' => $request->user()?->can('recepciones.asignar') ?? false,
            'canReverse' => $request->user()?->can('recepciones.revertir') ?? false,
        ]);
    }

    public function allocate(
        AllocateFundsRequest $request,
        FundReceipt $receipt,
        AllocateFundsToInstallment $asignar,
    ): RedirectResponse {
        $cuota = BeneficiaryInstallment::query()->findOrFail((int) $request->validated('installmentId'));

        $asignar->handle(
            receipt: $receipt,
            installment: $cuota,
            amount: (string) $request->validated('amount'),
            idempotencyKey: (string) $request->validated('idempotencyKey'),
            actorId: $request->user()?->id,
            notes: $request->validated('notes') === null ? null : (string) $request->validated('notes'),
        );

        $cuota->refresh();

        Toast::success('Asignado.', $this->financiacion->isFullyFunded($cuota)
            ? 'La cuota quedó completamente financiada.'
            : sprintf(
                'A la cuota le faltan $ %s.',
                Decimal::format($this->financiacion->remaining($cuota)),
            ));

        return back();
    }

    /**
     * Deshace la recepción: ese dinero nunca entró por esta vía.
     *
     * Vuelve al listado y no a la ficha: la recepción revertida ya no
     * tiene nada que ofrecer, y quedarse mirándola sugeriría que sí.
     */
    public function reverse(
        ReverseFundReceiptRequest $request,
        FundReceipt $receipt,
        ReverseFundReceipt $revertir,
    ): RedirectResponse {
        $revertir->handle(
            receipt: $receipt,
            reason: (string) $request->validated('reason'),
            idempotencyKey: (string) $request->validated('idempotencyKey'),
            actorId: $request->user()?->id,
        );

        Toast::success(
            'Recepción revertida.',
            'El dinero salió de los libros y el movimiento del extracto volvió a quedar sin imputar.',
        );

        return to_route('recepciones.index');
    }

    /**
     * Las cuotas a las que se puede imputar esta recepción.
     *
     * Si el ticket llegó a vincularse, el expediente ya se conoce y sus
     * cuotas se ofrecen sin que el operador busque nada — que es el caso
     * normal, porque el comprobante llega dentro del expediente. El
     * buscador existe para el resto: recepciones sin ticket, o un depósito
     * que resultó ser de otro expediente.
     *
     * @return list<InstallmentCandidateData>
     */
    private function cuotasCandidatas(FundReceipt $receipt, ?int $expedienteId, Request $request): array
    {
        $buscado = trim((string) $request->query('expediente', ''));

        if ($buscado !== '') {
            $expedienteId = Expediente::query()
                ->where('display_number', 'ilike', '%'.$buscado.'%')
                ->value('id');
        }

        if ($expedienteId === null) {
            return [];
        }

        $cuotas = BeneficiaryInstallment::query()
            ->with(['haber.beneficiary', 'haber.expediente'])
            ->whereNotIn('workflow_status', [
                InstallmentWorkflowStatus::Cancelled->value,
                InstallmentWorkflowStatus::Paid->value,
            ])
            ->whereHas('haber', fn ($q) => $q
                ->where('expediente_id', $expedienteId)
                ->whereNotIn('workflow_status', [
                    HaberWorkflowStatus::Cancelled->value,
                    HaberWorkflowStatus::Closed->value,
                ]))
            ->whereHas('haber.expediente', fn ($q) => $q
                ->where('status', '!=', ExpedienteStatus::Cancelled->value))
            ->orderBy('haber_id')
            ->orderBy('installment_number')
            ->get();

        /** @var list<int> $cuotaIds */
        $cuotaIds = $cuotas->map(fn (BeneficiaryInstallment $cuota): int => $cuota->id)->all();
        $asignado = $this->financiacion->allocatedForMany($cuotaIds);
        $medios = $this->financiacion->mediumForMany($cuotaIds);

        return array_values($cuotas
            ->map(function (BeneficiaryInstallment $cuota) use ($asignado, $medios): InstallmentCandidateData {
                $importeAsignado = $asignado[$cuota->id];
                $pendiente = Decimal::sub($cuota->importeEsperado(), $importeAsignado);

                return InstallmentCandidateData::fromModel(
                    $cuota,
                    $importeAsignado,
                    Decimal::isNegative($pendiente) ? '0.00' : $pendiente,
                    $medios[$cuota->id]?->value,
                );
            })
            ->all());
    }

    /**
     * @return list<FundingAllocationListItemData>
     */
    private function asignacionesDe(FundReceipt $receipt): array
    {
        return array_values(FundingAllocation::query()
            ->with(['installment.haber.beneficiary', 'installment.haber.expediente', 'allocatedBy'])
            ->where('fund_receipt_id', $receipt->id)
            ->orderBy('id')
            ->get()
            ->map(fn (FundingAllocation $a): FundingAllocationListItemData => FundingAllocationListItemData::fromModel($a))
            ->all());
    }
}
