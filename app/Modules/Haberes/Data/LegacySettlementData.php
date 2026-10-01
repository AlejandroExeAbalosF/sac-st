<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\LegacySettlementMode;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cómo se pagó una cuota fuera del circuito.
 *
 * La fecha y el medio salen del registro en papel o del recibo de «Pagos
 * anteriores», según la modalidad: la pantalla no tiene que saber de dónde.
 */
#[TypeScript]
final class LegacySettlementData extends Data
{
    public function __construct(
        public int $id,
        public LegacySettlementMode $mode,
        /** @var numeric-string */
        public string $amount,
        public ?string $paidOn,
        public ?PaymentMedium $paymentMedium,
        /** El recibo del sistema, si se pagó desde Pagos anteriores. */
        public ?int $receiptId,
        public ?string $receiptNumber,
        /** La referencia a la planilla manual que se cargó al pagar. */
        public ?string $receiptReference,
        public ?string $notes,
        public ?string $recordedBy,
        public string $recordedAt,
    ) {}

    public static function fromModel(LegacySettlement $registro): self
    {
        $recibo = $registro->mode === LegacySettlementMode::LegacyDisbursement
            ? $registro->legacyDisbursementReceipt
            : null;

        return new self(
            id: $registro->id,
            mode: $registro->mode,
            amount: $registro->amount,
            paidOn: $recibo?->issue_date->format('Y-m-d') ?? $registro->paid_on?->format('Y-m-d'),
            paymentMedium: $recibo === null
                ? $registro->payment_medium
                : PaymentMedium::tryFrom($recibo->medium_snapshot),
            receiptId: $recibo?->id,
            receiptNumber: $recibo === null ? null : ($recibo->talonario_number ?? $recibo->formatted_number),
            receiptReference: $recibo?->expediente_number_snapshot,
            notes: $registro->notes,
            recordedBy: $registro->recorder?->name,
            recordedAt: BusinessDate::fromInstant($registro->recorded_at)->toDateString(),
        );
    }
}
