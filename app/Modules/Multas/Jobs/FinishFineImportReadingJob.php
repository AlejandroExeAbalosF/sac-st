<?php

declare(strict_types=1);

namespace App\Modules\Multas\Jobs;

use App\Modules\Multas\Actions\FinishFineImportReading;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Models\FineImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/** Agrega los cruces entre hojas y con la base, y deja el lote listo para revisar. */
final class FinishFineImportReadingJob implements ShouldQueue
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

    public function __construct(public readonly int $batchId) {}

    public function handle(FinishFineImportReading $terminar): void
    {
        $terminar->handle(FineImportBatch::query()->findOrFail($this->batchId));
    }

    public function failed(?Throwable $excepcion): void
    {
        FineImportBatch::query()
            ->whereKey($this->batchId)
            ->where('status', FineImportStatus::Reading)
            ->update([
                'status' => FineImportStatus::Failed,
                'failure' => mb_substr('Al cruzar las hojas: '.($excepcion?->getMessage() ?? 'error desconocido.'), 0, 2000),
            ]);
    }
}
