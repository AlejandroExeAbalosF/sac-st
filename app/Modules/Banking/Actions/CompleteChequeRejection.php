<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ChequeDisposition;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lo que se completa del rechazo después de registrarlo (Multas M03): cuándo
 * se avisó a la empresa y qué se hizo con el papel —anulado y devuelto, o
 * documentado con un acta—.
 *
 * Cada dato se escribe una vez, con quién y cuándo lo registró, y con su
 * constancia: la disposición siempre, el aviso si hay papel. No reabre el
 * rechazo ni repite su asiento; se puede completar con la regularización ya
 * cerrada.
 */
final class CompleteChequeRejection
{
    public function __construct(private readonly StoreAttachment $attachments) {}

    /** @throws ValidationException */
    public function handle(
        ChequeRejection $rechazo,
        ?CarbonInterface $companyNotifiedOn,
        ?UploadedFile $notice,
        ?ChequeDisposition $disposition,
        ?UploadedFile $dispositionProof,
        ?int $actorId,
    ): ChequeRejection {
        /** @var list<Attachment> $adjuntos */
        $adjuntos = [];

        try {
            return DB::transaction(function () use (
                $rechazo, $companyNotifiedOn, $notice, $disposition, $dispositionProof, $actorId, &$adjuntos
            ): ChequeRejection {
                $rechazo = ChequeRejection::query()->lockForUpdate()->findOrFail($rechazo->id);

                if ($companyNotifiedOn === null && $disposition === null) {
                    throw ValidationException::withMessages(['companyNotifiedOn' => 'Completá el aviso a la empresa o qué se hizo con el cheque.']);
                }

                $cambios = [];

                if ($companyNotifiedOn !== null) {
                    if ($rechazo->company_notice_recorded_at !== null) {
                        throw ValidationException::withMessages(['companyNotifiedOn' => 'El aviso a la empresa ya está registrado.']);
                    }

                    if ($companyNotifiedOn->gt(BusinessDate::today()) || $companyNotifiedOn->lt($rechazo->rejected_on)) {
                        throw ValidationException::withMessages([
                            'companyNotifiedOn' => 'El aviso no puede ser futuro ni anterior al rechazo.',
                        ]);
                    }

                    $cambios += [
                        'company_notified_on' => $companyNotifiedOn->toDateString(),
                        'company_notice_recorded_by' => $actorId,
                        'company_notice_recorded_at' => now(),
                    ];
                }

                if ($disposition !== null) {
                    if ($rechazo->disposition_recorded_at !== null) {
                        throw ValidationException::withMessages(['disposition' => 'Lo que se hizo con el cheque ya está registrado.']);
                    }

                    if ($dispositionProof === null) {
                        throw ValidationException::withMessages([
                            'dispositionProof' => 'Adjuntá la constancia: la devolución firmada o el acta.',
                        ]);
                    }

                    $cambios += [
                        'disposition' => $disposition,
                        'disposition_recorded_by' => $actorId,
                        'disposition_recorded_at' => now(),
                    ];
                }

                $rechazo->forceFill($cambios)->save();

                if ($companyNotifiedOn !== null && $notice !== null) {
                    $adjuntos[] = $this->adjuntar($rechazo, $notice, 'cheque_rejection_company_notice', 'Aviso a la empresa', $actorId);
                }

                if ($disposition !== null) {
                    $adjuntos[] = $this->adjuntar($rechazo, $dispositionProof, 'cheque_rejection_disposition', $disposition->label(), $actorId);
                }

                return $rechazo;
            });
        } catch (Throwable $excepcion) {
            foreach ($adjuntos as $adjunto) {
                $this->attachments->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $excepcion;
        }
    }

    private function adjuntar(ChequeRejection $rechazo, UploadedFile $archivo, string $tipo, string $titulo, ?int $actorId): Attachment
    {
        return $this->attachments->handle(
            file: $archivo,
            subject: AttachmentSubject::ChequeRejection,
            subjectId: $rechazo->id,
            documentType: $tipo,
            userId: $actorId,
            title: $titulo,
            source: AttachmentSource::Scanned,
        );
    }
}
