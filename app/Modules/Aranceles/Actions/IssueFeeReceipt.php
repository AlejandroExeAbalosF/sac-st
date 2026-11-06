<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Models\User;
use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Support\FeeReceiptSeries;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Emite el recibo de un cobro y deja congelado lo que dice (regla A94).
 *
 * Una sola definición para la emisión y la vista previa: lo que se mira es lo
 * que se imprime (A95). El número de la vista previa es un anticipo.
 *
 * @phpstan-type Papel array{payerName: string, payerDocument: string|null, lines: list<array{code: int, name: string, unitLabel: string|null, quantity: int|null, unitPrice: string|null, rate: string|null, baseAmount: string|null, amount: string}>, bankAccountNumber: string|null}
 */
final class IssueFeeReceipt
{
    public function __construct(private readonly TakeNextDocumentNumber $numerar) {}

    /**
     * @param  Papel  $papel
     * @param  list<int>  $eventos  Los hechos que documenta.
     */
    public function handle(
        FeeCharge $cobro,
        array $papel,
        string $total,
        CarbonInterface $fecha,
        array $eventos,
        ?string $talonario,
        ?int $signedById,
        ?int $actorId,
        ?int $replacesReceiptId = null,
    ): FeeChargeReceipt {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('El recibo se emite en la transacción que cobró.');
        }

        $numero = $this->numerar->handle(FeeReceiptSeries::CODE);

        $recibo = Receipt::query()->create([
            ...$this->datos($papel, $total, $cobro->medium, $fecha, $talonario, $signedById),
            'document_series_id' => $numero['series_id'],
            'number' => $numero['number'],
            'formatted_number' => $numero['formatted'],
            'status' => ReceiptStatus::Issued,
            'issued_by' => $actorId,
            'recorded_at' => $talonario === null ? null : now(),
            'recorded_by' => $talonario === null ? null : $actorId,
            'replaces_receipt_id' => $replacesReceiptId,
        ]);

        foreach ($eventos as $evento) {
            ReceiptFinancialEvent::query()->create(['receipt_id' => $recibo->id, 'financial_event_id' => $evento]);
        }

        return FeeChargeReceipt::query()->create([
            'fee_charge_id' => $cobro->id,
            'receipt_id' => $recibo->id,
            'snapshot' => $papel,
        ]);
    }

    /**
     * El recibo sin emitir, para mirarlo antes de cobrar. No escribe nada.
     *
     * @param  Papel  $papel
     */
    public function preview(
        FeeCharge $cobro,
        array $papel,
        string $total,
        CarbonInterface $fecha,
        ?string $talonario,
        ?int $signedById,
    ): FeeChargeReceipt {
        $serie = DocumentSeries::activeForCode(FeeReceiptSeries::CODE);

        $recibo = new Receipt([
            ...$this->datos($papel, $total, $cobro->medium, $fecha, $talonario, $signedById),
            'formatted_number' => $serie === null ? '—' : $serie->formatNumber($serie->next_number),
            'status' => ReceiptStatus::Issued,
        ]);

        return (new FeeChargeReceipt(['snapshot' => $papel]))
            ->setRelation('receipt', $recibo)
            ->setRelation('charge', $cobro);
    }

    /**
     * @param  Papel  $papel
     * @return array<string, mixed>
     */
    private function datos(array $papel, string $total, FeeChargeMedium $medio, CarbonInterface $fecha, ?string $talonario, ?int $signedById): array
    {
        $firmante = $signedById === null ? null : User::query()->find($signedById);
        $codigos = implode(' y ', array_map(fn (array $linea): string => (string) $linea['code'], $papel['lines']));

        return [
            'cash_box_id' => (int) CashBox::query()->where('code', CashBox::ARANCELES)->valueOrFail('id'),
            'talonario_number' => $talonario,
            'prints_talonario_number' => false,
            'receipt_type' => ReceiptType::Income,
            'concept_snapshot' => mb_substr('Tasa por servicio administrativo laboral, código '.$codigos, 0, 300),
            'medium_snapshot' => $medio->value,
            'counterparty_name_snapshot' => mb_substr($papel['payerName'], 0, 200),
            'amount' => Decimal::scale($total),
            'issue_date' => $fecha->toDateString(),
            'issue_mode' => $talonario === null ? ReceiptIssueMode::Online : ReceiptIssueMode::OfflineTalonario,
            'signed_by' => $firmante?->id,
            'signed_by_name_snapshot' => $firmante?->name,
            'signed_by_title_snapshot' => $firmante?->position,
        ];
    }
}
