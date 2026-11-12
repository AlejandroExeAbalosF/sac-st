<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Import\HistoricalWorkbook;
use App\Modules\Multas\Jobs\FinishFineImportReadingJob;
use App\Modules\Multas\Jobs\ReadFineImportSheetJob;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Sube la planilla histórica de Multas y la deja leyéndose en la cola.
 *
 * El corte es la apertura de la caja de Multas: sin apertura no se sabe desde
 * cuándo un pago ya tiene que estar en el sistema, así que no se importa. La
 * apertura se hace una sola vez, así que el corte no cambia entre la lectura y
 * la consolidación.
 *
 * Se lee una hoja por trabajo, para que ninguno se acerque al límite de tiempo
 * del worker, y un último trabajo agrega los cruces entre hojas y con la base.
 * El archivo queda como adjunto del lote.
 */
final class StartFineImport
{
    public function __construct(
        private readonly HistoricalWorkbook $libro,
        private readonly StoreAttachment $adjuntar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(UploadedFile $archivo, ?int $actorId): FineImportBatch
    {
        if (! config('multas.historical_import_enabled', false)) {
            throw ValidationException::withMessages(['file' => 'La importación de la planilla histórica está en pausa.']);
        }

        $ruta = $archivo->getRealPath();

        if ($ruta === false) {
            throw ValidationException::withMessages(['file' => 'No se pudo leer el archivo subido.']);
        }

        try {
            $hojas = $this->libro->yearSheets($ruta);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'No se pudo abrir la planilla: tiene que ser un Excel (.xls o .xlsx).']);
        }

        if ($hojas === []) {
            throw ValidationException::withMessages(['file' => 'La planilla no tiene hojas de año (2007, 2008…): no es la cuenta corriente de Multas.']);
        }

        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');
        $apertura = CashBookOpening::for($caja, Currency::Ars);

        if ($apertura === null) {
            throw ValidationException::withMessages(['file' => 'Primero hay que abrir la caja de Multas: su apertura es el corte de la importación.']);
        }

        $hash = (string) hash_file('sha256', $ruta);
        $guardado = null;

        try {
            $lote = DB::transaction(function () use ($archivo, $apertura, $hash, $actorId, $hojas, &$guardado): FineImportBatch {
                $lote = FineImportBatch::query()->create([
                    'original_filename' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                    'file_sha256' => $hash,
                    'file_size' => (int) $archivo->getSize(),
                    'reader_version' => HistoricalWorkbook::VERSION,
                    'cutoff_month' => $apertura->opened_on->copy()->startOfMonth()->toDateString(),
                    'status' => FineImportStatus::Reading,
                    'uploaded_by' => $actorId,
                ]);

                $guardado = $this->adjuntar->handle(
                    file: $archivo,
                    subject: AttachmentSubject::FineImport,
                    subjectId: $lote->id,
                    documentType: 'fine_import_workbook',
                    userId: $actorId,
                    title: $archivo->getClientOriginalName(),
                );

                $lote->forceFill(['attachment_id' => $guardado->id])->save();

                $this->auditar->handle('multa.importacion-subida', $lote, after: [
                    'original_filename' => $lote->original_filename,
                    'cutoff_month' => $lote->cutoff_month->toDateString(),
                ], metadata: ['hojas' => implode(', ', $hojas)], actorId: $actorId);

                // Recién con el lote confirmado: el trabajo lo busca por su id.
                DB::afterCommit(fn () => Bus::chain([
                    ...array_map(fn (string $hoja): ReadFineImportSheetJob => new ReadFineImportSheetJob($lote->id, $hoja), $hojas),
                    new FinishFineImportReadingJob($lote->id),
                ])->dispatch());

                return $lote;
            });
        } catch (Throwable $excepcion) {
            if ($guardado instanceof Attachment) {
                $this->adjuntar->deleteFile(['disk' => $guardado->storage_disk, 'key' => $guardado->object_key]);
            }

            throw $excepcion;
        }

        return $lote->refresh();
    }
}
