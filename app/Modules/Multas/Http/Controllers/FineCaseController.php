<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\AttachFineDocument;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Data\FineCaseData;
use App\Modules\Multas\Data\FineListItemData;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Http\Requests\ReviseFineRecordRequest;
use App\Modules\Multas\Http\Requests\StoreFineDocumentRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Support\FineAttachments;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Support\Ui\Toast;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El expediente de Multas: sus documentos y las multas que originó.
 */
final class FineCaseController extends Controller
{
    public function show(Request $request, FineCase $fineCase, FineAttachments $adjuntos): Response
    {
        $fineCase->load('documents');

        $multas = Fine::query()
            ->where('fine_case_id', $fineCase->id)
            ->with(['fineCase:id,original_text,number,year,suffix', 'person:id,name', 'documents', 'balance'])
            ->orderBy('id')
            ->get();

        $usuario = $request->user();

        return Inertia::render('multas/expediente', [
            'expediente' => FineCaseData::fromModel($fineCase),
            'multas' => $multas->map(fn (Fine $multa): FineListItemData => FineListItemData::fromModel($multa))->values(),
            // Las fotos de la resolución y de las actas: el expediente es dueño de esos papeles.
            'adjuntos' => $adjuntos->for(AttachmentSubject::FineCase, [$fineCase->id])[$fineCase->id] ?? [],
            'can' => [
                'register' => $usuario?->can('multas.registrar') ?? false,
                'adjust' => $usuario?->can('multas.ajustar') ?? false,
                'viewAttachments' => $usuario?->can('adjuntos.ver') ?? false,
            ],
        ]);
    }

    /**
     * Agrega una resolución, un acta u otro papel al expediente, antes o
     * después de cargar sus multas.
     */
    public function storeDocument(StoreFineDocumentRequest $request, FineCase $fineCase, AttachFineDocument $registrar): RedirectResponse
    {
        /** @var array{kind?: string, actType?: string|null, text?: string, issuedOn?: string|null} $datos */
        $datos = (array) $request->validated('document');

        $registrar->handle(
            $fineCase,
            NewDocument::fromArray($datos, FineDocumentKind::from((string) ($datos['kind'] ?? ''))),
            $request->user()?->id,
            'document.text',
        );

        Toast::success('Documento agregado al expediente.');

        return to_route('multas.expedientes.show', $fineCase);
    }

    /**
     * Completar o corregir número, año y sufijo. El texto del papel no cambia.
     */
    public function revise(ReviseFineRecordRequest $request, FineCase $fineCase, ReviseFineIdentifiers $revisar): RedirectResponse
    {
        try {
            $revision = $revisar->handle(
                $fineCase,
                [
                    'number' => $request->validated('number'),
                    'year' => $request->validated('year'),
                    'suffix' => $request->validated('suffix'),
                ],
                [
                    'number' => $request->validated('expected.number'),
                    'year' => $request->validated('expected.year'),
                    'suffix' => $request->validated('expected.suffix'),
                ],
                $request->validated('reason'),
                $request->canCorrect(),
                $request->user()?->id,
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'number' => 'Ya hay otro expediente con ese número, año y sufijo. Los expedientes no se fusionan desde acá.',
            ]);
        }

        Toast::success($revision === null ? 'No había nada que cambiar.' : 'Datos del expediente actualizados.');

        return to_route('multas.expedientes.show', $fineCase);
    }
}
