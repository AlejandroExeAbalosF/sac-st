<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Enums\Confidentiality;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve los archivos guardados.
 *
 * Los adjuntos viven en el disco privado (`storage/app/private`), fuera de
 * la raíz pública: no hay URL adivinable que los exponga. Esta es la única
 * puerta, y comprueba permisos antes de abrirla.
 */
final class AttachmentController extends Controller
{
    /** Descarga el archivo. */
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->assertCanSee($request, $attachment);

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
    public function preview(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->assertCanSee($request, $attachment);

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

    private function assertCanSee(Request $request, Attachment $attachment): void
    {
        if ($attachment->confidentiality === Confidentiality::Restricted) {
            abort_unless(
                $request->user()?->can('adjuntos.ver-reservados') ?? false,
                403,
                'Este archivo es reservado.',
            );
        }
    }
}
