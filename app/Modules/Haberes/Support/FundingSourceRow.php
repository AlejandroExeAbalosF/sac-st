<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use Carbon\CarbonInterface;

/**
 * Un renglón de la tabla de depósitos, ya resuelto.
 *
 * ```text
 * DEPÓSITO U OPERACIÓN N° | FECHA      | CTA. CTE. | IMPORTE
 * 83690105                | 28/5/2026  | 23456789  | $2.892.402,00
 * ```
 *
 * Se arma antes de emitir —para que la vista previa muestre exactamente lo
 * que se va a congelar— y se copia tal cual a
 * `payment_order_funding_sources` al emitir.
 */
final readonly class FundingSourceRow
{
    public function __construct(
        public int $fundingAllocationId,
        /** @var numeric-string */
        public string $amount,
        /** La cuenta del organismo donde está este dinero. */
        public ?int $organismBankAccountId = null,
        public ?int $bankTransactionId = null,
        /** «DEPÓSITO U OPERACIÓN N°». */
        public ?string $operationNumber = null,
        public ?CarbonInterface $operationDate = null,
        /** «CTA. CTE.»: el número de la cuenta del organismo. */
        public ?string $bankAccountNumber = null,
        public ?string $bankName = null,
    ) {}

    /**
     * Lo que se guarda en `payment_order_funding_sources`.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(int $paymentOrderId): array
    {
        return [
            'payment_order_id' => $paymentOrderId,
            'funding_allocation_id' => $this->fundingAllocationId,
            'bank_transaction_id' => $this->bankTransactionId,
            'amount' => $this->amount,
            'operation_number_snapshot' => $this->operationNumber,
            'operation_date_snapshot' => $this->operationDate,
            'bank_account_snapshot' => $this->bankAccountNumber,
            'bank_name_snapshot' => $this->bankName,
            'created_at' => now(),
        ];
    }
}
