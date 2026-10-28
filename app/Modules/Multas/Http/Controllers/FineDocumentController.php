<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\LinkFineDocument;
use App\Modules\Multas\Actions\ReplaceFineOriginResolution;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Actions\RevokeFineDocumentLink;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Http\Requests\ReasonRequest;
use App\Modules\Multas\Http\Requests\ReplaceOriginResolutionRequest;
use App\Modules\Multas\Http\Requests\ReviseFineRecordRequest;
use App\Modules\Multas\Http\Requests\StoreFineDocumentRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Multas\Support\PartialDate;
use App\Modules\Shared\Support\Reason;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Los documentos de una multa: vincularlos con su papel, desvincularlos y
 * completar o corregir cómo se leyeron.
 */
final class FineDocumentController extends Controller
{
    /**
     * Vincula un documento del expediente —o uno nuevo— con la multa. Es lo
     * que registra una ratificación (M04).
     */
    public function store(StoreFineDocumentRequest $request, Fine $fine, LinkFineDocument $vincular): RedirectResponse
    {
        $id = $request->validated('documentId');

        if ($id !== null) {
            $documento = FineDocument::query()->find((int) $id);

            if (! $documento instanceof FineDocument) {
                throw ValidationException::withMessages(['documentId' => 'Ese documento no existe.']);
            }
        } else {
            /** @var array{kind?: string, actType?: string|null, text?: string, issuedOn?: string|null} $datos */
            $datos = (array) $request->validated('document');
            $documento = NewDocument::fromArray($datos, FineDocumentKind::from((string) ($datos['kind'] ?? '')));
        }

        $archivo = $request->file('file');

        $vincular->handle(
            $fine,
            $documento,
            FineDocumentRole::from((string) $request->validated('role')),
            $request->user()?->id,
            $archivo instanceof UploadedFile ? $archivo : null,
        );

        Toast::success('Documento vinculado a la multa.');

        return to_route('multas.show', $fine->showParameters());
    }

    /**
     * Desvincula un documento cargado por error: el vínculo queda anulado,
     * con su motivo, y el documento sigue en el expediente.
     */
    public function revoke(ReasonRequest $request, Fine $fine, FineDocument $fineDocument, FineDocumentRole $role, RevokeFineDocumentLink $desvincular): RedirectResponse
    {
        $desvincular->handle($fine, $fineDocument, $role, $request->reason(), $request->user()?->id);

        Toast::success('Documento desvinculado de la multa.');

        return to_route('multas.show', $fine->showParameters());
    }

    /**
     * Reemplaza la resolución de origen cuando se cargó la equivocada: el
     * importe original pasa a citar la nueva y la vieja queda desvinculada.
     */
    public function replaceOrigin(ReplaceOriginResolutionRequest $request, Fine $fine, ReplaceFineOriginResolution $reemplazar): RedirectResponse
    {
        $id = $request->validated('documentId');

        if ($id !== null) {
            $documento = FineDocument::query()->find((int) $id);

            if (! $documento instanceof FineDocument) {
                throw ValidationException::withMessages(['documentId' => 'Ese documento no existe.']);
            }
        } else {
            /** @var array{text?: string, issuedOn?: string|null} $datos */
            $datos = (array) $request->validated('document');
            $documento = NewDocument::fromArray($datos, FineDocumentKind::Resolution);
        }

        $archivo = $request->file('file');

        $nueva = $reemplazar->handle(
            $fine,
            $documento,
            $request->reason(),
            $request->user()?->id,
            $archivo instanceof UploadedFile ? $archivo : null,
        );

        Toast::success('Resolución de origen reemplazada.', "La multa nace ahora de {$nueva->label()}.");

        return to_route('multas.show', $fine->showParameters());
    }

    /**
     * Completar o corregir tipo de acta, número, año y fecha. El texto del
     * papel no cambia.
     */
    public function revise(ReviseFineRecordRequest $request, FineDocument $fineDocument, ReviseFineIdentifiers $revisar): RedirectResponse
    {
        $fecha = PartialDate::parse($request->validated('issuedOn')) ?? PartialDate::unknown();
        $fechaVista = PartialDate::parse($request->validated('expected.issuedOn')) ?? PartialDate::unknown();

        $cambios = [
            'number' => $request->validated('number'),
            'year' => $request->validated('year'),
            'issued_on' => $fecha->date,
            'issued_precision' => $fecha->precision->value,
        ];

        $esperados = [
            'number' => $request->validated('expected.number'),
            'year' => $request->validated('expected.year'),
            'issued_on' => $fechaVista->date,
            'issued_precision' => $fechaVista->precision->value,
        ];

        // El tipo solo existe en un acta; en otro documento no se toca.
        if ($fineDocument->kind === FineDocumentKind::Act) {
            $cambios['act_type'] = $request->validated('actType');
            $esperados['act_type'] = $request->validated('expected.actType');
        }

        $revision = $revisar->handle(
            $fineDocument,
            $cambios,
            $esperados,
            Reason::fromValidated($request->validated(), FineReasonAct::Correction),
            $request->canCorrect(),
            $request->user()?->id,
        );

        Toast::success($revision === null ? 'No había nada que cambiar.' : 'Datos del documento actualizados.');

        return back();
    }
}
