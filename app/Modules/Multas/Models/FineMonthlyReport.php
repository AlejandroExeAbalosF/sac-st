<?php

declare(strict_types=1);

namespace App\Modules\Multas\Models;

use App\Models\User;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uno de los dos informes de la rendición mensual de Multas (P02), congelado
 * sobre una versión del cierre mensual: los ingresos en término o los fuera
 * de término. Se escribe una vez; solo se le adjunta su PDF.
 *
 * @property int $id
 * @property int $period_closing_id
 * @property int $closing_version
 * @property string $kind
 * @property list<array<string, mixed>> $payload
 * @property string $total
 * @property int $rows_count
 * @property int|null $attachment_id
 * @property int|null $emitted_by
 * @property Carbon $emitted_at
 * @property-read PeriodClosing $closing
 * @property-read Attachment|null $attachment
 * @property-read User|null $emitter
 */
final class FineMonthlyReport extends Model
{
    public const ON_TIME = 'on_time';

    public const LATE = 'late';

    public $timestamps = false;

    protected $table = 'fine_monthly_reports';

    protected $fillable = [
        'period_closing_id', 'closing_version', 'kind', 'payload', 'total', 'rows_count',
        'attachment_id', 'emitted_by', 'emitted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'total' => 'decimal:2',
            'emitted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PeriodClosing, $this> */
    public function closing(): BelongsTo
    {
        return $this->belongsTo(PeriodClosing::class, 'period_closing_id');
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function emitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'emitted_by');
    }
}
