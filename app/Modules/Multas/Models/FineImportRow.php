<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Modules\Multas\Enums\FineImportIssue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una fila de la planilla: lo que decía, cómo se leyó y qué problemas tiene,
 * cada uno con su columna. Lo que dijo la planilla no se edita; la base lo
 * impide. Se consolida una vez, cuando sus bloqueos están resueltos.
 *
 * @property int $id
 * @property int $fine_import_batch_id
 * @property string $sheet
 * @property int $row_number
 * @property string $kind `fine` o `footer`
 * @property array<string, array{v: string, f?: string, m?: bool, h?: string}> $cells
 * @property array<string, mixed> $normalized
 * @property string|null $identity_key
 * @property list<array{code: string, blocking: bool, column: string|null, value: string|null, message: string, meta?: array<string, mixed>}> $issues
 * @property int|null $previous_row_id
 * @property int|null $fine_id
 * @property int|null $consolidated_by
 * @property Carbon|null $consolidated_at
 * @property string|null $failure
 * @property Carbon|null $created_at
 * @property-read FineImportBatch $batch
 * @property-read Fine|null $fine
 */
final class FineImportRow extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fine_import_rows';

    protected $fillable = [
        'fine_import_batch_id',
        'sheet',
        'row_number',
        'kind',
        'cells',
        'normalized',
        'identity_key',
        'issues',
        'previous_row_id',
        'fine_id',
        'consolidated_by',
        'consolidated_at',
        'failure',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cells' => 'array',
            'normalized' => 'array',
            'issues' => 'array',
            'consolidated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FineImportBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FineImportBatch::class, 'fine_import_batch_id');
    }

    /**
     * @return BelongsTo<Fine, $this>
     */
    public function fine(): BelongsTo
    {
        return $this->belongsTo(Fine::class);
    }

    /**
     * @return HasMany<FineImportResolution, $this>
     */
    public function resolutions(): HasMany
    {
        return $this->hasMany(FineImportResolution::class);
    }

    /**
     * @return HasMany<FineImportCell, $this>
     */
    public function pendingCells(): HasMany
    {
        return $this->hasMany(FineImportCell::class);
    }

    /**
     * Los problemas que bloquean, sin repetir: una fila puede tener el mismo en varias columnas.
     *
     * @return list<FineImportIssue>
     */
    public function blockingIssues(): array
    {
        $codigos = [];

        foreach ($this->issues as $problema) {
            if ($problema['blocking']) {
                $codigos[$problema['code']] = FineImportIssue::from($problema['code']);
            }
        }

        return array_values($codigos);
    }

    public function hasIssue(FineImportIssue $problema): bool
    {
        foreach ($this->issues as $encontrado) {
            if ($encontrado['code'] === $problema->value) {
                return true;
            }
        }

        return false;
    }
}
