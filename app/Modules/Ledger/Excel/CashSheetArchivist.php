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
use Illuminate\Http\UploadedFile;
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
 * Cada llamada produce **un adjunto nuevo**. No hay forma de que sea de
 * otra manera: `attachments` tiene un trigger que rechaza toda edición
 * —«un adjunto no se edita: cada versión es un archivo nuevo»— y eso es lo
 * que protege al Excel que ya se imprimió y se firmó.
 */
final class CashSheetArchivist
{
    public const DOCUMENT_TYPE = 'planilla_de_caja';

    public function __construct(
        private readonly CashSheetBuilder $builder,
        private readonly CashSheetWorkbook $workbook,
        private readonly StoreAttachment $storeAttachment,
    ) {}

    /** Dibuja, guarda y deja el cierre apuntando a la planilla nueva. */
    public function archive(PeriodClosing $closing, ?int $actorId): Attachment
    {
        $closing->loadMissing('cashBox');

        $planillas = array_map(
            fn (PeriodClosing $cierre) => $this->builder->build($cierre),
            $this->sheetsFor($closing),
        );

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

    /**
     * Qué hojas entran en el libro.
     *
     * Un cierre diario es una planilla. Uno mensual son todas las del mes
     * más la suya, en orden — y solo las **cerradas**: un día sin cerrar no
     * tiene snapshot, y meterlo obligaría a recalcularlo, que es
     * exactamente lo que este circuito no hace.
     *
     * @return list<PeriodClosing>
     */
    private function sheetsFor(PeriodClosing $closing): array
    {
        if ($closing->period_type !== PeriodType::Monthly) {
            return [$closing];
        }

        $diarios = PeriodClosing::query()
            ->with('cashBox')
            ->where('cash_box_id', $closing->cash_box_id)
            ->where('currency', $closing->currency)
            ->where('period_type', PeriodType::Daily)
            ->where('status', PeriodClosingStatus::Closed)
            ->whereDate('period_from', '>=', $closing->period_from)
            ->whereDate('period_to', '<=', $closing->period_to)
            ->orderBy('period_from')
            ->get()
            ->all();

        return [...$diarios, $closing];
    }

    private function filename(PeriodClosing $closing): string
    {
        $caja = mb_strtoupper($closing->cashBox->name);

        $periodo = $closing->period_type === PeriodType::Monthly
            ? mb_strtoupper($closing->period_from->translatedFormat('F Y'))
            : $closing->period_from->format('d-m-Y');

        return sprintf('%s %s.xlsx', $caja, $periodo);
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
