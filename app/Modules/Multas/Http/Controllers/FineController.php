<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Actions\CreateFineCase;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Data\FineCaseData;
use App\Modules\Multas\Data\FineCaseListItemData;
use App\Modules\Multas\Data\FineCorrectionTargetData;
use App\Modules\Multas\Data\FineDetailData;
use App\Modules\Multas\Data\FineDocumentData;
use App\Modules\Multas\Data\FineListItemData;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Http\Requests\FineIndexRequest;
use App\Modules\Multas\Http\Requests\ReviseFineRecordRequest;
use App\Modules\Multas\Http\Requests\StoreFineRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\AppealAmount;
use App\Modules\Multas\Support\FineAppeals;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Multas\Support\FineCaseListQuery;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Multas\Support\FineCashReadiness;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Multas\Support\FineListQuery;
use App\Modules\Multas\Support\PartialDate;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Attachments;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Database\Like;
use App\Support\Excel\SpreadsheetWriter;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Generator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Las multas: el listado, el alta y la ficha.
 *
 * Acá no se mueve un peso: se registra qué se debe y por qué papel. Los
 * cobros llegan en la Etapa 4.
 */
final class FineController extends Controller
{
    private const POR_PAGINA = 25;

    /**
     * Dos lecturas del mismo material, como el listado de Haberes: por
     * expediente o por multa. Solo viajan las filas de la vista pedida.
     */
    public function index(FineIndexRequest $request, FineListQuery $multas, FineCaseListQuery $expedientes): Response
    {
        $filtros = $request->filters();
        $vista = $request->view();
        $filasDeMultas = [];
        $filasDeExpedientes = [];

        if ($vista === 'multas') {
            $paginador = $multas->query($filtros)->paginate(self::POR_PAGINA)->withQueryString();
            $filasDeMultas = $paginador->getCollection()
                ->map(fn (Fine $multa): FineListItemData => FineListItemData::fromModel($multa))
                ->values();
        } else {
            $paginador = $expedientes->query($filtros)->paginate(self::POR_PAGINA)->withQueryString();
            $filasDeExpedientes = $paginador->getCollection()
                ->map(fn (FineCase $expediente): FineCaseListItemData => FineCaseListItemData::fromModel($expediente))
                ->values();
        }

        return Inertia::render('multas/index', [
            'vista' => $vista,
            'multas' => $filasDeMultas,
            'expedientes' => $filasDeExpedientes,
            'filters' => $filtros,
            'canRegister' => $request->user()?->can('multas.registrar') ?? false,
            'canImport' => config('multas.historical_import_enabled', false) && ($request->user()?->can('multas.importar') ?? false),
            // La forma que espera `PaginationFooter`.
            'pagination' => Arr::only($paginador->toArray(), ['current_page', 'last_page', 'from', 'to', 'total', 'links']),
        ]);
    }

    /**
     * El listado con los mismos filtros, en una planilla: una fila por multa.
     *
     * Los importes van escritos, como en pantalla: pasarlos a número de
     * Excel sería meterles un `float`.
     */
    public function export(FineIndexRequest $request, FineListQuery $listado): StreamedResponse
    {
        return SpreadsheetWriter::download(
            sprintf('multas-%s.xlsx', BusinessDate::today()->format('Y-m-d')),
            [
                'Expediente',
                'Carátula',
                'Persona',
                'Documentos de origen',
                'Fecha de registro',
                'Importe vigente',
                'Pagado en el sistema',
                'Pagado antes del sistema',
                'Saldo',
                'Excedente',
                'Estado',
                'Procedencia',
            ],
            $this->filasDelExporte($listado, $request->filters()),
        );
    }

    /**
     * El alta.
     *
     * Mientras se escribe el número de expediente, la pantalla pregunta por
     * él con una recarga parcial: si ya está cargado, la multa se le agrega y
     * se ofrecen sus documentos; si hay otro con el mismo número y otro
     * sufijo, se avisa (P03).
     */
    public function create(Request $request): Response
    {
        $numero = FineCaseNumber::parse($request->string('expediente')->toString());
        $existente = $numero === null ? null : CreateFineCase::existing($numero);

        return Inertia::render('multas/create', [
            'existente' => $existente === null ? null : FineCaseData::fromModel($existente->load('documents')),
            'similares' => $numero === null || $existente !== null ? [] : $this->similares($numero),
            'fijo' => false,
            'preseleccion' => ['fixed' => false, 'resolutionId' => null, 'actIds' => []],
        ]);
    }

