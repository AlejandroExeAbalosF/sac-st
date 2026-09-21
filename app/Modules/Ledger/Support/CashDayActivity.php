<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Los asientos que explican por qué un día tiene actividad en el calendario. */
final class CashDayActivity
{
    /**
     * @return list<array{publicId: string, label: string, amount: numeric-string, note: ?string, reversedOn: ?string}>
     */
    public function entries(int $cashBoxId, CarbonInterface $date, Currency $currency): array
    {
        $amount = DB::table('journal_lines')
            ->selectRaw('COALESCE(SUM(debit), 0)')
            ->whereColumn('financial_event_id', 'financial_events.id')
            ->where('currency', $currency->value);

        $reversedOn = DB::table('financial_events AS reversal')
            ->select('reversal.event_date')
            ->whereColumn('reversal.reversal_of_id', 'financial_events.id')
            ->whereIn('reversal.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->limit(1);

        $events = DB::table('financial_events')
            ->leftJoin('financial_events AS original', 'original.id', '=', 'financial_events.reversal_of_id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->whereDate('financial_events.event_date', $date)
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereExists(function ($query) use ($currency): void {
                $query->selectRaw('1')
                    ->from('journal_lines')
                    ->whereColumn('journal_lines.financial_event_id', 'financial_events.id')
                    ->where('journal_lines.currency', $currency->value);
            })
            ->select([
                'financial_events.public_id',
                'financial_events.event_type',
                'financial_events.description',
                'financial_events.reversal_reason',
                'original.event_type AS original_type',
            ])
            ->selectSub($amount, 'amount')
            ->selectSub($reversedOn, 'reversed_on')
            ->orderBy('financial_events.id')
            ->get();

        $activity = [];

        foreach ($events as $event) {
            $type = FinancialEventType::from((string) $event->event_type);
            $original = $event->original_type === null
                ? null
                : FinancialEventType::from((string) $event->original_type);

            $activity[] = [
                'publicId' => (string) $event->public_id,
                'label' => $type === FinancialEventType::Reversal && $original !== null
                    ? 'Reversión de '.mb_strtolower($original->label())
                    : $type->label(),
                'amount' => Decimal::scale((string) $event->amount),
                'note' => $type === FinancialEventType::Reversal
                    ? $event->reversal_reason
                    : $event->description,
                'reversedOn' => $event->reversed_on === null ? null : (string) $event->reversed_on,
            ];
        }

        return $activity;
    }
}
