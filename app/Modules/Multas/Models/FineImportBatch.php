<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Un archivo de la cuenta corriente de Multas subido para importar. Lo que se
 * subió no se edita; la base lo impide.
 *
 * @property int $id
 * @property string $original_filename
 * @property string $file_sha256
 * @property int $file_size
 * @property string $reader_version
 * @property int|null $attachment_id
 * @property Carbon $cutoff_month El primer día del mes de la apertura de la caja.
 * @property FineImportStatus $status
 * @property list<array<string, mixed>> $reconciliation Por hoja: detalle, pies de control y diferencias.
 * @property string|null $failure
 * @property int|null $uploaded_by
 * @property int|null $consolidated_by
 * @property Carbon|null $consolidated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $uploader
 */
final class FineImportBatch extends Model
{
    protected $table = 'fine_import_batches';

    protected $fillable = [
        'original_filename',
        'file_sha256',
        'file_size',
        'reader_version',
        'attachment_id',
        'cutoff_month',
        'status',
        'reconciliation',
        'failure',
        'uploaded_by',
        'consolidated_by',
        'consolidated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cutoff_month' => 'date',
            'status' => FineImportStatus::class,
            'reconciliation' => 'array',
            'consolidated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<FineImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(FineImportRow::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