    /**
     * Agregar una multa desde la ficha del expediente.
     *
     * Es el mismo formulario con el expediente fijo: no se escribe ni se
     * cambia, y el alta lo identifica por id —un expediente importado puede
     * no tener el número normalizado que el campo pediría—.
     */
    public function createForCase(Request $request, FineCase $fineCase): Response
    {
        $fineCase->load('documents');

        return Inertia::render('multas/create', [
            'existente' => FineCaseData::fromModel($fineCase),
            'similares' => [],
            'fijo' => true,
            'preseleccion' => $this->preseleccion($request, $fineCase),
        ]);
    }

    /**
     * Los documentos fijos que trae el «+» de la ficha del expediente: el de
     * una resolución, esa resolución sin actas; el de un grupo, su resolución
     * y sus actas; el de un documento sin multa, ese documento.
     *
     * Viajan en la consulta (`?resolucion=…&actas[]=…`) y se filtran contra
     * los documentos del expediente y su tipo: lo que no corresponde se
     * descarta. Si no queda nada, el formulario es el libre de siempre. Si
     * queda, la multa se carga con exactamente esos documentos: las actas no
     * se cambian ni se agregan, y la resolución tampoco si vino una —con un
     * acta sola, la resolución se elige o se escribe—. `StoreFineRequest`
     * rechaza un alta que no los respete.
     *
     * @return array{fixed: bool, resolutionId: int|null, actIds: list<int>}
     */
    private function preseleccion(Request $request, FineCase $fineCase): array
    {
        $documentos = $fineCase->documents->keyBy('id');
        $resolucion = $request->integer('resolucion');
        $actas = array_map(intval(...), array_filter((array) $request->query('actas', []), is_numeric(...)));

        $resolucionId = ($documentos->get($resolucion)?->kind === FineDocumentKind::Resolution) ? $resolucion : null;
        $actIds = array_values(array_unique(array_filter(
            $actas,
            fn (int $id): bool => $documentos->get($id)?->kind === FineDocumentKind::Act,
        )));

        return [
            'fixed' => $resolucionId !== null || $actIds !== [],
            'resolutionId' => $resolucionId,
            'actIds' => $actIds,
        ];
    }

