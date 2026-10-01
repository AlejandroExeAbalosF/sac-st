<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Los pagos de «Pagos anteriores» que todavía pueden respaldar cuotas.
 *
 * Un pago de esos no tiene cuota: se hizo contra el saldo del sistema
 * anterior, con una referencia a la planilla manual. Cuando el expediente
 * se carga después, sus cuotas se vinculan a ese recibo. Un recibo puede
 * cubrir varias cuotas del mismo beneficiario, hasta su importe.
 *
 * Son las mismas reglas que impone `legacy_settlement_receipt_link`: el
 * mismo beneficiario, la misma moneda, recibo vigente y con disponible.
 */
final class LegacyDisbursementReceipts
{
    /**
     * Los recibos de ese beneficiario, en la moneda del haber, que todavía
     * tienen importe sin vincular.
     *
     * @return Collection<int, array{receipt: Receipt, available: numeric-string}>
     */
    public function availableFor(Haber $haber): Collection
    {
        $recibos = $this->candidates($haber)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        $vinculado = $this->linkedAmounts(array_values($recibos->pluck('id')->map(intval(...))->all()));

        return $recibos
            ->map(fn (Receipt $recibo): array => [
                'receipt' => $recibo,
                'available' => Decimal::sub($recibo->amount, $vinculado[$recibo->id] ?? '0.00'),
            ])
            ->filter(fn (array $fila): bool => ! Decimal::isNegative($fila['available']) && ! Decimal::equals($fila['available'], '0'))
            ->values();
    }

    /**
     * Un recibo candidato, bloqueado, con su disponible.
     *
     * El bloqueo es el mismo que toma la base antes de sumar: dos vínculos
     * simultáneos al mismo recibo no leen el mismo disponible.
     *
     * @return array{receipt: Receipt, available: numeric-string}|null
     */
    public function lockedCandidate(Haber $haber, int $receiptId): ?array
    {
        $recibo = $this->candidates($haber)->whereKey($receiptId)->lockForUpdate()->first();

        if ($recibo === null) {
            return null;
        }

        $vinculado = $this->linkedAmounts([$recibo->id])[$recibo->id] ?? '0.00';

        return [
            'receipt' => $recibo,
            'available' => Decimal::sub($recibo->amount, $vinculado),
        ];
    }

    /** @return Builder<Receipt> */
    private function candidates(Haber $haber): Builder
    {
        return Receipt::query()
            ->where('receipt_type', ReceiptType::Expense)
            ->where('status', ReceiptStatus::Issued)
            ->whereNull('beneficiary_installment_id')
            ->where('person_id', $haber->beneficiary_id)
            ->whereExists(function (QueryBuilder $query): void {
                $query->selectRaw('1')
                    ->from('receipt_financial_events AS pivote')
                    ->join('financial_events', 'financial_events.id', '=', 'pivote.financial_event_id')
                    ->whereColumn('pivote.receipt_id', 'receipts.id')
                    ->where('financial_events.event_type', FinancialEventType::LegacyDisbursement->value);
            })
            ->whereNotExists(function (QueryBuilder $query) use ($haber): void {
                $query->selectRaw('1')
                    ->from('receipt_financial_events AS pivote')
                    ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'pivote.financial_event_id')
                    ->whereColumn('pivote.receipt_id', 'receipts.id')
                    ->where('journal_lines.currency', '<>', $haber->currency);
            });
    }

    /**
     * Cuánto de cada recibo ya respaldan cuotas.
     *
     * @param  list<int>  $receiptIds
     * @return array<int, numeric-string>
     */
    private function linkedAmounts(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [];
        }

        $sumas = LegacySettlement::query()
            ->current()
            ->whereIn('legacy_disbursement_receipt_id', $receiptIds)
            ->groupBy('legacy_disbursement_receipt_id')
            ->selectRaw('legacy_disbursement_receipt_id AS recibo, SUM(amount) AS total')
            ->get();

        $porRecibo = [];

        foreach ($sumas as $fila) {
            $porRecibo[(int) $fila->getAttribute('recibo')] = Decimal::scale((string) $fila->getAttribute('total'));
        }

        return $porRecibo;
    }
}
