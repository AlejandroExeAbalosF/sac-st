<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\FineImportCellStatus;
use App\Modules\Multas\Models\FineImportCell;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un pago de la planilla desde la apertura, para identificar a mano. */
#[TypeScript]
final class FineImportCellData extends Data
{
    public function __construct(
        public int $id,
        public string $sheet,
        public int $row,
        public string $column,
        public string $month,
        /** @var numeric-string */
        public string $amount,
        public string $caption,
        public ?int $fineId,
        public ?string $fineUrl,
        public FineImportCellStatus $status,
        public ?string $note,
    ) {}

    /**
     * Espera cargada `row.fine.fineCase`.
     */
    public static function fromModel(FineImportCell $celda): self
    {
        $fila = $celda->row;

        return new self(
            id: $celda->id,
            sheet: $fila->sheet,
            row: $fila->row_number,
            column: $celda->column,
            month: $celda->month->toDateString(),
            amount: $celda->amount,
            caption: (string) ($fila->normalized['caption'] ?? ''),
            fineId: $fila->fine_id,
            fineUrl: $fila->fine === null ? null : route('multas.show', $fila->fine->showParameters()),
            status: $celda->status,
            note: $celda->note,
        );
    }
}
