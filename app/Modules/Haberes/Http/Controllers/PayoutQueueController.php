<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Data\CounterPayoutRowData;
use App\Modules\Haberes\Data\UnconfirmedTransferRowData;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Pdf\CounterPayoutSheet;
use App\Modules\Haberes\Pdf\UnconfirmedTransferSheet;
use App\Modules\Haberes\Support\CounterPayoutQueue;
use App\Modules\Haberes\Support\TransferStage;
use App\Support\Money\Decimal;
use App\Support\Pdf\Worksheet;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Lo que el sistema le debe a alguien, o le debe al libro.
 *
 * Dos colas, una pantalla, porque son las dos mitades del mismo dinero
 * saliendo y las decide el mismo `DisbursementEligibility`:
 *
 * | Solapa | Qué espera | De quién es el trabajo |
 * |---|---|---|
 * | `mostrador` | nada: se paga ya | el cajero |
 * | `transferencias` | el débito, el informe o el cotejo | el contador |
 *
 * **Ninguna de las dos escribe nada.** Es una pantalla de lectura que
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

    public function __construct(
        private readonly CounterPayoutQueue $mostrador,
        private readonly TransferStage $etapa,
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

        return Inertia::render('haberes/planillas', [
            'cola' => $this->cola($request),
            'counter' => $mostrador,
            'transfers' => $transferencias,
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
     * La cola de la solapa abierta, en papel.
     *
     * Una ruta y no dos porque es la misma acción sobre la misma pantalla:
     * imprimir lo que se está mirando. Cuál de las dos sale lo dice el
     * mismo parámetro que elige la solapa.
     */
    public function sheet(
        Request $request,
        Worksheet $molde,
        CounterPayoutSheet $planillaMostrador,
        UnconfirmedTransferSheet $planillaTransferencias,
    ): SymfonyResponse {
        $ahora = CarbonImmutable::now();
        $quien = $request->user()?->name;

        if ($this->cola($request) === self::TRANSFERENCIAS) {
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

    /** Cuál de las dos colas se está mirando. Ante cualquier cosa, el mostrador. */
    private function cola(Request $request): string
    {
        return $request->query('cola') === self::TRANSFERENCIAS
            ? self::TRANSFERENCIAS
            : self::MOSTRADOR;
    }
}
