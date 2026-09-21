<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Models;

use App\Modules\Banking\Models\BankTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la tabla de depósitos de la Orden — §9.6 del DER.
 *
 * ```text
 * DEPÓSITO U OPERACIÓN N° | FECHA      | CTA. CTE. | IMPORTE
 * 83690105                | 28/5/2026  | 23456789  | $2.892.402,00
 * ```
 *
 * Es la justificación que el área le da al organismo: *«esta plata vino de
 * acá»*. El vínculo probatorio entre el dinero recibido y el pago que se
 * solicita, y lo que un auditor consulta.
 *
 * Sin `updated_at`: la fila se escribe una vez con la Orden y no se toca.
 *
 * @property int $id
 * @property int $payment_order_id
 * @property int $funding_allocation_id
 * @property int|null $bank_transaction_id
 * @property numeric-string $amount
 * @property string|null $operation_number_snapshot
 * @property CarbonInterface|null $operation_date_snapshot
 * @property string|null $bank_account_snapshot
 * @property string|null $bank_name_snapshot
 */
final class PaymentOrderFundingSource extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_order_id',
        'funding_allocation_id',
        'bank_transaction_id',
        'amount',
        'operation_number_snapshot',
        'operation_date_snapshot',
        'bank_account_snapshot',
        'bank_name_snapshot',
        'created_at',
    ];

    /** @return BelongsTo<PaymentOrder, $this> */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class, 'payment_order_id');
    }

    /** @return BelongsTo<FundingAllocation, $this> */
    public function fundingAllocation(): BelongsTo
    {
        return $this->belongsTo(FundingAllocation::class, 'funding_allocation_id');
    }

    /** @return BelongsTo<BankTransaction, $this> */
    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'operation_date_snapshot' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
