<?php

declare(strict_types=1);

namespace App\Modules\Multas\Jobs;

use App\Modules\Multas\Actions\ConsolidateFineImportChunk;
use App\Modules\Multas\Actions\FinishFineImportConsolidation;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Models\FineImportBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Una tanda de la consolidación. Al terminar encola la siguiente; sin filas
 * por delante, cierra el lote. Repetida, no duplica: cada fila consolidada se
 * saltea.
 */
final class ConsolidateFineImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Por debajo del `retry_after` de la cola (90 s): si no, otro intento toma el
     * job mientras este sigue. Con el archivo real, una hoja o una tanda tarda
     * menos de 6 s.
     */
    public int $timeout = 60;

    public function __construct(
        public readonly int $batchId,
        public readonly int $afterRowId,
        public readonly ?int $actorId,
    ) {}

    public function handle(ConsolidateFineImportChunk $tanda, FinishFineImportConsolidation $terminar): void
    {
        $lote = FineImportBatch::query()->findOrFail($this->batchId);

        if ($lote->status !== FineImportStatus::Consolidating) {
            return;
        }

        $ultima = $tanda->handle($lote, $this->afterRowId, $this->actorId);

        if ($ultima === null) {
            $terminar->handle($lote, $this->actorId);

            return;
        }

        self::dispatch($this->batchId, $ultima, $this->actorId);
    }
}
