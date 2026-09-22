<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\Disbursement;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El egreso, tal como lo muestra la tarjeta de la cuota.
 *
 * Lleva las dos mitades porque son dos circuitos: lo del mostrador —quién
 * entregó y cuándo— y lo de la transferencia —el aviso del organismo, el
 * débito del extracto y la firma del contador—. Cada egreso usa una sola,
 * y cuál es lo dice `method`.
 */
#[TypeScript]
final class DisbursementSummaryData extends Data
{
    /**
     * Las relaciones que `fromModel()` lee.
     *
     * Viven con el DTO y no con cada consulta porque el que las necesita
     * es él. Con `preventLazyLoading` encendido, olvidarlas no es una
     * consulta de más: es un 500.
     *
     * @var list<string>
     */
    public const RELATIONS = [
        'cashDeliveredBy:id,name',
        'validatedBy:id,name',
        'bankTransaction:id,transaction_date,amount,operation_id,description,counterparty_name',
    ];

    public function __construct(
        public int $id,
        public DisbursementMethod $method,
        public DisbursementStatus $status,
        /** @var numeric-string */
        public string $amount,
        public ?string $paymentDate,
        /** Quién entregó el dinero en el mostrador. */
        public ?string $deliveredByName,
        public ?string $notes,

        /* ─── El circuito bancario ───────────────────────────────────── */
        /** Cuándo el organismo avisó que transfirió (§2.3.1). */
        public ?string $reportedAt,
        /** Con qué número identificó la transferencia. */
        public ?string $transferReference,
        /** El CBU al que se pidió transferir, congelado. */
        public ?string $beneficiaryCbu,
        /** El débito del extracto que prueba que el dinero salió. */
        public ?int $debitTransactionId,
        public ?string $debitDate,
        public ?string $debitOperationId,
        public ?string $debitDescription,
        /** Quién cotejó Orden, informe y débito (§2.3.4). */
        public ?string $validatedByName,
        public ?string $validatedAt,
    ) {}

    public static function fromModel(Disbursement $disbursement): self
    {
        $debito = $disbursement->bankTransaction;

        return new self(
            id: $disbursement->id,
            method: $disbursement->method,
            status: $disbursement->status,
            amount: $disbursement->amount,
            paymentDate: $disbursement->payment_date?->format('Y-m-d'),
            deliveredByName: $disbursement->cashDeliveredBy?->name,
            notes: $disbursement->notes,
            reportedAt: $disbursement->report_received_at === null ? null : BusinessDate::fromInstant($disbursement->report_received_at)->toDateString(),
            transferReference: $disbursement->transfer_reference,
            beneficiaryCbu: $disbursement->beneficiary_cbu_snapshot,
            debitTransactionId: $debito?->id,
            debitDate: $debito?->transaction_date?->format('Y-m-d'),
            debitOperationId: $debito?->operation_id,
            debitDescription: $debito->description ?? $debito?->counterparty_name,
            validatedByName: $disbursement->validatedBy?->name,
            validatedAt: $disbursement->validated_at === null ? null : BusinessDate::fromInstant($disbursement->validated_at)->toDateString(),
        );
    }
}
