<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineRevisionKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un dato normalizado que se completó o se corrigió.
 *
 * La fila se aplica a sí misma: el trigger de la base escribe el cambio en
 * el expediente, el documento o la multa, después de comprobar que el valor
 * anterior sigue siendo el actual. Es la única vía para cambiar esos datos.
 *
 * @property int $id
 * @property int|null $fine_case_id
 * @property int|null $fine_document_id
 * @property int|null $fine_id
 * @property int|null $fine_debt_entry_id
 * @property int|null $fine_historical_movement_id
 * @property FineRevisionKind $kind
 * @property array<string, mixed> $old_values
 * @property array<string, mixed> $new_values
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property-read User|null $creator
 */
final class FineIdentifierRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fine_identifier_revisions';

    protected $fillable = [
        'fine_case_id',
        'fine_document_id',
        'fine_id',
        'fine_debt_entry_id',
        'fine_historical_movement_id',
        'kind',
        'old_values',
        'new_values',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FineRevisionKind::class,
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
