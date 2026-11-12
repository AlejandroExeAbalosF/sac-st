<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Models\User;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Models\FineImportBatch;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un lote de la planilla histórica, con sus cuentas y la conciliación por hoja. */
#[TypeScript]
final class FineImportBatchData extends Data
{
    /**
     * @param  array{rows: int, blocked: int, ready: int, consolidated: int, excluded: int, failed: int, unchanged: int, pendingCells: int}  $counts
     * @param  list<array<string, mixed>>  $reconciliation  por hoja: sheet, rows, blocked, amount, paid, after_cutoff y footers (row, amount, paid, amount_matches, paid_matches)
     */
    public function __construct(
        public int $id,
        public string $filename,
        public FineImportStatus $status,
        public string $cutoffMonth,
        public ?string $uploadedByName,
        public ?string $createdAt,
        public ?string $consolidatedAt,
        public ?string $failure,
        public array $counts,
        public array $reconciliation,
    ) {}

    /**
     * @param  array{rows: int, blocked: int, ready: int, consolidated: int, excluded: int, failed: int, unchanged: int, pendingCells: int}  $cuentas
     */
    public static function fromModel(FineImportBatch $lote, array $cuentas): self
    {
        $autor = $lote->uploader;

        return new self(
            id: $lote->id,
            filename: $lote->original_filename,
            status: $lote->status,
            cutoffMonth: $lote->cutoff_month->toDateString(),
            uploadedByName: $autor instanceof User ? $autor->name : null,
            createdAt: $lote->created_at?->toIso8601String(),
            consolidatedAt: $lote->consolidated_at?->toIso8601String(),
            failure: $lote->failure,
            counts: $cuentas,
            reconciliation: $lote->reconciliation,
        );
    }
}
