<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Data\BankTransactionListItemData;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\DiscardDepositTicket;
use App\Modules\Haberes\Actions\FindTicketCandidates;
use App\Modules\Haberes\Actions\LinkDepositTicket;
use App\Modules\Haberes\Actions\ListAvailableCredits;
use App\Modules\Haberes\Actions\RegisterDepositTicket;
use App\Modules\Haberes\Actions\UpdateDepositTicket;
use App\Modules\Haberes\Data\DepositTicketData;
use App\Modules\Haberes\Data\TicketCandidateData;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Http\Requests\SaveDepositTicketRequest;
use App\Modules\Haberes\Http\Requests\UpdateDepositTicketRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Pdf\PendingDepositSheet;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\TicketCandidate;
use App\Support\Pdf\Worksheet;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

final class DepositTicketController extends Controller
{
    public function __construct(private readonly InstallmentFunding $financiacion) {}

    /**
     * La cola de trabajo.
     *
     * Los que esperan van primero y ordenados del más viejo al más nuevo:
     * un ticket de hace tres semanas que sigue sin aparecer es una señal
     * —o el depósito nunca se hizo, o falta importar un período— y tiene
     * que estar arriba, no perdido al final de la lista.
     */
    public function index(Request $request): Response
    {
        $estado = $request->query('estado', DepositTicketStatus::Waiting->value);

        $tickets = DepositTicket::query()
            ->with(['expediente.employer', 'account', 'installment'])
            ->when(
                is_string($estado) && $estado !== 'todos',
                fn ($query) => $query->where('status', $estado),
            )
            ->orderByRaw("CASE status WHEN 'waiting' THEN 0 WHEN 'matched' THEN 1 ELSE 2 END")
            ->orderBy('deposited_at')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (DepositTicket $t): DepositTicketData => DepositTicketData::fromModel($t));

