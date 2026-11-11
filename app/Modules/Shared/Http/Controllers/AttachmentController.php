<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\PurgeAttachment;
use App\Modules\Shared\Actions\RenameAttachment;
use App\Modules\Shared\Actions\VoidAttachment;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Http\Requests\AttachmentHistoryRequest;
use App\Modules\Shared\Http\Requests\PurgeAttachmentRequest;
use App\Modules\Shared\Http\Requests\RenameAttachmentRequest;
use App\Modules\Shared\Http\Requests\VoidAttachmentRequest;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Support\Attachments;
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

    /**
     * Todos los archivos de algo, anulados incluidos, para el historial.
     *
     * Lo piden las pantallas que muestran un solo archivo: con el único
     * anulado, es el camino para ver el motivo. Cada archivo dice si quien
     * mira lo puede abrir.
     *
     * @return list<AttachmentData>
     */
    public function history(AttachmentHistoryRequest $request, Attachments $adjuntos): array
    {
        $type = $request->validated('type');

        return $adjuntos->historyOf(
            AttachmentSubject::from((string) $request->validated('subject')),
            (int) $request->validated('id'),
            is_string($type) ? $type : null,
            $request->user(),
        );
    }

    /**
     * Elimina del todo un adjunto ya anulado. El archivo se borra del disco
     * después del commit, y si falla se reintenta.
     */
    public function purge(PurgeAttachmentRequest $request, Attachment $attachment, PurgeAttachment $eliminar): RedirectResponse
    {
        Gate::authorize('purge', $attachment);

        $eliminar->handle($attachment, $request->reason(), $request->user());

        Toast::success('Se eliminó el archivo.', 'En la auditoría queda qué era y por qué se eliminó.');

        return back();
    }

    /** Anula un respaldo opcional: queda guardado, fuera de la vista. */
    public function void(VoidAttachmentRequest $request, Attachment $attachment, VoidAttachment $anular): RedirectResponse
    {
        Gate::authorize('void', $attachment);

        $anular->handle($attachment, $request->reason(), $request->user());

        Toast::success('Se anuló el archivo.', 'Queda guardado y se ve en el historial.');

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
