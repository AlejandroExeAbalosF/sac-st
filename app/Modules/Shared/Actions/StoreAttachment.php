<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\Confidentiality;
use App\Modules\Shared\Models\Attachment;
use App\Support\Image\ImageNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Guarda un archivo y lo deja registrado.
 *
 * Un único camino para que todo archivo del sistema entre igual: al disco
 * privado, con su huella calculada y su fila en `attachments`. Si cada
 * módulo guardara por su cuenta, cada uno elegiría su carpeta y su forma
 * de nombrar, y el día que haya que mover el almacenamiento habría que
 * buscar cuántas variantes se inventaron.
 */
final class StoreAttachment
{
    /**
     * El disco privado: `storage/app/private`, fuera de la raíz pública.
     *
     * Un adjunto se sirve por una ruta que comprueba permisos —ver
     * `AttachmentController`—, nunca por una URL adivinable.
     */
    private const DISK = 'local';

    public function __construct(
        private readonly ImageNormalizer $images,
    ) {}

    public function handle(
        UploadedFile $file,
        AttachmentSubject $subject,
        int $subjectId,
        string $documentType,
        ?int $userId,
        ?string $title = null,
        AttachmentSource $source = AttachmentSource::Uploaded,
        Confidentiality $confidentiality = Confidentiality::Internal,
        /*
         * Con qué versión de la plantilla se dibujó, cuando el archivo lo
         * produjo el sistema. Va en el alta y no después: el trigger
         * rechaza toda edición sobre `attachments`, así que un adjunto no
         * puede enterarse más tarde de cómo se hizo.
         */
        ?string $templateVersion = null,
    ): Attachment {
        /*
         * Lo que trae una persona se normaliza antes de calcular la huella:
         * el sha256 tiene que ser el del archivo que queda guardado, no el
         * de uno que se descartó. Lo que dibujó el sistema ya sale como
         * tiene que salir.
         */
        $normalized = $source === AttachmentSource::Generated
            ? $file
            : $this->images->normalize($file);

        try {
            return $this->persist(
                $normalized, $subject, $subjectId, $documentType, $userId,
                $title, $source, $confidentiality, $templateVersion,
            );
        } finally {
            if ($normalized !== $file) {
                @unlink($normalized->getPathname());
            }
        }
    }

    private function persist(
        UploadedFile $file,
        AttachmentSubject $subject,
        int $subjectId,
        string $documentType,
        ?int $userId,
        ?string $title,
        AttachmentSource $source,
        Confidentiality $confidentiality,
        ?string $templateVersion,
    ): Attachment {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular la huella del archivo.');
        }

        /*
         * Una carpeta por tipo de sujeto. No es cosmético: cuando haya que
         * revisar qué ocupa lugar, o mover los extractos a otro
         * almacenamiento, la separación ya está hecha.
         */
        $objectKey = $file->store($subject->value, self::DISK);

        if ($objectKey === false) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }

        return Attachment::query()->create([
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'document_type' => $documentType,
            'title' => mb_substr($title ?? $file->getClientOriginalName(), 0, 200),
            'storage_disk' => self::DISK,
            'object_key' => $objectKey,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            // El tipo declarado por el cliente no se usa: lo deduce el
            // servidor del contenido, que es lo que después decide si el
            // archivo se muestra en pantalla o se descarga.
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'sha256' => $hash,
            'source' => $source,
            'confidentiality' => $confidentiality,
            'template_version' => $templateVersion,
            'created_by' => $userId,
        ]);
    }

    /**
     * Borra el registro del adjunto y devuelve el archivo que quedó huérfano.
     *
     * Existe para una sola situación: revertir la importación de un
     * extracto. Si el extracto se borra, su archivo tiene que irse con él
     * o quedaría un adjunto apuntando a un sujeto que ya no existe.
     *
     * El borrado lo hace `forget_statement_attachment()`, una función de la
     * base que corre como dueña de la tabla y solo acepta archivos de
     * extractos. La app no puede borrar un adjunto por su cuenta: antes le
     * alcanzaba con `SET LOCAL sacst.allow_attachment_delete`, que cualquier
     * rol puede fijar, y por ahí se iba el PDF de cualquier recibo.
     *
     * **No borra el archivo**, lo devuelve. Si esta transacción termina
     * volviendo atrás, la fila reaparece y el archivo tiene que seguir
     * estando; eliminarlo acá dejaría un adjunto apuntando a la nada.
     * Quien llama lo borra después del commit, con `deleteFile()`.
     *
     * @return array{disk: string, key: string} el archivo pendiente de borrar
     */
    public function forget(Attachment $attachment): array
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException(
                'Borrar un adjunto exige una transacción: el archivo se elimina recién después '
                .'de confirmarla, y sin ella no hay «después».'
            );
        }

        if ($attachment->subject_type !== AttachmentSubject::Import) {
            throw new RuntimeException(
                'Solo se borra el archivo de un extracto importado, al revertir la importación.'
            );
        }

        $pendiente = [
            'disk' => $attachment->storage_disk,
            'key' => $attachment->object_key,
        ];

        DB::select('SELECT forget_statement_attachment(?)', [$attachment->id]);

        return $pendiente;
    }

    /**
     * @param  array{disk: string, key: string}  $pendiente
     */
    public function deleteFile(array $pendiente): void
    {
        Storage::disk($pendiente['disk'])->delete($pendiente['key']);
    }
}