        return Inertia::render('haberes/depositos/index', [
            'tickets' => $tickets,
            'filters' => ['estado' => $estado],
            'counts' => [
                'waiting' => DepositTicket::query()->where('status', DepositTicketStatus::Waiting->value)->count(),
                'matched' => DepositTicket::query()->where('status', DepositTicketStatus::Matched->value)->count(),
                'discarded' => DepositTicket::query()->where('status', DepositTicketStatus::Discarded->value)->count(),
            ],
            'canRegister' => $request->user()?->can('depositos.registrar') ?? false,
            'canLink' => $request->user()?->can('depositos.vincular') ?? false,
            'canDiscard' => $request->user()?->can('depositos.descartar') ?? false,
        ]);
    }

    /**
     * La misma cola, en papel.
     *
     * Solo los que esperan, y sin paginar: la planilla se usa para barrer
     * la cola entera contra el extracto o para reclamarle al empleador, y
     * una hoja que corta en el renglón veinticinco no sirve para ninguna de
     * las dos cosas.
     *
     * No admite el filtro de estado de la pantalla. Un listado de
     * comprobantes ya cruzados no es una cola de trabajo: es historia, y
     * para eso está el expediente.
     */
    public function sheet(Request $request, PendingDepositSheet $planilla, Worksheet $molde): SymfonyResponse
    {
        $tickets = DepositTicket::query()
            ->with(['expediente.employer', 'account', 'installment'])
            ->waiting()
            ->get();

        $hoja = $planilla->build(
            tickets: $tickets,
            generadaEl: CarbonImmutable::now(),
            generadaPor: $request->user()?->name,
        );

        // En línea y no como descarga, igual que los comprobantes: lo normal
        // es mirarla y mandarla a imprimir, no guardarla en Descargas.
        return $molde->render($hoja)->stream($hoja->filename());
    }

    /**
     * La carga del comprobante que trajo el expediente.
     *
     * Entra por la cuota y no por el expediente, y de ahí sale todo lo que
     * antes era un campo: el beneficiario, el importe reconocido y el tipo
     * de depósito. Preguntarlos era ofrecer contradecir a la dirección por
     * la que se entró, y esa contradicción no tenía quién la arbitrara.
     */
    public function create(BeneficiaryInstallment $installment): Response
    {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $haber = $installment->haber;
        $expediente = $haber->expediente;

        /*
         * El medio **efectivo** y no el previsto: con plata adentro, el que
         * fijó la primera recepción es el que va impreso en el recibo, y la
         * columna es solo una expectativa que se edita.
         */
        $medio = $this->financiacion->effectiveMedium($installment);

        return Inertia::render('haberes/depositos/create', [
            'cuota' => [
                'id' => $installment->id,
                'number' => $installment->installment_number,
                'amount' => $installment->importeEsperado(),
                'concept' => $installment->description ?? $haber->concept,
                'medium' => $medio->value,
                'mediumLabel' => $medio->label(),
                'depositKind' => DepositKind::forMedium($medio)->label(),
            ],
            'haber' => [
                'number' => $haber->haber_number,
                'expedienteId' => $expediente->id,
                'beneficiaryName' => $haber->beneficiary->name,
            ],
            'expediente' => [
                'displayNumber' => $expediente->display_number,
                'employerName' => $expediente->employer?->name,
            ],
            'accounts' => $this->activeAccounts(),
        ]);
    }

    /**
     * @return list<array{id: int, label: string, currency: string}>
     */
    private function activeAccounts(): array
    {
        $cuentas = [];

        foreach (BankAccount::query()->where('is_active', true)->orderBy('label')->get() as $cuenta) {
            $cuentas[] = [
                'id' => $cuenta->id,
                'label' => $cuenta->label,
                'currency' => $cuenta->currency,
            ];
        }

        return $cuentas;
    }

    /**
     * Guarda el comprobante contra la cuota de la dirección.
     *
     * A quién apunta, por cuánto y de qué tipo no llegan del formulario:
     * los arma el servidor desde la cuota. Es lo que hace imposible el
     * ticket que dice una cosa y cuelga de otra —y de paso deja al
     * formulario con un solo trabajo, que es transcribir el papel—.
     */
    public function store(
        SaveDepositTicketRequest $request,
        BeneficiaryInstallment $installment,
        RegisterDepositTicket $registrar,
    ): RedirectResponse {
        $installment->loadMissing('haber');

        /** @var array<string, mixed> $datos */
        $datos = [
            ...$request->validated(),
            'expedienteId' => $installment->haber->expediente_id,
            'haberId' => $installment->haber_id,
            'installmentId' => $installment->id,
            'amount' => $installment->importeEsperado(),
            'depositKind' => DepositKind::forMedium(
                $this->financiacion->effectiveMedium($installment),
            )->value,
        ];

        $ticket = $registrar->handle($datos, $request->file('photo'), $request->user()?->id);

        Toast::success('Comprobante registrado.', 'Buscá el movimiento en el extracto.');

        return to_route('depositos.search', $ticket->id);
    }

    /**
     * Corrige lo transcripto del papel.
     *
     * Vuelve a donde estaba el operador —el modal se abre desde el haber,
     * pero también se llega desde la búsqueda—, así que `back()` en vez de
     * un destino fijo.
     */
    public function update(
        UpdateDepositTicketRequest $request,
        DepositTicket $ticket,
        UpdateDepositTicket $corregir,
    ): RedirectResponse {
        /** @var array<string, mixed> $datos */
        $datos = $request->validated();

        try {
            $corregir->handle($ticket, $datos, $request->file('photo'), $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->backWithError($e);
        }

        return back()->with('status', 'Comprobante corregido.');
    }

    /**
     * La pantalla donde se reconoce el movimiento.
     *
     * Muestra la foto del comprobante al lado de los candidatos: comparar
     * mirando es más rápido y más seguro que recordar.
     */
    public function search(
        Request $request,
        DepositTicket $ticket,
        FindTicketCandidates $buscar,
        ListAvailableCredits $disponibles,
    ): Response {
        $ticket->load(['expediente.employer', 'account', 'installment', 'transaction']);

        $resultado = $buscar->handle($ticket);

        /*
         * La lista de respaldo se arma solo cuando el operador la pide.
         *
         * Es la respuesta a «no hay candidatos», y esa respuesta casi
         * siempre es que algo del ticket no coincide. Traerla siempre
         * costaría una consulta paginada en cada visita para una pantalla
         * que en el caso normal —un candidato exacto— no la usa.
         */
        $orden = (string) $request->query('orden', 'cercania');
        $verTodos = $request->boolean('todos');

        return Inertia::render('haberes/depositos/search', [
            'ticket' => DepositTicketData::fromModel($ticket),
            'candidates' => array_map(
                fn (TicketCandidate $c): TicketCandidateData => TicketCandidateData::fromCandidate($c),
                $resultado->candidates,
            ),
            'periodImported' => $resultado->periodImported,
            'emptyReason' => $resultado->emptyReason(),
            'warnings' => $resultado->warnings,
            'available' => $verTodos
                ? $disponibles
                    ->handle($ticket, in_array($orden, ListAvailableCredits::ORDENES, true) ? $orden : 'cercania')
                    ->through(fn (BankTransaction $t): BankTransactionListItemData => BankTransactionListItemData::fromModel($t))
                : null,
            'availableOrder' => $orden,
            'showingAvailable' => $verTodos,
            'canLink' => $request->user()?->can('depositos.vincular') ?? false,
            'canDiscard' => $request->user()?->can('depositos.descartar') ?? false,
            'canEditTicket' => $request->user()?->can('depositos.registrar') ?? false,
        ]);
    }

    public function link(Request $request, DepositTicket $ticket, LinkDepositTicket $vincular): RedirectResponse
    {
        $validado = $request->validate([
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
        ]);

        /** @var BankTransaction $movimiento */
        $movimiento = BankTransaction::query()->findOrFail($validado['bankTransactionId']);

        try {
            $vincular->handle($ticket, $movimiento, (int) $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->backWithError($e);
        }

        return to_route('depositos.index')
            ->with('status', 'El comprobante quedó vinculado a su movimiento bancario.');
    }

    public function unlink(Request $request, DepositTicket $ticket, LinkDepositTicket $vincular): RedirectResponse
    {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        try {
            $vincular->unlink($ticket, $validado['reason'], (int) $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->backWithError($e);
        }

        return back()->with('status', 'El comprobante volvió a la cola de espera.');
    }

    public function discard(Request $request, DepositTicket $ticket, DiscardDepositTicket $descartar): RedirectResponse
    {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        try {
            $descartar->handle($ticket, $validado['reason'], (int) $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->backWithError($e);
        }

        return to_route('depositos.index')->with('status', 'Comprobante descartado.');
    }

    public function reopen(Request $request, DepositTicket $ticket, DiscardDepositTicket $descartar): RedirectResponse
    {
        try {
            $descartar->reopen($ticket, (int) $request->user()?->id);
        } catch (RuntimeException $e) {
            return $this->backWithError($e);
        }

        return back()->with('status', 'El comprobante volvió a la cola de espera.');
    }

    private function backWithError(RuntimeException $exception): RedirectResponse
    {
        Toast::error($exception->getMessage());

        return back();
    }
}
