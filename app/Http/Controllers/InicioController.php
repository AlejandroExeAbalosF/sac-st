<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\PendingOrderQueues;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Data\CashBoxStateData;
use App\Modules\Ledger\Data\CashDayStatusData;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDaysPendingClosing;
use App\Modules\Ledger\Support\CashMonthsPendingClosing;
use App\Modules\Ledger\Support\RecentMovements;
use App\Modules\Shared\Data\CashBoxSummaryData;
use App\Modules\Shared\Data\RecentAccessData;
use App\Modules\Shared\Data\WorkQueueData;
use App\Modules\Shared\Enums\QueueTone;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El tablero de inicio.
 *
 * Muestra colas de trabajo, no métricas acumuladas: cada número significa
 * «hay N casos esperando que alguien haga algo», y el click lleva
 * exactamente a esos N casos. Un total acumulado es decorativo; esto
 * reemplaza el «¿en qué estaba?» de cada mañana.
 *
 * ── Por qué vive acá y no en un módulo ─────────────────────────────────
 *
 * Es la única pantalla que compone los cuatro. `App\Modules\Shared` —donde
 * estaba— no puede leer Ledger, Banking ni Haberes: la dependencia es
 * dirigida y `tests/Arch/ModuleBoundariesTest.php` la hace fallar en CI.
 * Un tablero que necesita a los cuatro pertenece a la capa que está arriba
 * de los cuatro, que es esta. **No mover de vuelta a Shared.**
 *
 * ── Qué llega diferido ─────────────────────────────────────────────────
 *
 * La primera respuesta trae lo que la pantalla necesita para dibujarse
 * —fecha, permisos, actividad propia— y el resto viaja en peticiones
 * paralelas con `Inertia::defer()`. No es una optimización cosmética: la
 * pasada de elegibilidad de las cuotas es lo más caro del sistema, y sin
 * diferir retrasaría el encabezado y la búsqueda, que es lo primero que
 * el operador usa.
 */
final class InicioController extends Controller
{
    /** La caja de Haberes, resuelta una sola vez por respuesta. */
    private ?CashBox $caja = null;

    public function __construct(
        private readonly CashBalance $saldos,
        private readonly CashDaysPendingClosing $diasSinCerrar,
        private readonly CashMonthsPendingClosing $mesesSinCerrar,
        private readonly RecentMovements $movimientos,
        private readonly PendingOrderQueues $colasDeOrdenes,
        private readonly RegisterOpeningBalance $abrir,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $fecha = BusinessDate::today();

        $verCaja = $user->can('caja.ver');
        $verExpedientes = $user->can('expedientes.ver');
        $verBanco = $user->can('banco.extractos.ver');
        $verCierres = $user->can('cierres.ver');

        return Inertia::render('inicio', [
            'operationalDate' => $fecha->toDateString(),

            /*
             * Los permisos de los accesos rápidos. Van por pantalla y no
             * en `HandleInertiaRequests`, que a propósito comparte solo
             * los tres que la barra lateral necesita: sesenta banderas en
             * cada respuesta para usar cuatro es el problema que ese
             * comentario ya evitó una vez.
             */
            'can' => [
                'verCaja' => $verCaja,
                'verExpedientes' => $verExpedientes,
                'verMovimientos' => $verCaja,
                'verVolumen' => $verExpedientes,
                'verColas' => $verBanco || $verExpedientes || $verCierres,
                'crearExpediente' => $user->can('expedientes.crear'),
                'importarExtracto' => $user->can('banco.extractos.importar'),
            ],

            'recentAccess' => $user
                ->loginEvents()
                ->limit(5)
                ->get()
                ->map(RecentAccessData::fromEvent(...))
                ->all(),

            /*
             * `rescue`: si una de las consultas de las colas falla, la
             * pantalla muestra el aviso adentro del bloque en vez de
             * caerse entera. El resto del tablero sigue sirviendo.
             */
            'queues' => Inertia::defer(
                fn (): array => $this->queues($fecha, $user),
                'colas',
                rescue: true,
            ),

            'cashDay' => Inertia::defer(
                fn (): ?CashDayStatusData => $verCaja ? $this->cashDay($fecha) : null,
                'caja',
                rescue: true,
            ),

            'cashBoxes' => Inertia::defer(
                fn (): array => $verExpedientes ? $this->cashBoxes() : [],
                'caja',
                rescue: true,
            ),

            'movements' => Inertia::defer(
                fn (): array => $verCaja ? $this->movimientos->latest() : [],
                'movimientos',
                rescue: true,
            ),
        ]);
    }

