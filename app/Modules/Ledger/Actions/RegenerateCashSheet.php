<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Excel\CashSheetArchivist;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vuelve a dibujar la planilla de un cierre, sin tocar sus números.
 *
 * **Separa dos cosas que el circuito confundía.** Si cambian los números
 * hay que reabrir el período, recontar y cerrar: eso es contabilidad. Si
 * lo que cambió es el dibujo —un arreglo del generador, una fila que
 * faltaba— los números del cierre siguen siendo los correctos y reabrir un
 * período que está bien, con su rastro de auditoría, sería pagar un precio
 * contable por un problema de papel.
 *
 * Sin esta salida, un error de dibujo quedaba horneado para siempre en
 * todos los cierres ya exportados.
 *
 * **No sobrescribe: versiona.** `attachments` rechaza toda edición por
 * trigger, y con razón — ese Excel pudo imprimirse y firmarse. La planilla
 * anterior queda donde está y el cierre pasa a apuntar a la nueva, que es
 * el mismo criterio del reabrir y volver a cerrar.
 *
 * Exige un motivo y lo deja en `audit_events`: rehacer un documento
 * oficial tiene que poder explicarse después.
 */
final class RegenerateCashSheet
{
    public function __construct(
        private readonly CashSheetArchivist $archivist,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /** @throws ValidationException */
    public function handle(PeriodClosing $closing, int $actorId, string $reason): Attachment
    {
        $motivo = trim($reason);

        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo tiene que explicar por qué se rehace una planilla ya emitida: '
                    .'un par de palabras no alcanzan para entenderlo dentro de un año.',
            ]);
        }

        if ($closing->status !== PeriodClosingStatus::Closed) {
            throw ValidationException::withMessages([
                'status' => 'La planilla se emite de un período cerrado. Este todavía no lo está.',
            ]);
        }

        if ($closing->sheet_attachment_id === null) {
            throw ValidationException::withMessages([
                'status' => 'Este cierre todavía no tiene planilla: pedila una primera vez en vez de rehacerla.',
            ]);
        }

        return DB::transaction(function () use ($closing, $actorId, $motivo): Attachment {
            $anterior = $closing->sheet_attachment_id;

            $adjunto = $this->archivist->archive($closing, $actorId);

            $this->recordAuditEvent->handle(
                action: 'planilla.regenerada',
                subject: $closing,
                before: ['sheet_attachment_id' => $anterior],
                after: ['sheet_attachment_id' => (int) $adjunto->id],
                metadata: [
                    'motivo' => $motivo,
                    'version_del_dibujo' => CashSheetWorkbook::VERSION,
                ],
                actorId: $actorId,
            );

            return $adjunto;
        });
    }
}
