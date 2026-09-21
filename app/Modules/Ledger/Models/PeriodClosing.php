<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Excel\CashSheetArchivist;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El cierre de un período — §9.9 del DER.
 *
 * Es el anverso de la planilla congelado como snapshot: tres saldos de
 * apertura, los movimientos del período por columna, y tres saldos de
 * cierre que la base calcula sola.
 *
 * Los saldos finales **son columnas generadas** y por eso no están en
 * `$fillable`: intentar escribirlos es un error contra PostgreSQL, y
 * dejarlos afuera lo vuelve imposible desde PHP.
 *
 * @property int $id
 * @property int $cash_box_id
 * @property Currency $currency
 * @property PeriodType $period_type
 * @property CarbonImmutable $period_from
 * @property CarbonImmutable $period_to
 * @property numeric-string $opening_cash
 * @property numeric-string $opening_cheques
 * @property numeric-string $opening_bank_deposits
 * @property numeric-string $received_cash
 * @property numeric-string $received_cheques
 * @property numeric-string $received_bank_deposits
 * @property numeric-string $disbursed_cash
 * @property numeric-string $disbursed_cheques
 * @property numeric-string $disbursed_bank_deposits
 * @property numeric-string $deposited_to_bank_cash
 * @property numeric-string $deposited_to_bank_cheques
 * @property numeric-string $closing_cash
 * @property numeric-string $closing_cheques
 * @property numeric-string $closing_bank_deposits
 * @property numeric-string $total_unassigned
 * @property PeriodClosingStatus $status
 * @property int|null $closed_by
 * @property CarbonImmutable|null $closed_at
 * @property int|null $approved_by
 * @property int|null $reopened_by
 * @property CarbonImmutable|null $reopened_at
 * @property string|null $reopen_reason
 * @property string|null $notes
 * @property int|null $sheet_attachment_id
 */
final class PeriodClosing extends Model
{
    protected $fillable = [
        'cash_box_id',
        'currency',
        'period_type',
        'period_from',
        'period_to',
        'opening_cash',
        'opening_cheques',
        'opening_bank_deposits',
        'received_cash',
        'received_cheques',
        'received_bank_deposits',
        'disbursed_cash',
        'disbursed_cheques',
        'disbursed_bank_deposits',
        'deposited_to_bank_cash',
        'deposited_to_bank_cheques',
        'total_unassigned',
        'status',
        'closed_by',
        'closed_at',
        'approved_by',
        'reopened_by',
        'reopened_at',
        'reopen_reason',
        'notes',
        'sheet_attachment_id',
    ];

    /**
     * Todas las planillas que este cierre emitió, de la última a la primera.
     *
     * Rehacer una planilla no pisa la anterior --`attachments` no admite
     * edición-- así que un cierre puede acumular varias. Guardarlas sin
     * poder mirarlas no serviría de nada: acá está la lista.
     *
     * @return HasMany<Attachment, $this>
     */
    public function sheets(): HasMany
    {
        return $this->hasMany(Attachment::class, 'subject_id')
            ->where('subject_type', AttachmentSubject::PeriodClosing->value)
            ->where('document_type', CashSheetArchivist::DOCUMENT_TYPE)
            ->latest('id');
    }

    /**
     * La planilla vigente de este cierre.
     *
     * @return BelongsTo<Attachment, $this>
     */
    public function sheetAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'sheet_attachment_id');
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', PeriodClosingStatus::Closed);
    }

    /**
     * El cierre que contiene una fecha, si lo hay.
     *
     * Es la consulta que responde «¿puedo asentar algo con esta fecha?».
     * El trigger `financial_events_period_open` hace lo mismo en la base;
     * esto existe para que la pantalla pueda avisarlo antes de que el
     * operador cargue todo el formulario.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCovering(Builder $query, string $date): Builder
    {
        return $query
            ->whereDate('period_from', '<=', $date)
            ->whereDate('period_to', '>=', $date);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'period_type' => PeriodType::class,
            'status' => PeriodClosingStatus::class,
            'period_from' => 'immutable_date',
            'period_to' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
            'opening_cash' => 'decimal:2',
            'opening_cheques' => 'decimal:2',
            'opening_bank_deposits' => 'decimal:2',
            'received_cash' => 'decimal:2',
            'received_cheques' => 'decimal:2',
            'received_bank_deposits' => 'decimal:2',
            'disbursed_cash' => 'decimal:2',
            'disbursed_cheques' => 'decimal:2',
            'disbursed_bank_deposits' => 'decimal:2',
            'deposited_to_bank_cash' => 'decimal:2',
            'deposited_to_bank_cheques' => 'decimal:2',
            'closing_cash' => 'decimal:2',
            'closing_cheques' => 'decimal:2',
            'closing_bank_deposits' => 'decimal:2',
            'total_unassigned' => 'decimal:2',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
