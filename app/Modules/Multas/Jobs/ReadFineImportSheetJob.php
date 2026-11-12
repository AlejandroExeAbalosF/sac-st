<?php

declare(strict_types=1);

namespace App\Modules\Multas\Jobs;

use App\Modules\Multas\Actions\ReadFineImportSheet;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Models\FineImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Lee una hoja de un lote. Una hoja tarda segundos; si el trabajo se repite,
 * la hoja ya leída no se vuelve a leer.
 */
final class ReadFineImportSheetJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * Por debajo del `retry_after` de la cola (90 s): si no, otro intento toma el
     * job mientras este sigue. Con el archivo real, una hoja o una tanda tarda
     * menos de 6 s.
     */
    public int $timeout = 60;

    public function __construct(public readonly int $batchId, public readonly string $sheet) {}

    public function handle(ReadFineImportSheet $leer): void
    {
        $leer->handle(FineImportBatch::query()->findOrFail($this->batchId), $this->sheet);
    }

    /** Una hoja que no se puede leer deja el lote fallado, con el motivo. */
    public function failed(?Throwable $excepcion): void
    {
        FineImportBatch::query()
            ->whereKey($this->batchId)
            ->where('status', FineImportStatus::Reading)
            ->update([
                'status' => FineImportStatus::Failed,
                'failure' => mb_substr("Hoja {$this->sheet}: ".($excepcion?->getMessage() ?? 'no se pudo leer.'), 0, 2000),
            ]);
    }
}
