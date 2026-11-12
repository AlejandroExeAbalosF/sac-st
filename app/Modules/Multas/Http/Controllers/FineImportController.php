<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Actions\ResolveFineImportCell;
use App\Modules\Multas\Actions\ResolveFineImportIssue;
use App\Modules\Multas\Actions\ResolveFineImportIssueInBulk;
use App\Modules\Multas\Actions\StartFineImport;
use App\Modules\Multas\Actions\StartFineImportConsolidation;
use App\Modules\Multas\Data\FineImportBatchData;
use App\Modules\Multas\Data\FineImportCellData;
use App\Modules\Multas\Data\FineImportRowData;
use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportCellStatus;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Http\Requests\ResolveFineImportCellRequest;
use App\Modules\Multas\Http\Requests\ResolveFineImportIssueRequest;
use App\Modules\Multas\Http\Requests\StoreFineImportRequest;
use App\Modules\Multas\Import\FineImportReadyRows;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportCell;
use App\Modules\Multas\Models\FineImportRow;
use App\Modules\Shared\Models\CashBox;
use App\Support\Excel\SpreadsheetWriter;
use App\Support\Ui\Toast;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La importación de la planilla histórica de Multas (Etapa 9).
 *
 * Subir lee el archivo en la cola. La pantalla del lote muestra la
 * conciliación por hoja, las filas para revisar con cada problema en su
 * columna, los pagos posteriores a la apertura para identificar y lo
 * importado. Consolidar es un paso explícito.
 */
final class FineImportController extends Controller implements HasMiddleware
{
    private const PESTANAS = ['conciliacion', 'revision', 'pendientes', 'completar', 'importadas', 'excluidas'];

    /** En pausa (`multas.historical_import_enabled`): ninguna ruta responde. */
    public static function middleware(): array
    {
        return [
            new Middleware(function (Request $request, Closure $next) {
                abort_unless(config('multas.historical_import_enabled', false), 404);

                return $next($request);
            }),
        ];
    }

    public function index(): Response
    {
        $lotes = FineImportBatch::query()->with('uploader:id,name')->latest('id')->paginate(20);

        return Inertia::render('multas/importaciones/index', [
            'lotes' => $lotes->through(fn (FineImportBatch $lote): FineImportBatchData => FineImportBatchData::fromModel($lote, $this->cuentas($lote))),
            'aperturaHecha' => CashBookOpening::for((int) CashBox::query()->where('code', CashBox::MULTAS)->value('id'), Currency::Ars) !== null,
        ]);
    }

    public function store(StoreFineImportRequest $request, StartFineImport $iniciar): RedirectResponse
    {
        $archivo = $request->file('file');
        abort_unless($archivo !== null, 422);

        $lote = $iniciar->handle($archivo, $request->user()?->id);

        Toast::success('Planilla subida.', 'Se está leyendo hoja por hoja: la pantalla se actualiza sola.');

        return to_route('multas.importaciones.show', $lote);
    }

    public function show(Request $request, FineImportBatch $batch, FineImportReadyRows $listas): Response
    {
        $pestana = in_array($request->string('pestana')->toString(), self::PESTANAS, true) ? $request->string('pestana')->toString() : 'revision';
        $hoja = $request->string('hoja')->toString();
        $problema = FineImportIssue::tryFrom($request->string('problema')->toString());
        $batch->load('uploader:id,name');

        return Inertia::render('multas/importaciones/show', [
            'lote' => FineImportBatchData::fromModel($batch, $this->cuentas($batch)),
            'pestana' => $pestana,
            'filtros' => ['hoja' => $hoja, 'problema' => $problema?->value],
            'filas' => in_array($pestana, ['pendientes', 'conciliacion'], true) ? null : $this->filas($batch, $pestana, $hoja, $problema)
                ->with(['resolutions.resolver:id,name', 'fine.fineCase'])
                ->orderBy('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (FineImportRow $fila): FineImportRowData => FineImportRowData::fromModel($fila)),
            'celdas' => $pestana !== 'pendientes' ? null : FineImportCell::query()
                ->with('row.fine.fineCase')
                ->whereHas('row', fn (Builder $q) => $q->where('fine_import_batch_id', $batch->id)->when($hoja !== '', fn ($h) => $h->where('sheet', $hoja)))
                ->orderByRaw("status = 'pending' DESC")
                ->orderBy('id')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (FineImportCell $celda): FineImportCellData => FineImportCellData::fromModel($celda)),
            // Lo que se puede resolver en bloque y cuántas filas lo esperan.
            'enBloque' => $this->enBloque($batch),
            'puedeConsolidar' => $listas->of($batch)->exists(),
            'problemas' => array_map(fn (FineImportIssue $p): array => ['value' => $p->value, 'label' => $p->label(), 'blocking' => $p->blocking()], FineImportIssue::cases()),
        ]);
    }

