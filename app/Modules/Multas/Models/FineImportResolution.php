<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportIssue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Lo que alguien decidió sobre un problema de una fila. Una por problema; no
 * se edita ni se borra.
 *
 * @property int $id
 * @property int $fine_import_row_id
 * @property FineImportIssue $issue_code
 * @property FineImportAction $action
 * @property int|null $fine_case_id
 * @property int|null $fine_id
 * @property string|null $reason
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property-read User|null $resolver
 */
final class FineImportResolution extends Model
{
    public $timestamps = false;

    protected $table = 'fine_import_resolutions';

    protected $fillable = [
        'fine_import_row_id',
        'issue_code',
        'action',
        'fine_case_id',
        'fine_id',
        'reason',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issue_code' => FineImportIssue::class,
            'action' => FineImportAction::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
