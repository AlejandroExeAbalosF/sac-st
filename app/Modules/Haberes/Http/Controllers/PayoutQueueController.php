<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Data\CounterPayoutRowData;
use App\Modules\Haberes\Data\UnconfirmedTransferRowData;
use App\Modules\Haberes\Http\Requests\PlanillaFilterRequest;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Pdf\AllInstallmentsSheet;
use App\Modules\Haberes\Pdf\CounterPayoutSheet;
use App\Modules\Haberes\Pdf\UnconfirmedTransferSheet;
use App\Modules\Haberes\Support\CounterPayoutQueue;
use App\Modules\Haberes\Support\PlanillaFilters;
use App\Modules\Haberes\Support\PlanillaInstallments;
use App\Modules\Haberes\Support\TransferStage;
use App\Support\Excel\SpreadsheetWriter;
use App\Support\Money\Decimal;
use App\Support\Pdf\Worksheet;
use Carbon\CarbonImmutable;
use Generator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Lo que el sistema le debe a alguien, o le debe al libro.
 *
 * Dos colas de trabajo y un inventario completo. Las colas son las dos
 * mitades del dinero saliendo y las decide el mismo `DisbursementEligibility`:
 *
 * | Solapa | Qué espera | De quién es el trabajo |
 * |---|---|---|
 * | `mostrador` | nada: se paga ya | el cajero |
 * | `transferencias` | el débito, el informe o el cotejo | el contador |
 *
 * **Ninguna vista escribe nada.** Es una pantalla de lectura que
 * lleva a la cuota, que es donde viven los actos. Por eso tampoco hay
 * Actions acá: no hay nada que accionar, hay algo que mirar y una hoja que
 * imprimir o descargar.
 *
 * Los depósitos esperando acreditación son la tercera cola del circuito,
 * pero son plata **entrando** y ya tienen su pantalla en `/depositos`. Su
 * planilla se emite desde ahí, no desde acá.
 */
final class PayoutQueueController extends Controller
{
    private const MOSTRADOR = 'mostrador';

    private const TRANSFERENCIAS = 'transferencias';

    private const TODAS = 'todas';

    public function __construct(
        private readonly CounterPayoutQueue $mostrador,
        private readonly TransferStage $etapa,
        private readonly PlanillaInstallments $planillas,
    ) {}

    public function index(PlanillaFilterRequest $request): Response
    {
        /*
         * Las dos listas van siempre, no solo la de la solapa abierta: el
         * número de la otra pestaña tiene que ser cierto, y para saberlo hay
         * que resolverla igual. Las dos tienen tope, así que el peor caso
         * está acotado.
         */
        $filtros = $request->filters();
        [$mostrador, $transferencias] = $this->colasFiltradas($filtros);
        $cola = $this->cola($request);
        $todas = $cola === self::TODAS ? $this->planillas->page($filtros, $request->integer('page', 1)) : null;

        return Inertia::render('haberes/planillas', [
            'cola' => $cola,
            'counter' => $mostrador,
            'transfers' => $transferencias,
            'all' => $todas,
            'allCount' => $todas?->total() ?? $this->planillas->count($filtros),
            'filters' => $filtros->toArray(),
            'stageOptions' => array_map(
                fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
                array_keys(PlanillaFilters::STAGES),
                array_values(PlanillaFilters::STAGES),
            ),
            /*
             * Los totales se suman acá y bajan como cadena. El navegador no
             * suma dinero: `Number` sobre importes es un `float` sobre plata
             * de terceros, que es lo que el modelo prohíbe de punta a punta.
             * Y de paso, el número de la pantalla y el de la planilla salen
             * de la misma suma.
             */
            'counterTotal' => $this->total($mostrador),
            'transfersTotal' => $this->total($transferencias),
        ]);
    }

