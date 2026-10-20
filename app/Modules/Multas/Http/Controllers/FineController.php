<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\CreateFineCase;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Data\FineCaseData;
use App\Modules\Multas\Data\FineDetailData;
use App\Modules\Multas\Data\FineDocumentData;
use App\Modules\Multas\Data\FineListItemData;
use App\Modules\Multas\Http\Requests\FineIndexRequest;
use App\Modules\Multas\Http\Requests\ReviseFineRecordRequest;
use App\Modules\Multas\Http\Requests\StoreFineRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Multas\Support\PartialDate;
use App\Support\Ui\Toast;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La cuenta corriente de multas: el listado, el alta y la ficha.
 *
 * Acá no se mueve un peso: se registra qué se debe y por qué papel. Los
 * cobros llegan en la Etapa 4.
 */
final class FineController extends Controller
{
    private const POR_PAGINA = 25;

    public function index(FineIndexRequest $request): Response
    {
        $filtros = [
            'q' => trim((string) $request->validated('q')),
            'estado' => (string) $request->validated('estado'),
            'anio' => $request->validated('anio') === null ? null : (int) $request->validated('anio'),
        ];

        $paginador = Fine::query()
            ->select('fines.*')
            ->join('fine_balances', 'fine_balances.fine_id', '=', 'fines.id')
            ->with([
                'fineCase:id,original_text,number,year,suffix',
                'person:id,name',
                'documents',
                'balance',
            ])
            ->buscar($filtros['q'])
            ->when($filtros['estado'] === 'con-deuda', fn ($query) => $query->where('fine_balances.debt', '>', 0))
            ->when($filtros['estado'] === 'sin-deuda', fn ($query) => $query->where('fine_balances.debt', '=', 0))
            ->when($filtros['anio'] !== null, fn ($query) => $query->whereYear('fines.registered_on', $filtros['anio']))
            // Lo último que se registró, primero; los históricos sin fecha, al final.
            ->orderByRaw('fines.registered_on DESC NULLS LAST')
            ->orderByDesc('fines.id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        return Inertia::render('multas/index', [
            'multas' => $paginador->getCollection()
                ->map(fn (Fine $multa): FineListItemData => FineListItemData::fromModel($multa))
                ->values(),
            'filters' => $filtros,
            'canRegister' => $request->user()?->can('multas.registrar') ?? false,
            // La forma que espera `PaginationFooter`.
            'pagination' => Arr::only($paginador->toArray(), ['current_page', 'last_page', 'from', 'to', 'total', 'links']),
        ]);
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
        ]);
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

        return to_route('multas.show', $multa);
    }

    public function show(Request $request, Fine $fine): Response
    {
        $fine->load([
            'fineCase',
            'fineCase.documents',
            'person:id,name,document,type',
            'documents',
            'balance',
            'creator:id,name',
            'debtEntries.document',
            'debtEntries.reversedBy',
            'debtEntries.creator:id,name',
            'historicalMovements.creator:id,name',
            'historicalMovements.reviewer:id,name',
        ]);

        $usuario = $request->user();

        return Inertia::render('multas/show', [
            'multa' => FineDetailData::fromModel($fine),
            'documentosDelExpediente' => $fine->fineCase->documents
                ->sortBy('id')
                ->map(fn (FineDocument $documento): FineDocumentData => FineDocumentData::fromModel($documento))
                ->values(),
            'can' => [
                'register' => $usuario?->can('multas.registrar') ?? false,
                'adjust' => $usuario?->can('multas.ajustar') ?? false,
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
            $request->validated('reason'),
            $request->canCorrect(),
            $request->user()?->id,
        );

        Toast::success($revision === null ? 'No había nada que cambiar.' : 'Datos de la multa actualizados.');

        return to_route('multas.show', $fine);
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
