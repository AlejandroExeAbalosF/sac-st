<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\CancelExpediente;
use App\Modules\Haberes\Actions\CreateExpediente;
use App\Modules\Haberes\Actions\ReactivateExpediente;
use App\Modules\Haberes\Actions\UpdateExpediente;
use App\Modules\Haberes\Data\ExpedienteExtraData;
use App\Modules\Haberes\Data\ExpedienteListItemData;
use App\Modules\Haberes\Data\HaberRowData;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Http\Requests\CancelExpedienteRequest;
use App\Modules\Haberes\Http\Requests\ReactivateExpedienteRequest;
use App\Modules\Haberes\Http\Requests\StoreExpedienteRequest;
use App\Modules\Haberes\Http\Requests\UpdateExpedienteRequest;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Haberes\Support\DepositTicketLinks;
use App\Modules\Haberes\Support\ExpedienteNumber;
use App\Modules\Haberes\Support\InstallmentBatch;
use App\Modules\Shared\Data\LastChangeData;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Support\LastChanges;
use App\Support\Ui\Toast;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Expedientes y los haberes que reconocen.
 *
 * El expediente registra el derecho, no el dinero: acá no se mueve un
 * peso. La recepción, la Orden y el egreso son otras pantallas.
 */
final class ExpedienteController extends Controller
{
    private const OPCIONES_INICIALES = 20;

    private const EXPEDIENTES_POR_PAGINA = 25;

    private const HABERES_POR_PAGINA = 25;

    public function __construct(private readonly LastChanges $cambios) {}

    /**
     * El listado, en sus dos lecturas.
     *
     * La misma pantalla contesta dos preguntas distintas: «qué expedientes
     * entraron» y «qué haberes hay». La segunda no se puede resolver
     * aplanando la primera en el navegador: la página trae veinticinco
     * expedientes, que pueden ser cuarenta haberes o cuatro, y el pie
     * diría cualquier cosa. Son dos consultas paginadas, y cuál corre lo
     * decide `vista`, que viaja en la dirección para que la pestaña
     * sobreviva al refresco y se pueda compartir.
     */
    public function index(Request $request): Response
    {
        $search = $request->string('q')->toString();

        /*
         * Los anulados no se ven salvo que se pidan.
         *
         * Es lo que reemplaza al borrado: el expediente cargado por error
         * deja de estorbar sin desaparecer, y el rastro de que existió
         * —y de quién lo anuló— queda entero.
         */
        $verAnulados = $request->boolean('anulados');

        if ($request->string('vista')->toString() === 'haberes') {
            return $this->listadoDeHaberes($search, $verAnulados);
        }

        $paginador = Expediente::query()
            ->paraListado()
            ->buscar($search)
            ->when(
                ! $verAnulados,
                fn ($query) => $query->whereNot('status', ExpedienteStatus::Cancelled),
            )
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->paginate(self::EXPEDIENTES_POR_PAGINA)
            ->withQueryString();

        $pagina = $paginador->getCollection();

        $cambios = $this->cambios->for(
            'Expediente',
            array_values($pagina->pluck('id')->map(intval(...))->all()),
            ignoring: ['expediente.registrado'],
        );

        /*
         * Los haberes del acordeón se resuelven de una sola vez para toda
         * la página, no expediente por expediente: son las mismas dos
         * consultas tanto para veinticinco haberes como para uno.
         */
        $cambiosDeHaberes = $this->cambiosDeHaberesDe($pagina);

        /*
         * Y lo que las cuotas del acordeón necesitan, también para toda la
         * página: son cinco consultas agregadas tanto para veinticinco
         * expedientes como para uno.
         */
        $lote = app(InstallmentBatch::class)->for(
            $pagina->flatMap(
                fn (Expediente $expediente) => $expediente->haberes->flatMap(
                    fn (Haber $haber) => $haber->installments,
                ),
            ),
        );

        $expedientes = $pagina
            ->map(fn (Expediente $expediente): ExpedienteListItemData => ExpedienteListItemData::fromModel(
                expediente: $expediente,
                lastChange: $cambios[$expediente->id] ?? null,
                haberChanges: $cambiosDeHaberes,
                funded: $lote['funded'],
                receipts: $lote['receipts'],
                transfers: $lote['transfers'],
                mediums: $lote['mediums'],
                stages: $lote['stages'],
            ))
            ->values();

        return Inertia::render('haberes/index', [
            'vista' => 'expedientes',
            'expedientes' => $expedientes,
            'filters' => ['q' => $search, 'anulados' => $verAnulados],
            'anulados' => Expediente::query()
                ->where('status', ExpedienteStatus::Cancelled)
                ->count(),
            'pagination' => [
                'currentPage' => $paginador->currentPage(),
                'lastPage' => $paginador->lastPage(),
                'perPage' => $paginador->perPage(),
                'total' => $paginador->total(),
                'from' => $paginador->firstItem(),
                'to' => $paginador->lastItem(),
                'previous' => $paginador->previousPageUrl(),
                'next' => $paginador->nextPageUrl(),
            ],
        ]);
    }

