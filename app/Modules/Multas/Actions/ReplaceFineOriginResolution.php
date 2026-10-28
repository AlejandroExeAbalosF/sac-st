<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\FineRevisionKind;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineIdentifierRevision;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Support\Reason;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reemplaza la resolución de origen de una multa: la que se cargó era otro
 * papel, no una lectura equivocada —eso se corrige en el documento—.
 *
 * En una transacción:
 *
 * 1. el importe original pasa a citar la resolución nueva, con su fecha,
 *    mediante una revisión que guarda el valor anterior, el nuevo y el
 *    motivo;
 * 2. la resolución vieja pierde el papel de origen: el vínculo queda anulado,
 *    a la vista, con el mismo motivo; si tenía otros papeles, los conserva;
 * 3. la nueva queda vinculada como origen.
 *
 * El importe no cambia: si la resolución correcta dice otro monto, eso es un
 * ajuste. Los recibos ya emitidos tampoco: guardan el texto de su momento. La
 * base exige el resultado (`fine_debt_entries_keep_document_role`, una sola
 * resolución de origen); acá se dice qué falta.
 */
final class ReplaceFineOriginResolution
{
    public function __construct(
        private readonly AttachFineDocument $registrarDocumento,
        private readonly AttachFineFile $adjuntar,
        private readonly StoreAttachment $archivos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(Fine $multa, FineDocument|NewDocument $documento, Reason $motivo, ?int $actorId, ?UploadedFile $archivo = null): FineDocument
    {
        $adjunto = null;

        try {
            return $this->reemplazar($multa, $documento, $motivo, $actorId, $archivo, $adjunto);
        } catch (Throwable $exception) {
            // La fila volvió atrás: el archivo ya escrito quedaría huérfano.
            if ($adjunto instanceof Attachment) {
                $this->archivos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $exception;
        }
    }

    private function reemplazar(Fine $multa, FineDocument|NewDocument $documento, Reason $motivo, ?int $actorId, ?UploadedFile $archivo, ?Attachment &$adjunto): FineDocument
    {
        return DB::transaction(function () use ($multa, $documento, $motivo, $actorId, $archivo, &$adjunto): FineDocument {
            $multa = Fine::query()->with('fineCase')->whereKey($multa->id)->lockForUpdate()->firstOrFail();

            $importe = FineDebtEntry::query()
                ->where('fine_id', $multa->id)
                ->where('kind', FineDebtEntryKind::Constitution)
                ->first();

            $vieja = $importe?->fine_document_id === null ? null : FineDocument::query()->find($importe->fine_document_id);

            if (! $importe instanceof FineDebtEntry || ! $vieja instanceof FineDocument) {
                throw ValidationException::withMessages([
                    'documentId' => 'El importe original de esta multa no cita una resolución: vinculá la de origen desde «Vincular documento».',
                ]);
            }

            $nueva = $documento instanceof NewDocument
                ? $this->registrarDocumento->handle($multa->fineCase, $documento, $actorId, 'document.text')
                : $documento;

            if ($nueva->kind !== FineDocumentKind::Resolution) {
                throw ValidationException::withMessages(['documentId' => 'La multa se origina en una resolución, no en un acta.']);
            }

            if ($nueva->id === $vieja->id) {
                throw ValidationException::withMessages(['documentId' => 'Elegí una resolución distinta de la de origen actual.']);
            }

            if (! $multa->fineCase->documents()->whereKey($nueva->id)->exists()) {
                throw ValidationException::withMessages(['documentId' => 'Esa resolución no pertenece al expediente de la multa.']);
            }

            // La fecha del importe original es la de su resolución: una multa cargada a mano la exige exacta.
            if ($multa->provenance === FineProvenance::Manual && $nueva->issued_precision !== DatePrecision::Day) {
                throw ValidationException::withMessages([
                    $documento instanceof NewDocument ? 'document.issuedOn' : 'documentId' => 'La resolución nueva necesita su fecha exacta: es la fecha del importe original.',
                ]);
            }

            $anteriores = ['fine_document_id' => $vieja->id];
            $nuevos = ['fine_document_id' => $nueva->id];
            $fecha = $nueva->issued_on?->toDateString();

            if ($fecha !== $importe->effective_on?->toDateString() || $nueva->issued_precision !== $importe->effective_precision) {
                $anteriores += ['effective_on' => $importe->effective_on?->toDateString(), 'effective_precision' => $importe->effective_precision->value];
                $nuevos += ['effective_on' => $fecha, 'effective_precision' => $nueva->issued_precision->value];
            }

            FineIdentifierRevision::query()->create([
                'fine_debt_entry_id' => $importe->id,
                'kind' => FineRevisionKind::Correction,
                'old_values' => $anteriores,
                'new_values' => $nuevos,
                'reason_code' => $motivo->code,
                'reason' => $motivo->detail,
                'created_by' => $actorId,
            ]);

            DB::table('fine_document_links')
                ->where('fine_id', $multa->id)
                ->where('fine_document_id', $vieja->id)
                ->where('role', FineDocumentRole::Origin->value)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoked_by' => $actorId,
                    'revocation_reason_code' => $motivo->code->value,
                    'revocation_reason' => $motivo->detail,
                ]);

            $multa->documents()->attach($nueva->id, [
                'fine_case_id' => $multa->fine_case_id,
                'role' => FineDocumentRole::Origin->value,
                'created_by' => $actorId,
            ]);

            $this->auditar->handle(
                'multa.resolucion-de-origen-reemplazada',
                $multa,
                metadata: ['anterior' => $vieja->label(), 'nueva' => $nueva->label(), ...$motivo->auditMetadata()],
                actorId: $actorId,
            );

            if ($archivo instanceof UploadedFile) {
                $adjunto = $this->adjuntar->handle($nueva, $archivo, null, $actorId);
            }

            return $nueva;
        });
    }
}
