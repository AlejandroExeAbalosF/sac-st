<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Ledger\Enums\PaymentMedium;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un papel del sistema anterior: recibo de ingreso, Orden de Pago o recibo
 * de egreso, con el número de talonario y la fecha que tiene impresos.
 *
 * El importe es **el del papel** y no cambia aunque la cuota se corrija
 * después: es lo que el documento dice, no lo que hoy se debe.
 *
 * @property int $id
 * @property int $haber_id
 * @property int $beneficiary_installment_id
 * @property int|null $legacy_settlement_id
 * @property LegacyDocumentKind $kind
 * @property string $number
 * @property CarbonInterface $issued_on
 * @property numeric-string $amount
 * @property PaymentMedium|null $medium
 * @property string|null $notes
 * @property int|null $recorded_by
 * @property CarbonInterface $recorded_at
 * @property CarbonInterface|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
final class LegacyDocument extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'haber_id',
        'beneficiary_installment_id',
        'legacy_settlement_id',
        'kind',
        'number',
        'issued_on',
        'amount',
        'medium',
        'notes',
        'recorded_by',
        'recorded_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /** @return BelongsTo<BeneficiaryInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryInstallment::class, 'beneficiary_installment_id');
    }

    /** @return BelongsTo<LegacySettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(LegacySettlement::class, 'legacy_settlement_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => LegacyDocumentKind::class,
            'issued_on' => 'date',
            'amount' => 'decimal:2',
            'medium' => PaymentMedium::class,
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
