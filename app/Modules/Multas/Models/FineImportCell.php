<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Modules\Multas\Enums\FineImportCellStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un pago de la planilla desde el mes de la apertura de la caja. No se importa:
 * se identifica con su crédito o su cobro, y se marca. Una vez.
 *
 * @property int $id
 * @property int $fine_import_row_id
 * @property string $column
 * @property Carbon $month
 * @property numeric-string $amount
 * @property FineImportCellStatus $status
 * @property string|null $note
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property-read FineImportRow $row
 */
final class FineImportCell extends Model
{
    public $timestamps = false;

    protected $table = 'fine_import_cells';

    protected $fillable = [
        'fine_import_row_id',
        'column',
        'month',
        'amount',
        'status',
        'note',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'date',
            'amount' => 'decimal:2',
            'status' => FineImportCellStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FineImportRow, $this>
     */
    public function row(): BelongsTo
    {
        return $this->belongsTo(FineImportRow::class, 'fine_import_row_id');
    }
}