    /**
     * Las colas del circuito de Haberes, en el orden en que ocurren.
     *
     * El orden es el del circuito y **no el de urgencia**: la pantalla se
     * mira todos los días y reordenarla según qué esté en rojo hoy
     * obligaría a leerla entera cada vez. El color dice qué apura; el
     * lugar dice qué es.
     *
     * @return list<WorkQueueData>
     */
    private function queues(CarbonImmutable $fecha, User $user): array
    {
        $colas = [];

        /*
         * Las dos caras del mismo cruce, y por eso van juntas y en este
         * orden: el papel llega con el expediente, el movimiento aparece
         * después cuando se importa el extracto.
         *
         * El comprobante sin cruzar no tenía cola, y era el único estado
         * pendiente del circuito que nadie miraba: quien cargó ocho
         * comprobantes de ocho expedientes distintos no tenía forma de
         * barrerlos salvo acordándose de cada uno.
         */
        if ($user->can('depositos.ver')) {
            $colas[] = WorkQueueData::resuelta(
                key: 'comprobantes_sin_cruzar',
                title: 'Comprobantes sin cruzar',
                description: 'Depósitos que trajo el expediente, esperando que el crédito aparezca en el extracto',
                count: DepositTicket::query()->waiting()->count(),
                href: route(
                    'depositos.index',
                    ['estado' => DepositTicketStatus::Waiting->value],
                    absolute: false,
                ),
            );
        }

        if ($user->can('banco.extractos.ver')) {
            $colas[] = WorkQueueData::resuelta(
                key: 'fondos_sin_identificar',
                title: 'Fondos sin identificar',
                description: 'Créditos del banco que todavía no se asignaron a una cuota',
                count: BankTransaction::query()->sinIdentificar()->count(),
                tone: QueueTone::Action,
                href: route(
                    'banco.movimientos.index',
                    ['estado' => 'pending', 'sentido' => 'credit'],
                    absolute: false,
                ),
            );
        }

        if ($user->can('expedientes.ver')) {
            $ordenes = $this->colasDeOrdenes->resolve();

            $colas[] = WorkQueueData::resuelta(
                key: 'cuotas_sin_orden',
                title: 'Cuotas financiadas sin Orden',
                description: 'Tienen el dinero adentro y ya se les puede emitir la Orden de Pago',
                count: $ordenes->withoutOrder,
                tone: QueueTone::Action,
                samples: $ordenes->withoutOrderSamples,
                hasMore: $ordenes->hasMore,
            );
            $colas[] = WorkQueueData::resuelta(
                key: 'ordenes_en_saf',
                title: 'Órdenes remitidas al SAF',
                description: 'Esperando el informe de transferencia',
                count: PaymentOrder::query()->enSaf()->count(),
            );
            $colas[] = WorkQueueData::resuelta(
                key: 'egresos_por_validar',
                title: 'Egresos por validar',
                description: 'Informe y débito presentes; falta la validación del contador',
                count: Disbursement::query()->porValidar()->count(),
                tone: QueueTone::Action,
                /*
                 * La cola contaba sin llevar a ningún lado: ahora hay
                 * pantalla. Va a la lista completa de transferencias sin
                 * confirmar —no solo a las listas para validar— porque quien
                 * viene a validar es el mismo que tiene que ver qué quedó
                 * trabado esperando al banco.
                 */
                href: route('planillas.index', ['cola' => 'transferencias'], absolute: false),
            );
            $colas[] = WorkQueueData::resuelta(
                key: 'beneficiarios_sin_cbu',
                title: 'Fondos en banco sin CBU',
                description: 'No se puede emitir la Orden hasta verificar la cuenta',
                count: $ordenes->withoutBankAccount,
                tone: QueueTone::Blocked,
                samples: $ordenes->withoutBankAccountSamples,
                hasMore: $ordenes->hasMore,
            );
        }

        if ($user->can('cierres.ver')) {
            $colas[] = WorkQueueData::resuelta(
                key: 'caja_sin_cerrar',
                title: 'Días de caja sin cerrar',
                description: 'Días anteriores con movimientos que todavía no se cerraron',
                count: $this->diasSinCerrar->count((int) $this->cashBox()->id, $fecha),
                tone: QueueTone::Action,
                href: route('caja.cierres.index', absolute: false),
            );

            /*
             * El mes terminado que nadie cerró.
             *
             * Cerrar el mes no es automático ni debería serlo --es un acto
             * contable, no un vencimiento de calendario-- pero tampoco
             * puede depender de que alguien se acuerde. Va como cola
             * propia y no sumado a la de días: son dos trabajos
             * distintos, y el mensual exige que los días ya estén
             * cerrados, así que este número se lleva a cero después del
             * otro.
             */
            $colas[] = WorkQueueData::resuelta(
                key: 'caja_mes_sin_cerrar',
                title: 'Meses sin cerrar',
                description: 'Meses terminados con movimientos que todavía no se cerraron',
                // Sin moneda: un mes sin cerrar en dólares tiene que
                // empujar igual que uno en pesos.
                count: $this->mesesSinCerrar->count((int) $this->cashBox()->id, $fecha),
                tone: QueueTone::Action,
                href: route('caja.cierres.index', absolute: false),
            );
        }

        return $colas;
    }

