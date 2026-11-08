<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Data\AllInstallmentRowData;
use App\Modules\Haberes\Data\CounterPayoutRowData;
use App\Modules\Haberes\Data\UnconfirmedTransferRowData;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Pdf\AllInstallmentsSheet;
use App\Modules\Haberes\Pdf\CounterPayoutSheet;
use App\Modules\Haberes\Pdf\UnconfirmedTransferSheet;
use App\Modules\Haberes\Support\CounterPayoutQueue;
use App\Modules\Haberes\Support\InstallmentStages;
use App\Modules\Haberes\Support\TransferStage;
use App\Modules\Shared\Enums\SystemRole;
use App\Support\Money\Decimal;
use App\Support\Pdf\Worksheet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
 * imprimir.
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
        private readonly InstallmentStages $etapasDeCuotas,
    ) {}

    public function index(Request $request): Response
    {
        /*
         * Las dos listas van siempre, no solo la de la solapa abierta: el
         * número de la otra pestaña tiene que ser cierto, y para saberlo hay
         * que resolverla igual. Las dos tienen tope, así que el peor caso
         * está acotado.
         */
        $mostrador = $this->mostrador->resolve();
        $transferencias = $this->transferencias();
        $cola = $this->cola($request);
        $todas = $cola === self::TODAS ? $this->todasPaginadas() : null;

        return Inertia::render('haberes/planillas', [
            'cola' => $cola,
            'counter' => $mostrador,
            'transfers' => $transferencias,
            'all' => $todas,
            'allCount' => $todas?->total() ?? BeneficiaryInstallment::query()->count(),
            'canSeeFilterNotes' => $request->user()?->hasRole(SystemRole::SuperAdmin->value) ?? false,
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
     * La vista abierta, en papel.
     *
     * Una ruta y no dos porque es la misma acción sobre la misma pantalla:
     * imprimir lo que se está mirando. Cuál sale lo dice el
     * mismo parámetro que elige la solapa.
     */
    public function sheet(
        Request $request,
        Worksheet $molde,
        CounterPayoutSheet $planillaMostrador,
        UnconfirmedTransferSheet $planillaTransferencias,
        AllInstallmentsSheet $planillaTodas,
    ): SymfonyResponse {
        $ahora = CarbonImmutable::now();
        $quien = $request->user()?->name;

        if ($this->cola($request) === self::TODAS) {
            $hoja = $planillaTodas->build($this->todas(), $ahora, $quien);
        } elseif ($this->cola($request) === self::TRANSFERENCIAS) {
            $filas = $this->transferencias();
            $hoja = $planillaTransferencias->build($filas, $this->total($filas), $ahora, $quien);
        } else {
            $filas = $this->mostrador->resolve();
            $hoja = $planillaMostrador->build($filas, $this->total($filas), $ahora, $quien);
        }

        // En línea y no como descarga, igual que los comprobantes: lo normal
        // es mirarla y mandarla a imprimir, no guardarla en Descargas.
        return $molde->render($hoja)->stream($hoja->filename());
    }

    /** @return Builder<BeneficiaryInstallment> */
    private function consultaDeCuotas(): Builder
    {
        return BeneficiaryInstallment::query()->with([
            'haber.beneficiary',
            'haber.expediente',
            'managementLabel',
        ]);
    }

    /** @return LengthAwarePaginator<int, AllInstallmentRowData> */
    private function todasPaginadas(): LengthAwarePaginator
    {
        $pagina = $this->consultaDeCuotas()->orderBy('id')->paginate(50)->withQueryString();
        $etapas = $this->etapasDeCuotas->forMany($pagina->getCollection());

        return $pagina->through(fn (BeneficiaryInstallment $cuota): AllInstallmentRowData => AllInstallmentRowData::fromModel($cuota, $etapas[(int) $cuota->id]));
    }

    /** @return list<AllInstallmentRowData> */
    private function todas(): array
    {
        $filas = [];

        $this->consultaDeCuotas()->chunkById(250, function (Collection $cuotas) use (&$filas): void {
            $etapas = $this->etapasDeCuotas->forMany($cuotas);

            foreach ($cuotas as $cuota) {
                $filas[] = AllInstallmentRowData::fromModel($cuota, $etapas[(int) $cuota->id]);
            }
        });

        return $filas;
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
    private function cola(Request $request): string
    {
        return match ($request->query('cola')) {
            self::TRANSFERENCIAS => self::TRANSFERENCIAS,
            self::TODAS => self::TODAS,
            default => self::MOSTRADOR,
        };
    }
}