    /**
     * El mismo listado, leído por haber.
     *
     * Los anulados se esconden con el mismo criterio que los expedientes,
     * pero contando haberes: en esta lectura un expediente vigente con su
     * único haber anulado no tiene por qué aparecer.
     */
    private function listadoDeHaberes(string $search, bool $verAnulados): Response
    {
        $paginador = Haber::query()
            /*
             * El `select` va antes de `paraListado`: los contadores de
             * cuotas son subconsultas del `select`, y pedirlo después las
             * borraría. Hace falta por el `join` de más abajo, que si no
             * mezcla las columnas del expediente con las del haber.
             */
            ->select('haberes.*')
            ->join('expedientes', 'expedientes.id', '=', 'haberes.expediente_id')
            ->paraListado()
            ->buscar($search)
            ->when(
                ! $verAnulados,
                fn ($query) => $query->whereNot('workflow_status', HaberWorkflowStatus::Cancelled),
            )
            /*
             * El orden es el del listado madre —lo último que entró, primero—
             * y se apoya en el expediente, que es el que tiene la fecha. Los
             * haberes de un mismo expediente quedan juntos y en el orden en
             * que se cargaron, que es como los nombra el papel.
             */
            ->orderByDesc('expedientes.received_date')
            ->orderByDesc('expedientes.id')
            ->orderBy('haberes.id')
            ->paginate(self::HABERES_POR_PAGINA)
            ->withQueryString();

        $pagina = $paginador->getCollection();

        $cambios = $this->cambios->for(
            'Haber',
            array_values($pagina->pluck('id')->map(intval(...))->all()),
            ignoring: ['haber.reconocido'],
        );

        /*
         * Lo que las cuotas del acordeón necesitan para no mentir, para
         * toda la página de una vez: son las mismas consultas agregadas
         * tanto para veinticinco haberes como para uno. Sin esto una cuota
         * ya depositada diría «Efectivo» y ninguna sabría en qué etapa del
         * circuito está.
         */
        $lote = app(InstallmentBatch::class)->for(
            $pagina->flatMap(fn (Haber $haber) => $haber->installments),
        );

        $haberes = $pagina
            ->map(fn (Haber $haber): HaberRowData => HaberRowData::fromModel(
                haber: $haber,
                lastChange: $cambios[$haber->id] ?? null,
                funded: $lote['funded'],
                receipts: $lote['receipts'],
                transfers: $lote['transfers'],
                mediums: $lote['mediums'],
                stages: $lote['stages'],
            ))
            ->values();

        return Inertia::render('haberes/index', [
            'vista' => 'haberes',
            'haberes' => $haberes,
            'filters' => ['q' => $search, 'anulados' => $verAnulados],
            'anulados' => Haber::query()
                ->where('workflow_status', HaberWorkflowStatus::Cancelled)
                ->count(),
            'pagination' => [
                'currentPage' => $paginador->currentPage(),
                'lastPage' => $paginador->lastPage(),
                'perPage' => $paginador->perPage(),
                'total' => $paginador->total(),
                'from' => $paginador->firstItem(),
                'to' => $paginador->lastItem(),
                'previous' => $paginador->previousPageUrl(),
                'next' => $paginador->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Formulario de alta.
     *
     * Recibe opcionalmente el número que se está tipeando para responder
     * si ese expediente ya existe. La pantalla lo consulta sola mientras
     * se escribe, con una recarga parcial de Inertia: no hace falta una
     * API aparte para una pregunta que ya sabe contestar el controlador.
     */
    public function create(Request $request): Response
    {
        $numero = ExpedienteNumber::parse($request->string('expediente')->toString());

        return Inertia::render('haberes/create', [
            'empleadores' => $this->opciones('employer'),
            'existente' => $numero === null
                ? null
                : $this->buscarPorCanonico($numero->canonical),
        ]);
    }

    /**
     * Da de alta el expediente y lleva a su detalle, donde se le agregan
     * los haberes.
     */
    public function store(StoreExpedienteRequest $request, CreateExpediente $crear): RedirectResponse
    {
        $datos = $request->validated();

        // El formato ya lo validó StoreExpedienteRequest, así que el
        // número siempre se puede descomponer acá.
        $numero = ExpedienteNumber::parse((string) $datos['number']);

        if ($numero === null) {
            throw new NotFoundHttpException;
        }

        try {
            $expediente = $crear->handle(
                $datos,
                $numero,
                $request->integer('employerId'),
                $request->user()?->id,
            );
        } catch (UniqueConstraintViolationException $exception) {
            $externalIdDuplicado = str_contains($exception->getMessage(), 'expedientes_source_external_unique');

            throw ValidationException::withMessages([
                $externalIdDuplicado ? 'externalId' : 'number' => $externalIdDuplicado
                    ? 'Ya existe un expediente con ese código de SiCE.'
                    : 'Ese expediente acaba de ser registrado por otro operador. Abrilo desde el listado.',
            ]);
        }

        Toast::success('Expediente registrado.', 'Agregale ahora los haberes que reconoce.');

        return to_route('expedientes.show', $expediente);
    }

    public function show(Request $request, Expediente $expediente): Response|RedirectResponse
    {
        /*
         * La dirección se acomoda sola a la forma con número.
         *
         * Los enlaces de adentro del sistema se arman con el id: los
         * helpers tipados del front no conocen la clave de ruta del
         * modelo. En vez de repetir esa clave en cada DTO, la pantalla que
         * de verdad se lee y se comparte redirige a su dirección buena, y
         * lo mismo vale para quien tipeó solo «125958-2026».
         */
        $clave = $request->route()?->originalParameters()['expediente'] ?? null;

        if (is_string($clave) && $clave !== $expediente->getRouteKey()) {
            return to_route('expedientes.show', $expediente);
        }

        $expediente->load([
            'employer:id,name',
            // Solo la pantalla de detalle muestra quién lo cargó.
            'creator:id,name',
            'haberes' => fn ($haberes) => $haberes->orderBy('id'),
            'haberes.beneficiary:id,name,document',
            // Quién cargó cada haber: la tarjeta lo muestra al lado de sus
            // importes. Va acá y no en el DTO porque leerlo suelto, con el
            // modo estricto encendido, sería una consulta por haber.
            'haberes.creator:id,name',
            'haberes.installments' => fn ($cuotas) => $cuotas->orderBy('installment_number'),
            'haberes.installments.managementLabel:id,code,blocks_payment',
            // El comprobante que el expediente trajo para cada cuota: la
            // tarjeta lo usa para ofrecer cargarlo o mostrar el que ya está.
            'haberes.installments.depositTickets.account:id,label',
        ]);

        /*
         * Los mismos enlaces que arma la pantalla del haber. Sin esto la
         * foto del comprobante desaparece acá: el DTO dejó de buscarla por
         * su cuenta cuando se sacó la consulta por ticket.
         */
        /** @var list<int> $cuotaIds */
        $cuotaIds = $expediente->haberes
            ->flatMap(fn (Haber $haber): array => $haber->installments->modelKeys())
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $enlaces = app(DepositTicketLinks::class);

        /*
         * Lo que las cuotas necesitan para decir la verdad: el traslado al
         * banco, el recibo, lo imputado, el medio real y la etapa. Hasta
         * acá no se pasaban, y el listado mostraba «Efectivo» para una
         * cuota ya depositada sin que nada fallara.
         */
        $lote = app(InstallmentBatch::class)->for(
            $expediente->haberes->flatMap(fn (Haber $haber) => $haber->installments),
        );

        return Inertia::render('haberes/show', [
            'expediente' => ExpedienteListItemData::fromModel(
                expediente: $expediente,
                ticketReceipts: $enlaces->receipts($cuotaIds),
                ticketAttachments: $enlaces->attachments($cuotaIds),
                funded: $lote['funded'],
                receipts: $lote['receipts'],
                transfers: $lote['transfers'],
                mediums: $lote['mediums'],
                stages: $lote['stages'],
                /*
                 * El detalle dibuja los mismos haberes con el mismo
                 * componente que el acordeón del listado. Sin esto la
                 * columna diría «nunca se modificó» en una pantalla y la
                 * verdad en la otra.
                 */
                haberChanges: $this->cambiosDeHaberesDe(collect([$expediente])),
            ),
            'extras' => ExpedienteExtraData::fromModel($expediente),
            'etiquetas' => HaberManagementLabel::query()
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'code', 'description']),
            'canCancel' => $request->user()?->can('expedientes.anular') ?? false,
            'canEdit' => $request->user()?->can('expedientes.editar') ?? false,
        ]);
    }

    /**
     * Formulario de corrección de la ficha.
     *
     * El número no se edita: identifica al expediente. Un número
     * equivocado no es una ficha con un error, es otro expediente, y eso
     * se resuelve anulando y cargando el correcto.
     */
    public function edit(Expediente $expediente): Response
    {
        $expediente->load('employer:id,name,document,type');

        return Inertia::render('haberes/edit', [
            'expediente' => [
                'id' => $expediente->id,
                'displayNumber' => $expediente->display_number,
                'canonicalNumber' => $expediente->canonical_number,
                'subject' => $expediente->subject,
                'receivedDate' => $expediente->received_date?->format('Y-m-d'),
                'employerId' => $expediente->employer_id,
                'employerRepresentative' => $expediente->employer_representative,
                'declaredTotalAmount' => $expediente->declared_total_amount,
                'externalReference' => $expediente->external_reference,
                'notes' => $expediente->notes,
            ],
            // El empleador actual va primero para que el buscador abra
            // mostrándolo, aunque no entre en las primeras veinte opciones.
            'empleadores' => $this->opcionesConActual('employer', $expediente->employer),
        ]);
    }

    public function update(
        UpdateExpedienteRequest $request,
        Expediente $expediente,
        UpdateExpediente $corregir,
    ): RedirectResponse {
        $corregir->handle($expediente, $request->validated());

        return to_route('expedientes.show', $expediente)
            ->with('status', 'Ficha del expediente actualizada.');
    }

    /**
     * Anula el expediente y baja el estado a sus haberes y cuotas.
     *
     * No hay borrado, ni para el administrador. El número de expediente es
     * único: borrarlo perdería el registro de que estuvo cargado, y dejaría
     * a los eventos de auditoría apuntando a una fila inexistente.
     */
    public function cancel(
        CancelExpedienteRequest $request,
        Expediente $expediente,
        CancelExpediente $anular,
    ): RedirectResponse {
        $anular->handle($expediente, (string) $request->validated('reason'));

        return to_route('expedientes.index')->with(
            'status',
            "Expediente {$expediente->display_number} anulado.",
        );
    }

    /**
     * Deshace una anulación.
     *
     * Cada haber y cada cuota vuelven al estado que tenían antes, no a
     * «activo»: uno que estaba cerrado vuelve cerrado, y uno bloqueado
     * recupera su motivo.
     */
    public function reactivate(
        ReactivateExpedienteRequest $request,
        Expediente $expediente,
        ReactivateExpediente $reactivar,
    ): RedirectResponse {
        $reactivar->handle($expediente, (string) $request->validated('reason'));

        return back()->with(
            'status',
            "Expediente {$expediente->display_number} reactivado.",
        );
    }

    /**
     * El último cambio de cada haber de una tanda de expedientes.
     *
     * @param  Collection<int, Expediente>  $expedientes
     * @return array<int, LastChangeData>
     */
    private function cambiosDeHaberesDe(Collection $expedientes): array
    {
        return $this->cambios->for(
            'Haber',
            array_values($expedientes
                ->flatMap(fn (Expediente $expediente): array => $expediente->haberes->modelKeys())
                ->map(intval(...))
                ->all()),
            ignoring: ['haber.reconocido'],
        );
    }

    private function buscarPorCanonico(string $canonical): ?ExpedienteListItemData
    {
        $expediente = Expediente::query()
            ->paraListado()
            ->where('canonical_number', $canonical)
            ->first();

        return $expediente === null
            ? null
            : ExpedienteListItemData::fromModel($expediente);
    }

    /**
     * Las opciones iniciales, con una persona concreta al frente.
     *
     * Sin esto, editar un expediente cuyo empleador no entra en las
     * primeras veinte abriría el buscador sin poder mostrar el que ya está
     * elegido.
     *
     * @return list<array{id:int,name:string,document:string|null,type:string}>
     */
    private function opcionesConActual(string $role, ?Person $actual): array
    {
        $opciones = $this->opciones($role);

        if ($actual === null) {
            return $opciones;
        }

        $sinDuplicar = array_values(array_filter(
            $opciones,
            fn (array $opcion): bool => $opcion['id'] !== $actual->id,
        ));

        /*
         * El empleador del expediente llega con un `select` acotado, sin
         * `owner_person_id`, así que pedirle el titular explota bajo
         * `shouldBeStrict`. Se lo trae completo solo para esta fila.
         */
        $completo = Person::query()->with('owner')->find($actual->id);

        return [
            [...$actual->toOption(), 'ownerName' => $completo?->owner?->name],
            ...$sinDuplicar,
        ];
    }

    /**
     * Primeras opciones del buscador de personas.
     *
     * Son un punto de partida para que el desplegable no abra vacío; a
     * partir de ahí el buscador consulta al servidor.
     *
     * @return list<array{id:int,name:string,document:string|null,type:string}>
     */
    private function opciones(string $role): array
    {
        /** @var list<array{id:int,name:string,document:string|null,type:string,ownerName:string|null}> $opciones */
        $opciones = Person::query()
            ->active()
            ->forRole($role)
            ->with('owner')
            ->orderBy('name')
            ->limit(self::OPCIONES_INICIALES)
            ->get()
            ->map(fn (Person $person): array => [
                ...$person->toOption(),
                /*
                 * El titular viaja con el empleador para que el campo del
                 * representante pueda ofrecerlo: cuando el expediente trae
                 * un nombre junto a la empresa, casi siempre es ese.
                 */
                'ownerName' => $person->owner?->name,
            ])
            ->values()
            ->all();

        return $opciones;
    }
}
