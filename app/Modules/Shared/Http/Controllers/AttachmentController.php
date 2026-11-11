<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\RenameAttachment;
use App\Modules\Shared\Http\Requests\RenameAttachmentRequest;
use App\Modules\Shared\Models\Attachment;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve los archivos guardados.
 *
 * Los adjuntos viven en el disco privado (`storage/app/private`), fuera de
 * la raíz pública: no hay URL adivinable que los exponga. Esta es la única
 * puerta, y comprueba permisos antes de abrirla: los de `AttachmentPolicy`,
 * los mismos que la pantalla consulta para saber si puede pedir el archivo.
 */
final class AttachmentController extends Controller
{
    /** Corrige la descripción; el archivo queda igual. */
    public function rename(RenameAttachmentRequest $request, Attachment $attachment, RenameAttachment $renombrar): RedirectResponse
    {
        Gate::authorize('rename', $attachment);

        $renombrar->handle($attachment, $request->string('title')->toString(), $request->user());

        Toast::success('Se corrigió la descripción del archivo.');

        return back();
    }

    /** Descarga el archivo. */
    public function download(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        return Storage::disk($attachment->storage_disk)
            ->download($attachment->object_key, $attachment->original_filename);
    }

    /**
     * Lo muestra en pantalla en lugar de descargarlo.
     *
     * Es lo que permite poner la foto del ticket al lado del formulario
     * mientras se copian los datos. Solo para imágenes y PDF: ofrecer
     * «ver» un `.xls` abriría una descarga igual, con el rodeo de una
     * pestaña en blanco.
     */
    public function preview(Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        abort_unless($attachment->isViewableInline(), 404);

        return Storage::disk($attachment->storage_disk)->response(
            $attachment->object_key,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                /*
                 * `nosniff` para que el navegador no reinterprete el tipo:
                 * un archivo subido por un usuario no debería poder
                 * ejecutarse como otra cosa por el camino.
                 */
                'X-Content-Type-Options' => 'nosniff',
            ],
            /*
             * `inline` para que el navegador lo muestre. La cabecera la
             * arma Symfony y no una concatenación: un nombre con tildes o
             * con ñ necesita `filename*` en UTF-8 y un respaldo en ASCII.
             */
            'inline',
        );
    }
}