    /**
     * Cuánto hay en la caja y en qué estado está el día.
     *
     * Todo sale de donde ya salía para la pantalla de Caja. Si el tablero
     * calculara lo suyo, el día que las dos discrepen nadie sabría cuál
     * creer.
     */
    private function cashDay(CarbonImmutable $fecha): CashDayStatusData
    {
        $caja = $this->cashBox();
        $id = (int) $caja->id;
        /*
         * El panel muestra un saldo, y son dos libros. Queda en pesos
         * hasta saber cómo quiere verlo el área: partir la tarjeta en dos
         * es una decisión de diseño del tablero, no un arreglo. La Caja sí
         * ofrece las dos monedas, y la cola de meses sin cerrar de arriba
         * cuenta ambas, que es lo que evita que un mes pase inadvertido.
         */
        $moneda = Currency::Ars;

        $cierre = PeriodClosing::query()
            ->where('cash_box_id', $id)
            ->where('currency', $moneda)
            ->where('period_type', PeriodType::Daily)
            ->whereDate('period_from', $fecha)
            ->first();

        return new CashDayStatusData(
            balances: new CashBoxStateData(
                cashBoxId: $id,
                code: $caja->code,
                name: $caja->name,
                currency: $moneda->value,
                cash: $this->saldos->of(LedgerAccount::CashOnHand, $id, $moneda, $fecha),
                cheques: $this->saldos->of(LedgerAccount::ChequesInCustody, $id, $moneda, $fecha),
                bank: $this->saldos->of(LedgerAccount::BankAccount, $id, $moneda, $fecha),
                unassigned: $this->saldos->of(LedgerAccount::UnassignedFunds, $id, $moneda, $fecha),
                inTransit: $this->saldos->of(LedgerAccount::CashInTransit, $id, $moneda, $fecha),
            ),
            date: $fecha->toDateString(),
            /*
             * La misma pregunta que hace la pantalla de Caja, contestada
             * por el mismo lado: el asiento de apertura existe o no.
             * Contar líneas o mirar saldos abriría la puerta a que las dos
             * den distinto.
             */
            needsOpening: $this->abrir->existingFor($id, $moneda) === null,
            countsToday: CashCount::query()
                ->where('cash_box_id', $id)
                ->where('currency', $moneda)
                ->whereDate('counted_on', $fecha)
                ->whereNot('status', CashCountStatus::Draft)
                ->count(),
            closingStatus: $cierre?->status->value,
            href: route('caja.dia', absolute: false),
        );
    }

    /**
     * Volumen de trámites por caja.
     *
     * Las tres del alcance, aunque solo Haberes opere: el área comprometió
     * las tres y ocultarlas daría a entender que se descartaron. Las otras
     * dos siguen en `null`, que la pantalla distingue de un cero.
     *
     * @return list<CashBoxSummaryData>
     */
    private function cashBoxes(): array
    {
        $haberes = Expediente::query()
            ->whereNot('status', ExpedienteStatus::Cancelled)
            ->count();

        return [
            new CashBoxSummaryData('haberes', 'Haberes', $haberes, $haberes > 0 ? 100 : 0, true),
            new CashBoxSummaryData('aranceles', 'Aranceles', null, null, false),
            new CashBoxSummaryData('multas', 'Multas', null, null, false),
        ];
    }

    /**
     * La caja de Haberes, la única con circuito construido.
     *
     * Se resuelve una sola vez: las colas y el estado del día la piden por
     * separado y llegan en la misma respuesta diferida.
     */
    private function cashBox(): CashBox
    {
        return $this->caja ??= CashBox::query()
            ->active()
            ->where('code', CashBox::HABERES)
            ->firstOrFail();
    }
}
