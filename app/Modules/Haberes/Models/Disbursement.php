<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Models\User;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Ledger\Models\FinancialEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * El dinero saliendo hacia su dueño — §9.7 del DER.
 *
 * La contracara de `FundReceipt`: aquella registra que entró y de quién,
 * ésta que salió y hacia quién.
 *
 * Append-only sobre el hecho monetario —importe, vía, cuota y asiento—;
 * lo que sí avanza es el estado y las fechas del circuito. Un egreso
 * equivocado no se edita: se revierte con su contrapartida.
 *
 * @property int $id
 * @property int|null $financial_event_id
 * @property int $beneficiary_installment_id
 * @property int|null $payment_order_id
 * @property DisbursementMethod $method
 * @property numeric-string $amount
 * @property DisbursementStatus $status
 * @property CarbonInterface|null $payment_date
 * @property string|null $beneficiary_cbu_snapshot
 * @property string|null $transfer_reference
 * @property int|null $bank_transaction_id
 * @property CarbonInterface|null $report_received_at
 * @property CarbonInterface|null $bank_debit_observed_at
 * @property int|null $validated_by
 * @property CarbonInterface|null $validated_at
 * @property int|null $cash_delivered_by
 * @property CarbonInterface|null $received_by_beneficiary_at
 * @property string|null $failure_reason
 * @property string|null $notes
 * @property int|null $created_by
 */
final class Disbursement extends Model
{
    protected $fillable = [
        'financial_event_id',
        'beneficiary_installment_id',
        'payment_order_id',
        'method',
        'amount',
        'status',
        'payment_date',
        'beneficiary_cbu_snapshot',
        'transfer_reference',
        'bank_transaction_id',
        'report_received_at',
        'bank_debit_observed_at',
        'validated_by',
        'validated_at',
        'cash_delivered_by',
        'received_by_beneficiary_at',
        'failure_reason',
        'notes',
        'created_by',
    ];

    /** @return BelongsTo<FinancialEvent, $this> */
    public function financialEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class);
    }

    /** El débito del extracto que prueba que el dinero salió. */
    /** @return BelongsTo<BankTransaction, $this> */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    /** @return BelongsTo<BeneficiaryInstallment, $this> */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(BeneficiaryInstallment::class, 'beneficiary_installment_id');
    }

    /** @return BelongsTo<PaymentOrder, $this> */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id');
    }

    /** Quién entregó el dinero en el mostrador. */
    /** @return BelongsTo<User, $this> */
    public function cashDeliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cash_delivered_by');
    }

    /** El contador que cotejó Orden, informe y débito (§2.3.4). */
    /** @return BelongsTo<User, $this> */
    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /**
     * Los que ocupan el lugar de su cuota.
     *
     * Espeja `disbursements_one_live_per_installment`.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            DisbursementStatus::Reversed,
            DisbursementStatus::Failed,
        ]);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', DisbursementStatus::Confirmed);
    }

    /**
     * Los que tienen informe y débito y esperan la firma del contador.
     *
     * El §2.3.4 pide que alguien coteje Orden, informe y débito antes de
     * dar el egreso por hecho. Hasta que eso pase, el dinero figura como
     * salido sin que nadie se haya hecho cargo de decirlo.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePorValidar(Builder $query): Builder
    {
        return $query->where('status', DisbursementStatus::ReadyForValidation);
    }

    /**
     * El dinero se fue y el sistema todavía no lo dio por hecho.
     *
     * Los tres estados intermedios del circuito bancario: el organismo
     * informó, o apareció el débito, o están los dos y falta el cotejo. Son
     * exactamente los egresos que existen en el banco y no en los libros, y
     * el §12.3 y el §12.4 explican por qué pueden llegar en cualquier orden.
     *
     * **`Pending` queda afuera** aunque también esté sin confirmar: ahí no
     * salió un peso todavía. Meterlo en la misma lista obligaría a la
     * planilla a titularse de una manera que no sería cierta para todas sus
     * filas.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSinConfirmar(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DisbursementStatus::ReportReceived,
            DisbursementStatus::BankDebitObserved,
            DisbursementStatus::ReadyForValidation,
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => DisbursementMethod::class,
            'status' => DisbursementStatus::class,
            'amount' => 'decimal:2',
            'payment_date' => 'date',
            'report_received_at' => 'datetime',
            'bank_debit_observed_at' => 'datetime',
            'validated_at' => 'datetime',
            'received_by_beneficiary_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