    public function resolve(ResolveFineImportIssueRequest $request, FineImportRow $row, ResolveFineImportIssue $resolver): RedirectResponse
    {
        $datos = $request->validated();

        $resolver->handle(
            $row,
            FineImportIssue::from((string) $datos['issue']),
            FineImportAction::from((string) $datos['action']),
            $datos['reason'] ?? null,
            $request->user()?->id,
            isset($datos['fineCaseId']) ? (int) $datos['fineCaseId'] : null,
        );

        Toast::success('Decisión registrada.');

        return back();
    }

    public function resolveInBulk(ResolveFineImportIssueRequest $request, FineImportBatch $batch, ResolveFineImportIssueInBulk $resolver): RedirectResponse
    {
        $datos = $request->validated();

        $cantidad = $resolver->handle(
            $batch,
            FineImportIssue::from((string) $datos['issue']),
            FineImportAction::from((string) $datos['action']),
            $datos['reason'] ?? null,
            $request->user()?->id,
        );

        Toast::success("Decisión registrada en {$cantidad} filas.");

        return back();
    }

    public function resolveCell(ResolveFineImportCellRequest $request, FineImportCell $cell, ResolveFineImportCell $resolver): RedirectResponse
    {
        $datos = $request->validated();

        $resolver->handle($cell, FineImportCellStatus::from((string) $datos['status']), (string) $datos['note'], $request->user()?->id);

        Toast::success('Pago marcado.');

        return back();
    }

    public function consolidate(Request $request, FineImportBatch $batch, StartFineImportConsolidation $consolidar): RedirectResponse
    {
        $consolidar->handle($batch, $request->user()?->id);

        Toast::success('Consolidando.', 'Se lleva al sistema de a tandas: la pantalla se actualiza sola.');

        return back();
    }

    /** La lista de Revisión: cada problema de cada fila, con su columna, para trabajarla con el área. */
    public function export(FineImportBatch $batch): StreamedResponse
    {
        return SpreadsheetWriter::download(
            sprintf('importacion-multas-%d-revision.xlsx', $batch->id),
            ['Hoja', 'Fila', 'Columna', 'Valor', 'Problema', 'Bloquea', 'Detalle', 'Expediente', 'Resolución', 'Carátula', 'Importe', 'Decisión', 'Motivo'],
            $this->filasDeRevision($batch),
            "Revisión de la importación N.º {$batch->id} ({$batch->original_filename})",
        );
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function filasDeRevision(FineImportBatch $lote): Generator
    {
        $consulta = FineImportRow::query()
            ->with('resolutions')
            ->where('fine_import_batch_id', $lote->id)
            ->where('kind', 'fine')
            ->whereRaw('jsonb_array_length(issues) > 0')
            ->orderBy('id');

        foreach ($consulta->lazyById(500) as $fila) {
            $normal = $fila->normalized;

            foreach ($fila->issues as $problema) {
                $codigo = FineImportIssue::from($problema['code']);
                $decision = $fila->resolutions->first(fn ($r) => $r->issue_code === $codigo);

                yield [
                    $fila->sheet,
                    (string) $fila->row_number,
                    (string) ($problema['column'] ?? ''),
                    (string) ($problema['value'] ?? ''),
                    $codigo->label(),
                    $codigo->blocking() ? 'Sí' : 'No',
                    $problema['message'],
                    (string) ($normal['case_text'] ?? ''),
                    (string) ($normal['resolution_text'] ?? ''),
                    (string) ($normal['caption'] ?? ''),
                    (string) ($normal['amount'] ?? ''),
                    $decision?->action->label() ?? '',
                    (string) ($decision->reason ?? ''),
                ];
            }
        }
    }

    /**
     * @return Builder<FineImportRow>
     */
    private function filas(FineImportBatch $lote, string $pestana, string $hoja, ?FineImportIssue $problema): Builder
    {
        $base = FineImportRow::query()
            ->where('fine_import_batch_id', $lote->id)
            ->where('kind', 'fine')
            ->when($hoja !== '', fn (Builder $q) => $q->where('sheet', $hoja))
            ->when($problema !== null, fn (Builder $q) => $q->whereJsonContains('issues', [['code' => $problema?->value]]));

        return match ($pestana) {
            'completar' => $base->whereNotNull('fine_id')->whereRaw(<<<'SQL'
                EXISTS (SELECT 1 FROM jsonb_array_elements(issues) i
                         WHERE NOT (i->>'blocking')::boolean AND i->>'code' NOT IN ('unchanged', 'after_cutoff'))
            SQL),
            'importadas' => $base->whereNotNull('fine_id'),
            'excluidas' => $base->whereHas('resolutions', fn (Builder $q) => $q->where('action', FineImportAction::Exclude)),
            // Revisión: lo que bloquea, sin consolidar ni excluir, resuelto o no.
            default => $base->whereNull('fine_id')
                ->whereDoesntHave('resolutions', fn (Builder $q) => $q->where('action', FineImportAction::Exclude))
                ->where(fn (Builder $q) => $q->whereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements(issues) i WHERE (i->>'blocking')::boolean)")
                    ->orWhereNotNull('failure')),
        };
    }

