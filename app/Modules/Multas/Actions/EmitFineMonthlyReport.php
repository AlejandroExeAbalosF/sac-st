<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Models\FineMonthlyReport;
use App\Modules\Multas\Pdf\FineRenditionPdf;
use App\Modules\Multas\Support\FineRendition;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite la rendición mensual de Multas (P02): los dos informes del mes —en
 * término y fuera de término—, congelados sobre la versión vigente del
 * cierre mensual, con su PDF.
 *
 * Una vez por versión del cierre: pedirla de nuevo devuelve la misma.
 * Reabrir el mes y volver a cerrarlo da otra versión del cierre, y con ella
 * otra rendición; la anterior queda.
 */
final class EmitFineMonthlyReport
{
    public const DOCUMENT_TYPE = 'rendicion_multas';

    public function __construct(
        private readonly FineRendition $rendicion,
        private readonly FineRenditionPdf $pdf,
        private readonly StoreAttachment $adjuntar,
        private readonly RecordAuditEvent $auditar,
        private readonly CashBoxBankAccounts $vinculos,
    ) {}

    /**
     * @return array{on_time: FineMonthlyReport, late: FineMonthlyReport}
     *
     * @throws ValidationException
     */
    public function handle(PeriodClosing $closing, ?int $actorId): array
    {
        return DB::transaction(function () use ($closing, $actorId): array {
            $cierre = PeriodClosing::query()->with('cashBox')->lockForUpdate()->findOrFail($closing->id);

            if ($cierre->cashBox->code !== CashBox::MULTAS || $cierre->period_type !== PeriodType::Monthly) {
                throw ValidationException::withMessages(['closing' => 'La rendición se emite sobre un cierre mensual de la caja de Multas.']);
            }

            if ($cierre->status !== PeriodClosingStatus::Closed) {
                throw ValidationException::withMessages(['closing' => 'El mes está abierto: se rinde con el mes cerrado.']);
            }

            $existentes = FineMonthlyReport::query()
                ->where('period_closing_id', $cierre->id)
                ->where('closing_version', $cierre->evidence_version)
                ->get()
                ->keyBy('kind');

            if ($existentes->count() === 2) {
                return ['on_time' => $existentes[FineMonthlyReport::ON_TIME], 'late' => $existentes[FineMonthlyReport::LATE]];
            }

            $informes = [];

            foreach ([FineMonthlyReport::ON_TIME => 'on_time', FineMonthlyReport::LATE => 'late'] as $kind => $clave) {
                $filas = $this->rendicion->rows($cierre, $kind);
                $total = array_reduce($filas, fn (string $suma, array $fila): string => Decimal::add($suma, $fila['amount']), '0.00');

                $informe = FineMonthlyReport::query()->create([
                    'period_closing_id' => $cierre->id,
                    'closing_version' => $cierre->evidence_version,
                    'kind' => $kind,
                    'payload' => $filas,
                    'accounts' => $this->cuentas($filas, (int) $cierre->cash_box_id),
                    'total' => $total,
                    'rows_count' => count($filas),
                    'emitted_by' => $actorId,
                    'emitted_at' => now(),
                ]);

                $informe->setRelation('closing', $cierre);
                $informe->forceFill(['attachment_id' => $this->archivar($informe, $actorId)])->save();
                $informes[$clave] = $informe;
            }

            $this->auditar->handle('multa.rendicion-emitida', $cierre, metadata: [
                'mes' => $cierre->period_from->format('m/Y'),
                'version' => $cierre->evidence_version,
                'en_termino' => $informes['on_time']->total,
                'fuera_de_termino' => $informes['late']->total,
            ], actorId: $actorId);

            return $informes;
        });
    }

    private function archivar(FineMonthlyReport $informe, ?int $actorId): int
    {
        $temporal = tempnam(sys_get_temp_dir(), 'rendicion');

        try {
            file_put_contents($temporal, $this->pdf->render($informe)->output());
            $nombre = $this->pdf->filename($informe);

            return (int) $this->adjuntar->handle(
                // `test: true`: el archivo lo produjo el sistema, no una subida.
                file: new UploadedFile($temporal, $nombre, 'application/pdf', test: true),
                subject: AttachmentSubject::PeriodClosing,
                subjectId: (int) $informe->period_closing_id,
                documentType: self::DOCUMENT_TYPE,
                userId: $actorId,
                title: $nombre,
                source: AttachmentSource::Generated,
            )->getKey();
        } finally {
            @unlink($temporal);
        }
    }

    /**
     * Las cuentas que cita la nota, congeladas con ella: las de sus filas, en
     * el orden en que aparecen. Una nota sin filas cita la predeterminada de
     * la caja al emitirla, para que el texto diga en qué cuenta no hubo nada.
     *
     * @param  list<array{accountId: int, account: string}>  $filas
     * @return list<array{id: int, citation: string}>
     */
    private function cuentas(array $filas, int $cajaId): array
    {
        $cuentas = [];

        foreach ($filas as $fila) {
            $cuentas[$fila['accountId']] ??= ['id' => $fila['accountId'], 'citation' => $fila['account']];
        }

        if ($cuentas === []) {
            $predeterminada = $this->vinculos->defaultFor($cajaId, Currency::Ars)?->bankAccount;

            if ($predeterminada !== null) {
                $cuentas[] = ['id' => $predeterminada->id, 'citation' => $predeterminada->document_citation ?? $predeterminada->label];
            }
        }

        return array_values($cuentas);
    }
}
