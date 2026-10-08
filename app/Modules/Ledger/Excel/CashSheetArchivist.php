<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Excel;

use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Dibuja la planilla de un cierre y la archiva como una versión nueva.
 *
 * **Vive fuera de los Actions porque la usan dos.** `ExportCashSheet` la
 * llama la primera vez y `RegenerateCashSheet` cuando el dibujo cambió;
 * los dos necesitan exactamente el mismo archivo, y tener el código en uno
 * solo evitaría que se separen el día que alguien toque el nombre del
 * archivo o las hojas que entran.
 *
 * Exportar reutiliza el adjunto vigente; regenerar crea uno nuevo.
 * Ambas operaciones bloquean la caja y comprueban la versión del cierre
 * antes de elegir o escribir el archivo. Los adjuntos anteriores se conservan.
 */
final class CashSheetArchivist
{
    public const DOCUMENT_TYPE = 'planilla_de_caja';

    public function __construct(
        private readonly CashSheetEvidence $evidence,
        private readonly CashSheetWorkbook $workbook,
        private readonly StoreAttachment $storeAttachment,
    ) {}

    /** Dibuja, guarda y deja el cierre apuntando a la planilla nueva. */
    public function archive(PeriodClosing $closing, ?int $actorId, bool $regenerate = false): Attachment
    {
        return DB::transaction(function () use ($closing, $actorId, $regenerate): Attachment {
            CashBox::query()->lockForUpdate()->findOrFail($closing->cash_box_id);
            $current = PeriodClosing::query()->lockForUpdate()->findOrFail($closing->id);
            if ($current->status !== PeriodClosingStatus::Closed || $current->evidence_version !== $closing->evidence_version) {
                throw ValidationException::withMessages(['status' => 'El cierre cambió mientras se preparaba la planilla. Actualizá la pantalla.']);
            }
            if (! $regenerate && $current->sheet_attachment_id !== null) {
                return Attachment::query()->findOrFail($current->sheet_attachment_id);
            }
            $attachment = $this->write($current, $actorId);
            $closing->refresh();

            return $attachment;
        });
    }

    private function write(PeriodClosing $closing, ?int $actorId): Attachment
    {
        $closing->loadMissing('cashBox');

        $planillas = $this->evidence->workbook($closing);

        $nombre = $this->filename($closing);
        $temporal = $this->temporaryPath();

        try {
            $this->workbook->write($planillas, $temporal);

            $adjunto = $this->storeAttachment->handle(
                file: new UploadedFile(
                    path: $temporal,
                    originalName: $nombre,
                    mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    /*
                     * `test: true` — el archivo lo produjo el sistema, no
                     * una subida HTTP. Sin esto, `UploadedFile` rechaza
                     * cualquier ruta que no venga de PHP como una posible
                     * manipulación, que es la protección correcta para un
                     * upload y un estorbo para un documento generado.
                     */
                    test: true,
                ),
                subject: AttachmentSubject::PeriodClosing,
                subjectId: (int) $closing->getKey(),
                documentType: self::DOCUMENT_TYPE,
                userId: $actorId,
                title: $nombre,
                source: AttachmentSource::Generated,
                /*
                 * Con qué versión del dibujo se hizo.
                 *
                 * Es lo que permite saber, mirando el adjunto, si la
                 * planilla que alguien tiene guardada la dibujó el
                 * generador de hoy o uno anterior. Sin esta marca la
                 * desactualización es invisible: el archivo se ve igual de
                 * oficial. Va en el alta porque un adjunto no se edita.
                 */
                templateVersion: CashSheetWorkbook::VERSION,
            );

            $closing->forceFill(['sheet_attachment_id' => $adjunto->id])->save();

            return $adjunto;
        } finally {
            // El adjunto ya se copió al disco privado; el temporal sobra
            // aunque la escritura haya fallado a la mitad.
            if (is_file($temporal)) {
                @unlink($temporal);
            }
        }
    }

    private function filename(PeriodClosing $closing): string
    {
        $caja = mb_strtoupper($closing->cashBox->name);

        $periodo = $closing->period_type === PeriodType::Monthly
            ? mb_strtoupper($closing->period_from->translatedFormat('F Y'))
            : $closing->period_from->format('d-m-Y');

        return sprintf('%s %s %s.xlsx', $caja, $periodo, $closing->currency->value);
    }

    private function temporaryPath(): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'planilla-');

        if ($ruta === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal de la planilla.');
        }

        return $ruta;
    }
}