    public function store(StoreFineRequest $request, RegisterFine $registrar): RedirectResponse
    {
        try {
            $multa = $registrar->handle($request->validated(), $request->user()?->id);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'caseNumber' => 'Otro operador acaba de registrar ese expediente. Volvé a guardar: la multa se le va a agregar.',
            ]);
        }

        Toast::success('Multa registrada.');

        return to_route('multas.show', $multa->showParameters());
    }

    /**
     * El expediente va en la firma aunque no se use: la ruta lo trae antes que
     * la multa, y es el que acota la búsqueda de la multa por su número.
     */
    public function show(Request $request, FineCase $fineCase, Fine $fine, Attachments $adjuntos, FineBankCredits $creditos, FineDeposits $depositos, FineAppeals $recursos, FineCashReadiness $preparacion): Response
    {
        $fine->load([
            'fineCase',
            'fineCase.documents',
            'person:id,name,document,type',
            'documents',
            'revokedDocuments',
            'balance',
            'creator:id,name',
            'debtEntries.document',
            'debtEntries.reversedBy',
            'debtEntries.creator:id,name',
            'historicalMovements.creator:id,name',
            'historicalMovements.reviewer:id,name',
            'historicalMovements.bankTransaction',
            'payments.application.fundReceipt',
            'payments.paymentReceipt.receipt',
            'payments.creator:id,name',
            'payments.voiding',
            'payments.appeal',
            'payments.correctedBy.newPayment.fine',
            'payments.correctionOf.correctedPayment.fine',
        ]);

        $usuario = $request->user();
        $cobra = $usuario?->can('multas.cobrar') ?? false;
        $verifica = $usuario?->can('multas.verificar-pagos') ?? false;
        $deposita = $usuario?->can('multas.depositar') ?? false;
        $emiteRecibos = $cobra || $verifica;
        $cajaAbierta = $this->cajaAbierta($fine);
        $recurso = $recursos->current($fine)?->load('currentVerdict.resolver:id,name');
        $pagoDelRecurso = $recurso === null ? null : $fine->payments->first(
            fn (FinePayment $pago): bool => $pago->fine_appeal_id === $recurso->id && ! in_array($pago->status, [FinePaymentStatus::Discarded, FinePaymentStatus::Voided], true),
        );

        return Inertia::render('multas/show', [
            'multa' => FineDetailData::fromModel(
                $fine,
                $adjuntos->allFor(AttachmentSubject::Fine, [$fine->id], $request->user())[$fine->id] ?? [],
                $adjuntos->allFor(AttachmentSubject::FineHistoricalMovement, array_values($fine->historicalMovements->modelKeys()), $request->user()),
                $adjuntos->allFor(AttachmentSubject::FinePayment, array_values($fine->payments->modelKeys()), $request->user()),
                $depositos->forPayments($fine->payments, $request->user()),
            ),
            'documentosDelExpediente' => $fine->fineCase->documents
                ->sortBy('id')
                ->map(fn (FineDocument $documento): FineDocumentData => FineDocumentData::fromModel($documento))
                ->values(),
            // Los papeles del expediente sin documento propio; la multa los muestra igual.
            'adjuntosDelExpediente' => $adjuntos->allFor(AttachmentSubject::FineCase, [$fine->fine_case_id], $request->user())[$fine->fine_case_id] ?? [],
            // La foto de cada resolución y de cada acta de la multa, por id del documento.
            'adjuntosDeDocumentos' => $adjuntos->allFor(AttachmentSubject::FineDocument, array_values($fine->documents->modelKeys()), $request->user()),
            /*
             * Los créditos viejos de la cuenta de Multas, para respaldar un
             * pago anterior. Se piden con una recarga parcial al buscar.
             */
            'creditosDelBanco' => Inertia::optional(fn (): array => $creditos->search($request->string('credito')->toString())),
            /*
             * Los créditos desde la apertura de la caja que nadie
             * identificó, para verificar un pago por el banco. También al buscar.
             */
            'creditosDisponibles' => Inertia::optional(fn (): array => $creditos->available($request->string('disponible')->toString())),
            /*
             * Las multas a las que puede pasar un pago verificado, y el crédito
             * que ya lo respalda, para corregirlo. También al buscar.
             */
            'multasDestino' => Inertia::optional(fn (): array => $this->multasDestino($request->string('multaDestino')->toString(), $fine)),
            'creditosActuales' => Inertia::optional(fn (): array => $creditos->backing($fine)),
            /*
             * El 20 % para recurrir, calculado sobre el importe vigente de hoy
             * (M07), y el trámite. Es el anticipo de la pantalla: lo que vale
             * lo decide el Action, con la multa bloqueada.
             */
            'recurso' => [
                'base' => $fine->balance->obligation ?? '0.00',
                'required' => AppealAmount::required($fine->balance->obligation ?? '0'),
                // El recurso es el primer pago: con deuda, sin pagos ni otro recurso que no haya caído.
                'allowed' => $recurso === null
                    && ! Decimal::equals($fine->balance->debt ?? '0', '0')
                    && ! $recursos->hasPayments($fine),
                // El recurso y el pago que se ven: el veredicto se da sobre estos.
                'id' => $recurso?->id,
                'paymentId' => $pagoDelRecurso?->id,
                'status' => $recurso?->status,
                'paidAmount' => $pagoDelRecurso?->amount,
                'paymentApplied' => $pagoDelRecurso?->status === FinePaymentStatus::Applied,
                // El veredicto vigente: revertirlo se pide sobre este.
                'verdictId' => $recurso?->currentVerdict?->id,
                'verdictByName' => $recurso?->currentVerdict?->resolver?->name,
                'verdictAt' => $recurso?->currentVerdict?->resolved_at->toIso8601String(),
            ],
            /*
             * Quienes pueden firmar el recibo, con su cargo, como en Haberes.
             * Opcional: sin firmante el pie queda para firmar a mano.
             */
            'firmantes' => $emiteRecibos ? User::query()
                ->where('is_active', true)
                ->whereNotNull('position')
                ->orderBy('name')
                ->get(['id', 'name', 'position']) : [],
            'cobroKey' => 'multa-cobro:'.$fine->id.':'.Str::ulid(),
            /*
             * La caja sin apertura: sin esto cobrar, depositar y verificar
             * faltaban sin explicación.
             */
            'cajaPendiente' => $preparacion->pending(
                $usuario,
                Currency::tryFrom($fine->currency) ?? Currency::Ars,
                affected: $cobra || $verifica || $deposita,
            ),
            // Las listas de motivos de cada acto: la pantalla no las repite.
            'motivos' => FineReasonAct::options(),
            'can' => [
                'register' => $usuario?->can('multas.registrar') ?? false,
                // Cobrar exige la caja abierta en la moneda de la multa.
                'collect' => $cobra && $cajaAbierta,
                // Informar lo que llega por el banco: el mostrador o la conciliación.
                'reportBank' => $cobra || $verifica,
                // Verificar contra el extracto exige la caja abierta con su cuenta.
                'verifyBank' => $verifica && $cajaAbierta && $creditos->book() !== null,
                // Depositar lo cobrado y confirmar su acreditación, con la cuenta del libro.
                'deposit' => $deposita && $creditos->book() !== null,
                'confirmDeposit' => $verifica,
                // El rechazo y lo que trae el extracto, de quien concilia; el reemplazo, de la caja.
                'manageRejections' => $usuario?->can('multas.gestionar-rechazos') ?? false,
                'replaceCheque' => $cobra,
                'resolveAppeal' => $cobra,
                'revertVerdict' => $cobra,
                'voidCollection' => $usuario?->can('multas.anular-cobros') ?? false,
                // Corregir exige la caja abierta con su cuenta, como verificar.
                'correctPayment' => ($usuario?->can('multas.corregir-pagos') ?? false) && $cajaAbierta && $creditos->book() !== null,
                'adjust' => $usuario?->can('multas.ajustar') ?? false,
                // Corregir el importe sin resolución, y revertir esa corrección.
                'correctAmount' => $usuario?->can('multas.corregir-importe') ?? false,
                'replaceOrigin' => $usuario?->can('multas.reemplazar-origen') ?? false,
                // Sin la caja abierta con su cuenta no se sabe qué crédito es viejo.
                'linkBankCredit' => $creditos->book() !== null,
            ],
        ]);
    }

    /**
     * Completar o corregir carátula, persona y fecha de registro.
     */
    public function revise(ReviseFineRecordRequest $request, Fine $fine, ReviseFineIdentifiers $revisar): RedirectResponse
    {
        $fecha = PartialDate::parse($request->validated('registeredOn')) ?? PartialDate::unknown();
        $fechaVista = PartialDate::parse($request->validated('expected.registeredOn')) ?? PartialDate::unknown();

        $revision = $revisar->handle(
            $fine,
            [
                'caption' => $request->validated('caption'),
                'person_id' => $request->validated('personId'),
                'registered_on' => $fecha->date,
                'registered_precision' => $fecha->precision->value,
            ],
            [
                'caption' => $request->validated('expected.caption'),
                'person_id' => $request->validated('expected.personId'),
                'registered_on' => $fechaVista->date,
                'registered_precision' => $fechaVista->precision->value,
            ],
            Reason::fromValidated($request->validated(), FineReasonAct::Correction),
            $request->canCorrect(),
            $request->user()?->id,
        );

        Toast::success($revision === null ? 'No había nada que cambiar.' : 'Datos de la multa actualizados.');

        return to_route('multas.show', $fine->showParameters());
    }

    /**
     * @param  array{q: string, estado: string, anio: int|null}  $filtros
     * @return Generator<int, list<string>>
     */
    private function filasDelExporte(FineListQuery $listado, array $filtros): Generator
    {
        foreach ($listado->chunks($filtros) as $bloque) {
            foreach ($bloque as $multa) {
                $fila = FineListItemData::fromModel($multa);
                $saldo = $fila->balance;

                yield [
                    $fila->caseLabel,
                    $fila->caption,
                    $fila->personName ?? '',
                    implode(' · ', $fila->originDocuments),
                    PartialDate::fromStored($multa->registered_on?->toDateString(), $multa->registered_precision)->toDisplay(),
                    Decimal::format($saldo->obligation),
                    Decimal::format($saldo->paid),
                    Decimal::format($saldo->historicalPaid),
                    Decimal::format($saldo->debt),
                    Decimal::format($saldo->excess),
                    $saldo->status->label(),
                    $fila->provenance->label(),
                ];
            }
        }
    }

    /**
     * Las multas con deuda a las que puede pasar un pago, por expediente o carátula.
     *
     * @return list<FineCorrectionTargetData>
     */
    private function multasDestino(string $termino, Fine $actual): array
    {
        $termino = trim($termino);

        if (mb_strlen($termino) < 2) {
            return [];
        }

        return array_values(Fine::query()
            ->with(['fineCase', 'balance'])
            ->whereKeyNot($actual->id)
            ->where('currency', $actual->currency)
            ->whereHas('balance', fn ($saldo) => $saldo->where('debt', '>', 0))
            ->where(fn ($consulta) => $consulta
                ->where('caption', 'ilike', Like::contains($termino))
                ->orWhereHas('fineCase', fn ($expediente) => $expediente->where('original_text', 'ilike', Like::contains($termino))))
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Fine $multa): FineCorrectionTargetData => new FineCorrectionTargetData(
                id: $multa->id,
                caption: $multa->caption,
                caseLabel: $multa->fineCase->label(),
                debt: $multa->balance->debt ?? '0.00',
            ))
            ->all());
    }

    /** Sin la apertura en la moneda de la multa no hay libro donde asentar el cobro. */
    private function cajaAbierta(Fine $multa): bool
    {
        $caja = CashBox::query()->where('code', CashBox::MULTAS)->value('id');
        $moneda = Currency::tryFrom($multa->currency);

        return $caja !== null && $moneda !== null && CashBookOpening::for((int) $caja, $moneda) !== null;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function similares(FineCaseNumber $numero): array
    {
        return array_values(FineCase::query()
            ->where('number', $numero->number)
            ->where('year', $numero->year)
            ->get(['id', 'original_text', 'number', 'year', 'suffix'])
            ->map(fn (FineCase $otro): array => ['id' => $otro->id, 'label' => $otro->label()])
            ->all());
    }
}