    /**
     * La vista abierta, en papel o Excel.
     *
     * Una ruta y no dos porque es la misma acción sobre la misma pantalla:
     * exportar la selección actual. Cuál sale lo dice el
     * mismo parámetro que elige la solapa.
     */
    public function sheet(
        PlanillaFilterRequest $request,
        Worksheet $molde,
        CounterPayoutSheet $planillaMostrador,
        UnconfirmedTransferSheet $planillaTransferencias,
        AllInstallmentsSheet $planillaTodas,
    ): SymfonyResponse {
        $ahora = CarbonImmutable::now();
        $quien = $request->user()?->name;
        $filtros = $request->filters();
        [$mostrador, $transferencias] = $this->colasFiltradas($filtros);
        $cola = $this->cola($request);
        $aplicados = $filtros->forQueue($cola);
        $marcables = [];

        foreach ([...$mostrador, ...$transferencias] as $fila) {
            $marcables[$fila->installmentId] = true;
        }

        if ($cola === self::TODAS && $request->query('formato') === 'xlsx') {
            $columns = $planillaTodas->build([], [], $ahora)->columns;
            $rows = (function () use ($aplicados, $marcables, $planillaTodas): Generator {
                foreach ($this->planillas->rows($aplicados) as $fila) {
                    yield $planillaTodas->row($fila, isset($marcables[$fila->installmentId]));
                }
            })();

            return SpreadsheetWriter::download(
                'planilla-todas-las-cuotas-'.$ahora->format('Y-m-d').'.xlsx',
                array_map(fn ($column): string => $column->label, $columns),
                $rows,
                $aplicados->summary(),
            );
        }

        if ($cola === self::TODAS) {
            $hoja = $planillaTodas->build(
                iterator_to_array($this->planillas->rows($aplicados), false),
                $marcables,
                $ahora,
                $quien,
                $aplicados->summary(),
            );
        } elseif ($cola === self::TRANSFERENCIAS) {
            $hoja = $planillaTransferencias->build($transferencias, $this->total($transferencias), $ahora, $quien, $aplicados->summary());
        } else {
            $hoja = $planillaMostrador->build($mostrador, $this->total($mostrador), $ahora, $quien, $aplicados->summary());
        }

        if ($request->query('formato') === 'xlsx') {
            return SpreadsheetWriter::download(
                $hoja->slug.'-'.$ahora->format('Y-m-d').'.xlsx',
                array_map(fn ($column): string => $column->label, $hoja->columns),
                $hoja->rows,
                $aplicados->summary(),
            );
        }

        // La acción «Planilla» mantiene la vista en línea; «PDF» la descarga.
        $pdf = $molde->render($hoja);

        return $request->boolean('descargar')
            ? $pdf->download($hoja->filename())
            : $pdf->stream($hoja->filename());
    }

    /**
     * @return array{list<CounterPayoutRowData>, list<UnconfirmedTransferRowData>}
     */
    private function colasFiltradas(PlanillaFilters $filtros): array
    {
        $mostrador = $this->mostrador->resolve();
        $transferencias = $this->transferencias();
        $aceptadasMostrador = $this->planillas->matchingIds(
            $filtros->forQueue(self::MOSTRADOR),
            array_map(fn (CounterPayoutRowData $fila): int => $fila->installmentId, $mostrador),
        );
        $aceptadasTransferencias = $this->planillas->matchingIds(
            $filtros->forQueue(self::TRANSFERENCIAS),
            array_map(fn (UnconfirmedTransferRowData $fila): int => $fila->installmentId, $transferencias),
        );

        return [
            array_values(array_filter($mostrador, fn (CounterPayoutRowData $fila): bool => isset($aceptadasMostrador[$fila->installmentId]))),
            array_values(array_filter($transferencias, fn (UnconfirmedTransferRowData $fila): bool => isset($aceptadasTransferencias[$fila->installmentId]))),
        ];
    }

    /**
     * Los egresos que salieron y el libro todavía no reconoce.
     *
     * Ordenados por el primero de los dos hechos que ya ocurrieron: el que
     * lleva más tiempo a medio camino es el que hay que destrabar, sea
     * porque falta el débito o porque falta el informe. `LEAST` en
     * PostgreSQL ignora los nulos, que es justo lo que hace falta acá
     * —siempre hay exactamente uno o dos—.
     *
     * @return list<UnconfirmedTransferRowData>
     */
    private function transferencias(): array
    {
        return array_values(Disbursement::query()
            ->sinConfirmar()
            ->with([
                'installment.haber.beneficiary',
                'installment.haber.expediente.employer',
                'paymentOrder',
            ])
            ->orderByRaw('LEAST(report_received_at, bank_debit_observed_at)')
            ->orderBy('id')
            ->get()
            ->map(fn (Disbursement $egreso): UnconfirmedTransferRowData => UnconfirmedTransferRowData::fromModel(
                $egreso,
                $this->etapa->missing($egreso),
                $this->diasEsperando($egreso),
            ))
            ->all());
    }

    /**
     * La suma de una cola, en `bcmath` y sin pasar por `float`.
     *
     * Las dos filas tienen `amount` y ninguna otra cosa en común, que es
     * todo lo que esta suma necesita saber de ellas.
     *
     * @param  list<CounterPayoutRowData>|list<UnconfirmedTransferRowData>  $filas
     * @return numeric-string
     */
    private function total(array $filas): string
    {
        $total = '0';

        foreach ($filas as $fila) {
            $total = Decimal::add($total, $fila->amount);
        }

        return $total;
    }

    private function diasEsperando(Disbursement $egreso): int
    {
        $desde = collect([$egreso->report_received_at, $egreso->bank_debit_observed_at])
            ->filter()
            ->min();

        return $desde === null ? 0 : (int) $desde->diffInDays(CarbonImmutable::now(), false);
    }

    /** Qué planilla se está mirando. Ante cualquier cosa, el mostrador. */
    private function cola(PlanillaFilterRequest $request): string
    {
        return match ($request->query('cola')) {
            self::TRANSFERENCIAS => self::TRANSFERENCIAS,
            self::TODAS => self::TODAS,
            default => self::MOSTRADOR,
        };
    }
}