    /**
     * @return array{rows: int, blocked: int, ready: int, consolidated: int, excluded: int, failed: int, unchanged: int, pendingCells: int}
     */
    private function cuentas(FineImportBatch $lote): array
    {
        $filas = FineImportRow::query()->where('fine_import_batch_id', $lote->id)->where('kind', 'fine');
        $listas = app(FineImportReadyRows::class)->of($lote)->count();
        $excluidas = (clone $filas)->whereHas('resolutions', fn (Builder $q) => $q->where('action', FineImportAction::Exclude))->count();
        $consolidadas = (clone $filas)->whereNotNull('fine_id')->count();
        $sinCambios = (clone $filas)->whereNull('fine_id')->whereJsonContains('issues', [['code' => FineImportIssue::Unchanged->value]])
            ->whereRaw("NOT EXISTS (SELECT 1 FROM jsonb_array_elements(issues) i WHERE (i->>'blocking')::boolean)")->count();
        $total = (clone $filas)->count();

        return [
            'rows' => $total,
            'blocked' => max(0, $total - $consolidadas - $excluidas - $listas - $sinCambios),
            'ready' => $listas,
            'consolidated' => $consolidadas,
            'excluded' => $excluidas,
            'failed' => (clone $filas)->whereNull('fine_id')->whereNotNull('failure')->count(),
            'unchanged' => $sinCambios,
            'pendingCells' => FineImportCell::query()->where('status', FineImportCellStatus::Pending)
                ->whereHas('row', fn (Builder $q) => $q->where('fine_import_batch_id', $lote->id))->count(),
        ];
    }

    /**
     * @return list<array{code: string, label: string, rows: int, actions: list<array{value: string, label: string}>}>
     */
    private function enBloque(FineImportBatch $lote): array
    {
        $bloques = [];

        foreach (FineImportIssue::cases() as $problema) {
            if (! $problema->bulk()) {
                continue;
            }

            $filas = FineImportRow::query()
                ->where('fine_import_batch_id', $lote->id)
                ->whereNull('fine_id')
                ->whereJsonContains('issues', [['code' => $problema->value]])
                ->whereDoesntHave('resolutions', fn (Builder $q) => $q->where('issue_code', $problema->value))
                ->count();

            if ($filas > 0) {
                $bloques[] = [
                    'code' => $problema->value,
                    'label' => $problema->label(),
                    'rows' => $filas,
                    'actions' => array_map(fn (FineImportAction $a): array => ['value' => $a->value, 'label' => $a->label()], $problema->actions()),
                ];
            }
        }

        return $bloques;
    }
}
