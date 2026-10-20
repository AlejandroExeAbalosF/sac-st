<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\AdjustFineDebt;
use App\Modules\Multas\Actions\ReverseFineDebtEntry;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Http\Requests\AdjustFineDebtRequest;
use App\Modules\Multas\Http\Requests\ReasonRequest;
use App\Modules\Multas\Http\Requests\ReviseFineRecordRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Multas\Support\PartialDate;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Los ajustes de la obligación y su reversión.
 */
final class FineDebtController extends Controller
{
    public function store(AdjustFineDebtRequest $request, Fine $fine, AdjustFineDebt $ajustar): RedirectResponse
    {
        $id = $request->validated('documentId');

        if ($id !== null) {
            $documento = FineDocument::query()->find((int) $id);

            if (! $documento instanceof FineDocument) {
                throw ValidationException::withMessages(['documentId' => 'Esa resolución no existe.']);
            }
        } else {
            /** @var array{text?: string, issuedOn?: string|null} $datos */
            $datos = (array) $request->validated('document');
            $documento = NewDocument::fromArray($datos, FineDocumentKind::Resolution);
        }

        $ajustar->handle(
            $fine,
            FineDebtEntryKind::from((string) $request->validated('kind')),
            (string) $request->validated('amount'),
            CarbonImmutable::parse((string) $request->validated('effectiveOn')),
            $documento,
            (string) $request->validated('reason'),
            $request->user()?->id,
        );

        Toast::success('Ajuste registrado.');

        return to_route('multas.show', $fine);
    }

    public function reverse(ReasonRequest $request, FineDebtEntry $entry, ReverseFineDebtEntry $revertir): RedirectResponse
    {
        $revertir->handle($entry, (string) $request->validated('reason'), $request->user()?->id);

        Toast::success('Ajuste revertido.');

        return to_route('multas.show', $entry->fine_id);
    }

    /**
     * Completar o corregir la fecha de un movimiento: la de un importado que
     * llegó sin ella, cuando aparece el papel. Importe, tipo y efecto no se
     * tocan (revisión del 2026-10-20, R4).
     */
    public function reviseDate(ReviseFineRecordRequest $request, FineDebtEntry $entry, ReviseFineIdentifiers $revisar): RedirectResponse
    {
        $fecha = PartialDate::parse($request->validated('date')) ?? PartialDate::unknown();
        $fechaVista = PartialDate::parse($request->validated('expected.date')) ?? PartialDate::unknown();

        $revision = $revisar->handle(
            $entry,
            ['effective_on' => $fecha->date, 'effective_precision' => $fecha->precision->value],
            ['effective_on' => $fechaVista->date, 'effective_precision' => $fechaVista->precision->value],
            $request->validated('reason'),
            $request->canCorrect(),
            $request->user()?->id,
        );

        Toast::success($revision === null ? 'No había nada que cambiar.' : 'Fecha del movimiento actualizada.');

        return to_route('multas.show', $entry->fine_id);
    }
}
