<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Actions\EmitFineMonthlyReport;
use App\Modules\Multas\Models\FineMonthlyReport;
use App\Modules\Multas\Pdf\FineRenditionPdf;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La rendición mensual de Multas a la SAF (P02): por cada mes cerrado, sus
 * dos notas —en término y fuera de término—, una por versión del cierre.
 */
final class FineMonthlyReportController extends Controller
{
    public function index(Request $request): Response
    {
        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');

        $cierres = PeriodClosing::query()
            ->where('cash_box_id', $caja)
            ->where('period_type', PeriodType::Monthly)
            ->orderByDesc('period_from')
            ->limit(36)
            ->get();

        $informes = FineMonthlyReport::query()
            ->with('emitter:id,name')
            ->whereIn('period_closing_id', $cierres->modelKeys())
            ->orderByDesc('closing_version')
            ->get()
            ->groupBy('period_closing_id');

        return Inertia::render('multas/rendiciones', [
            'months' => $cierres->map(fn (PeriodClosing $cierre): array => [
                'closingId' => $cierre->id,
                'month' => $cierre->period_from->format('Y-m'),
                'status' => $cierre->status->value,
                'version' => $cierre->evidence_version,
                'reports' => ($informes[$cierre->id] ?? collect())->map(fn (FineMonthlyReport $i): array => [
                    'id' => $i->id,
                    'kind' => $i->kind,
                    'version' => $i->closing_version,
                    'current' => $i->closing_version === $cierre->evidence_version,
                    'total' => Decimal::scale($i->total),
                    'rows' => $i->rows_count,
                    'emittedAt' => $i->emitted_at->toIso8601String(),
                    'emittedBy' => $i->emitter?->name,
                ])->values()->all(),
            ])->values()->all(),
            'can' => ['emit' => $request->user()?->can('multas.rendicion.emitir') ?? false],
        ]);
    }

    public function store(Request $request, PeriodClosing $closing, EmitFineMonthlyReport $emitir): RedirectResponse
    {
        $emitir->handle($closing, $request->user()?->id);

        Toast::success('Rendición emitida.', 'Las dos notas del mes quedaron guardadas.');

        return back();
    }

    /** El papel en el visor: la misma plantilla que el PDF. */
    public function view(FineMonthlyReport $report, FineRenditionPdf $pdf): View
    {
        return $pdf->preview($report->load('closing'));
    }

    public function download(FineMonthlyReport $report, FineRenditionPdf $pdf): HttpResponse
    {
        $report->load('closing');

        return $pdf->render($report)->download($pdf->filename($report));
    }
}
