<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Pdf;

use App\Modules\Ledger\Excel\CashSheetEvidence;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DailyCashRendition
{
    private const BOXES = ['haberes' => 'Haberes en consignación', 'multas' => 'Multas', 'aranceles' => 'Aranceles'];

    /** @return Builder<PeriodClosing> */
    private function closings(CarbonImmutable $date): Builder
    {
        return PeriodClosing::query()->whereDate('period_from', $date)
            ->where('currency', 'ARS')->where('period_type', 'daily')->where('status', 'closed')
            ->whereHas('cashBox', fn ($box) => $box->whereIn('code', array_keys(self::BOXES)))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('period_closing_evidence')
                ->whereColumn('period_closing_id', 'period_closings.id')
                ->whereColumn('version', 'period_closings.evidence_version'));
    }

    /** @return list<array{code: string, name: string}> */
    public function pendingBoxes(CarbonImmutable $date): array
    {
        $closed = $this->closings($date)->with('cashBox')->get()
            ->map(fn (PeriodClosing $closing): string => $closing->cashBox->code)
            ->all();

        $pending = [];
        foreach (array_diff_key(self::BOXES, array_flip($closed)) as $code => $name) {
            $pending[] = ['code' => $code, 'name' => $name];
        }

        return $pending;
    }

    /** @return list<array{title: string, summary: bool, rows: list<array{string,string}>}> */
    public function panels(CarbonImmutable $date, CashSheetEvidence $evidence): array
    {
        $closed = $this->closings($date)->with('cashBox')->get()->keyBy(fn (PeriodClosing $c) => $c->cashBox->code);
        $panels = $summary = [];
        $total = '0.00';
        foreach (self::BOXES as $code => $name) {
            $closing = $closed->get($code);
            abort_if($closing === null, 409, 'La rendición requiere los tres cierres diarios en pesos.');
            $count = $evidence->read($closing)->count;
            abort_if($count === null, 409, 'Falta el arqueo en la evidencia del cierre.');
            $day = Decimal::sub($count->counted_amount, $count->carry_counted_amount ?? '0.00');
            $carry = $count->carry_counted_amount ?? $count->uncounted_amount;
            $balance = Decimal::add($day, $carry);
            $rows = [['RECAUDACIÓN DEL DÍA', Decimal::format($day)], ['SALDO ANTERIOR EN EFECTIVO', Decimal::format($carry)]];
            if ($code === 'aranceles') {
                $rows[] = ['SUBTOTAL A DEPOSITAR', Decimal::format($balance)];
            }
            $last = [sprintf('SALDO TOTAL DE CAJA %s (%d)', mb_strtoupper($name), count($summary) + 1), Decimal::format($balance)];
            $summary[] = $last;
            $rows[] = $last;
            $panels[] = ['title' => mb_strtoupper($name), 'summary' => false, 'rows' => $rows];
            $total = Decimal::add($total, $balance);
        }
        $summary[] = ['TOTAL DE EFECTIVO DEL DÍA EN CAJA', Decimal::format($total)];
        $panels[] = ['title' => 'TOTAL DE EFECTIVO DEL DÍA', 'summary' => true, 'rows' => $summary];

        return $panels;
    }
}
