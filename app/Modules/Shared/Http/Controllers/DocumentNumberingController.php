<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Models\DocumentSeries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DocumentNumberingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('configuracion/numeracion', [
            'series' => DocumentSeries::query()->where('origin', 'system')->orderBy('code')->orderByDesc('id')->get()
                ->map(fn (DocumentSeries $series): array => [
                    'id' => $series->id, 'code' => $series->code, 'label' => $series->label,
                    'active' => $series->is_active, 'prefix' => $series->display_prefix ?? $series->code,
                    'prefixDigits' => $series->code_padding, 'numberDigits' => $series->number_padding,
                    'separator' => str_contains($series->format_pattern, '/') ? '/' : '-',
                    'nextNumber' => $series->next_number, 'preview' => $series->formatNumber($series->next_number),
                    'token' => $series->configurationToken(),
                    'lastNumber' => max(
                        (int) DB::table('receipts')->where('document_series_id', $series->id)->max('number'),
                        (int) DB::table('payment_orders')->where('document_series_id', $series->id)->max('number'),
                    ) ?: null,
                ])->values()->all(),
        ]);
    }

    public function update(Request $request, string $code, ConfigureDocumentNumbering $configure): RedirectResponse
    {
        $configure->handle($code, $request->all(), (int) $request->user()?->id);

        return back()->with('success', 'Numeración actualizada. Los comprobantes anteriores conservan su número.');
    }
}
