<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Pdf;

use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\CashCountLine;
use App\Support\Money\Decimal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/** La planilla imprimible de un arqueo firme. */
final class CashCountPdf
{
    private const array MONTH_ABBREVIATIONS = [
        1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN',
        7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC',
    ];

    public function render(CashCount $count): PdfDocument
    {
        $count->loadMissing('lines', 'cashBox');

        $lines = $count->lines->keyBy(fn (CashCountLine $line): string => Decimal::scale($line->denomination));
        $denominations = array_unique(array_map(
            static fn (int|string $value): string => Decimal::scale((string) $value),
            [
                ...CashCountLine::suggestedDenominations($count->currency),
                ...($count->currency->value === 'ARS' ? [20, 10, 5, 2, 1] : []),
                ...$count->lines->map(fn (CashCountLine $line): string => $line->denomination)->all(),
            ],
        ));
        usort($denominations, fn (string $a, string $b): int => bccomp($b, $a, 2));

        $rows = [];
        $total = '0.00';
        foreach ($denominations as $denomination) {
            $line = $lines->get($denomination);
            if ($line !== null) {
                $total = Decimal::add($total, $line->subtotal);
            }
            $rows[] = [
                'denomination' => Decimal::format($denomination),
                'quantity' => $line?->quantity,
                'subtotal' => $line === null ? null : Decimal::format($line->subtotal),
            ];
        }

        return Pdf::loadView('pdf.arqueo-de-caja', [
            'cashBoxCode' => mb_strtoupper($count->cashBox->code),
            'date' => $count->counted_on->format('d').' '.self::MONTH_ABBREVIATIONS[(int) $count->counted_on->format('n')].' '.$count->counted_on->format('Y'),
            'currency' => $count->currency,
            'rows' => $rows,
            'total' => Decimal::format($total),
            'difference' => $count->isBalanced() ? null : Decimal::format($count->difference_amount),
            'explanation' => $count->explanation,
        ])->setPaper('a4');
    }

    public function filename(CashCount $count): string
    {
        return sprintf('arqueo-%s-%s-turno-%d.pdf', $count->cashBox->code, $count->counted_on->format('Y-m-d'), $count->sequence);
    }
}
