<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Adjunta un archivo a un documento, un expediente, una multa o un pago.
 *
 * La foto de la resolución o del acta va en ese documento, que se comparte
 * entre expedientes y multas; un papel del expediente sin documento propio,
 * en el expediente; lo que respalda una multa en particular, en la multa;
 * el comprobante de un pago anterior, en el antecedente. No se pide
 * adjuntar nada para cargar (M11): se completa cuando aparece el papel.
 *
 * El archivo entra por `StoreAttachment`, como todos: disco privado,
 * validado por contenido, fotos normalizadas y huella del archivo guardado.
 * Un adjunto no se edita ni se borra.
 */
final class AttachFineFile
{
    public function __construct(
        private readonly StoreAttachment $adjuntos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(
        FineDocument|FineCase|Fine|FineHistoricalMovement|FinePayment $sujeto,
        UploadedFile $archivo,
        ?string $title,
        ?int $actorId,
    ): Attachment {
        $titulo = trim((string) $title) === '' ? null : trim((string) $title);
        $adjunto = null;

        try {
            return DB::transaction(function () use ($sujeto, $archivo, $titulo, $actorId, &$adjunto): Attachment {
                $adjunto = $this->adjuntos->handle(
                    file: $archivo,
                    subject: match (true) {
                        $sujeto instanceof FineDocument => AttachmentSubject::FineDocument,
                        $sujeto instanceof FineCase => AttachmentSubject::FineCase,
                        $sujeto instanceof Fine => AttachmentSubject::Fine,
                        $sujeto instanceof FinePayment => AttachmentSubject::FinePayment,
                        default => AttachmentSubject::FineHistoricalMovement,
                    },
                    subjectId: (int) $sujeto->getKey(),
                    documentType: match (true) {
                        $sujeto instanceof FineDocument => 'fine_document_paper',
                        $sujeto instanceof FineCase => 'fine_case_paper',
                        $sujeto instanceof Fine => 'fine_support',
                        $sujeto instanceof FinePayment => 'fine_payment_evidence',
                        default => 'fine_historical_evidence',
                    },
                    userId: $actorId,
                    title: $titulo,
                );

                // Los pagos se auditan sobre su multa: es donde se leen.
                $this->auditar->handle(
                    match (true) {
                        $sujeto instanceof FineDocument => 'documento-multa.adjunto-agregado',
                        $sujeto instanceof FineCase => 'expediente-multa.adjunto-agregado',
                        $sujeto instanceof Fine => 'multa.adjunto-agregado',
                        $sujeto instanceof FinePayment => 'multa.comprobante-de-pago-agregado',
                        default => 'multa.respaldo-de-antecedente-agregado',
                    },
                    $sujeto instanceof FineHistoricalMovement || $sujeto instanceof FinePayment
                        ? Fine::query()->findOrFail($sujeto->fine_id)
                        : $sujeto,
                    metadata: [
                        'titulo' => $adjunto->title,
                        'archivo' => $adjunto->original_filename,
                    ],
                    actorId: $actorId,
                );

                return $adjunto;
            });
        } catch (Throwable $exception) {
            // La fila volvió atrás: el archivo ya escrito quedaría huérfano.
            if ($adjunto instanceof Attachment) {
                $this->adjuntos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $exception;
        }
    }
}
