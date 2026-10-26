<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\AttachFineFile;
use App\Modules\Multas\Http\Requests\StoreFineAttachmentRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

/**
 * Los archivos de Multas: resoluciones y actas, papeles del expediente,
 * respaldos de la multa y comprobantes de pagos anteriores. Se ven por la puerta común de
 * adjuntos (`adjuntos.preview`), que comprueba permisos.
 */
final class FineAttachmentController extends Controller
{
    public function storeForCase(StoreFineAttachmentRequest $request, FineCase $fineCase, AttachFineFile $adjuntar): RedirectResponse
    {
        $adjuntar->handle($fineCase, $this->archivo($request), $request->validated('title'), $request->user()?->id);

        Toast::success('Archivo agregado al expediente.');

        return to_route('multas.expedientes.show', $fineCase);
    }

    /**
     * El archivo de una resolución o de un acta. El documento se comparte
     * entre expedientes y multas, así que se vuelve a la pantalla desde la
     * que se adjuntó.
     */
    public function storeForDocument(StoreFineAttachmentRequest $request, FineDocument $fineDocument, AttachFineFile $adjuntar): RedirectResponse
    {
        $adjuntar->handle($fineDocument, $this->archivo($request), $request->validated('title'), $request->user()?->id);

        Toast::success('Archivo agregado al documento.');

        return back();
    }

    public function storeForFine(StoreFineAttachmentRequest $request, Fine $fine, AttachFineFile $adjuntar): RedirectResponse
    {
        $adjuntar->handle($fine, $this->archivo($request), $request->validated('title'), $request->user()?->id);

        Toast::success('Archivo agregado a la multa.');

        return to_route('multas.show', $fine->showParameters());
    }

    public function storeForMovement(
        StoreFineAttachmentRequest $request,
        FineHistoricalMovement $movement,
        AttachFineFile $adjuntar,
    ): RedirectResponse {
        $adjuntar->handle($movement, $this->archivo($request), $request->validated('title'), $request->user()?->id);

        Toast::success('Comprobante agregado al pago anterior.');

        return to_route('multas.show', Fine::showParametersFor($movement->fine_id));
    }

    private function archivo(StoreFineAttachmentRequest $request): UploadedFile
    {
        $archivo = $request->file('file');

        // `ScannedDocument` ya exige un único archivo; esto lo dice al tipo.
        abort_unless($archivo instanceof UploadedFile, 422);

        return $archivo;
    }
}
