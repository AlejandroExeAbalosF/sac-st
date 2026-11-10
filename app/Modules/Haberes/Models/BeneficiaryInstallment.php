<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cuota del beneficiario.
 *
 * Los importes pueden ser distintos entre sí y vienen determinados en el
 * expediente: el sistema no reparte el total en cuotas iguales.
 *
 * @property int $id
 * @property int $haber_id
 * @property int $installment_number
 * @property string $expected_amount Lo que la cuota recibe y se paga, excedente incluido.
 * @property string $excess_amount Lo que entró de más; cero salvo que se haya registrado.
 * @property HaberesReasonCode|null $excess_reason_code
 * @property string|null $excess_reason
 * @property InstallmentWorkflowStatus $workflow_status
 * @property Carbon|null $due_date
 * @property ExpectedMedium $expected_medium Obligatorio desde 09/2026: una cuota sin medio no dice por dónde se paga.
 * @property string|null $notes
 * @property Carbon|null $edit_unlocked_at
 * @property HaberesReasonCode|null $edit_unlock_reason_code
 * @property string|null $edit_unlock_reason
 * @property int|null $edit_unlocked_by
 * @property-read HaberManagementLabel|null $managementLabel
 */
final class BeneficiaryInstallment extends Model
{
    protected $table = 'beneficiary_installments';

    protected $fillable = [
        'haber_id',
        'installment_number',
        'expected_amount',
        'excess_amount',
        'excess_reason_code',
        'excess_reason',
        'management_label_id',
        'due_date',
        'description',
        'expected_medium',
        'workflow_status',
        'block_reason',
        'notes',
        'edit_unlocked_at',
        'edit_unlock_reason_code',
        'edit_unlock_reason',
        'edit_unlocked_by',
    ];

    /**
     * El importe previsto de la cuota.
     *
     * @return numeric-string
     */
    public function importeEsperado(): string
    {
        /** @var numeric-string $importe */
        $importe = $this->expected_amount;

        return $importe;
    }

    /**
     * Lo que entró de más en la cuota: cero salvo que se haya registrado.
     *
     * @return numeric-string
     */
    public function excedente(): string
    {
        /** @var numeric-string $importe */
        $importe = $this->excess_amount ?? '0.00';

        return $importe;
    }

    /**
     * Lo que se debía de la cuota: el previsto sin el excedente.
     *
     * Es lo que cuenta contra el importe reconocido del haber; el excedente
     * se cobra y se paga, pero va aparte.
     *
     * @return numeric-string
     */
    public function importeAAbonar(): string
    {
        return Decimal::sub($this->importeEsperado(), $this->excedente());
    }

    public function tieneExcedente(): bool
    {
        return bccomp($this->excedente(), '0', 2) === 1;
    }

    /**
     * La moneda de la cuota, que es la de su haber.
     *
     * Usa el haber si ya vino cargado y si no lo consulta: los Actions la
     * piden para escribir sus líneas, y no tienen por qué haber cargado la
     * relación.
     */
    public function currency(): Currency
    {
        $moneda = $this->relationLoaded('haber')
            ? $this->haber->currency
            : Haber::query()->whereKey($this->haber_id)->value('currency');

        return Currency::from((string) $moneda);
    }

    /**
     * @return BelongsTo<Haber, $this>
     */
    public function haber(): BelongsTo
    {
        return $this->belongsTo(Haber::class, 'haber_id');
    }

    /**
     * @return BelongsTo<HaberManagementLabel, $this>
     */
    public function managementLabel(): BelongsTo
    {
        return $this->belongsTo(HaberManagementLabel::class, 'management_label_id');
    }

    /**
     * Los comprobantes de depósito que el expediente trajo para esta cuota.
     *
     * Son varios cuando el depósito llegó fraccionado (§2.1.9). La mayoría
     * de las veces es uno solo, o ninguno todavía.
     *
     * @return HasMany<DepositTicket, $this>
     */
    public function depositTickets(): HasMany
    {
        /*
         * Del más nuevo al más viejo, y no por capricho: quien consume la
         * relacion toma el primero no descartado como "el vigente". Sin
         * orden, ese primero lo elegía el plan de la consulta y la misma
         * cuota podía mostrar un comprobante distinto en cada recarga.
         */
        return $this->hasMany(DepositTicket::class, 'beneficiary_installment_id')
            ->latest('id');
    }

    /**
     * Las Órdenes de Pago emitidas por esta cuota, incluidas las anuladas.
     *
     * Un plan normal tiene una sola; hay más cuando alguna se anuló y se
     * emitió la que la reemplaza. La vigente se filtra con `active()`.
     *
     * @return HasMany<PaymentOrder, $this>
     */
    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class, 'beneficiary_installment_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edit_unlock_reason_code' => HaberesReasonCode::class,
            'workflow_status' => InstallmentWorkflowStatus::class,
            'expected_medium' => ExpectedMedium::class,
            'expected_amount' => 'decimal:2',
            'excess_amount' => 'decimal:2',
            'excess_reason_code' => HaberesReasonCode::class,
            'due_date' => 'date',
            'edit_unlocked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
