<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un veredicto del recurso. Revertirlo no lo borra: queda quién lo dio, quién
 * lo revirtió y por qué, y a lo sumo uno está vigente por recurso.
 *
 * @property int $id
 * @property int $fine_appeal_id
 * @property int $fine_id
 * @property FineAppealStatus $verdict
 * @property int $debt_entries_until_id Hasta qué movimiento de importe llegaba la multa; lo escribe la base.
 * @property Carbon $resolved_at
 * @property int|null $resolved_by
 * @property Carbon|null $reverted_at
 * @property int|null $reverted_by
 * @property FineReasonCode|null $revert_reason_code
 * @property string|null $revert_reason
 * @property-read User|null $resolver
 */
final class FineAppealVerdict extends Model
{
    protected $table = 'fine_appeal_verdicts';

    protected $fillable = [
        'fine_appeal_id',
        'fine_id',
        'verdict',
        'resolved_at',
        'resolved_by',
        'reverted_at',
        'reverted_by',
        'revert_reason_code',
        'revert_reason',
    ];

    /**
     * La base lo completa al insertar.
     *
     * @var array<string, int>
     */
    protected $attributes = ['debt_entries_until_id' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => FineAppealStatus::class,
            'revert_reason_code' => FineReasonCode::class,
            'resolved_at' => 'datetime',
            'reverted_at' => 'datetime',
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
