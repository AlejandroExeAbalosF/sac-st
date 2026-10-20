<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Models\JournalLine;
use App\Support\Money\Decimal;

/**
 * Lo que la apertura de un libro declaró, por cuenta.
 *
 * Suma todos los asientos de apertura del libro: el principal y el de cada
 * cheque detallado. Mirar solo el principal dejaba afuera la cartera
 * cuando se detallaba, y no mostraba nada cuando solo había cheques.
 *
 * La usan las pantallas de apertura de cada caja.
 */
final class OpeningLines
{
    /**
     * @return list<array{account: string, label: string, amount: numeric-string}>
     */
    public function of(int $cashBoxId, Currency $moneda): array
    {
        $porCuenta = [];

        $filas = JournalLine::query()
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->where('financial_events.event_type', FinancialEventType::OpeningBalance->value)
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->where('journal_lines.currency', $moneda->value)
            ->where('journal_lines.debit', '>', 0)
            ->orderBy('journal_lines.id')
            ->get(['journal_lines.account_code', 'journal_lines.debit']);

        foreach ($filas as $linea) {
            // El modelo ya lo devuelve como enum: `account_code` está casteado.
            $cuenta = $linea->account_code;

            $porCuenta[$cuenta->value] = [
                'account' => $cuenta->value,
                'label' => $cuenta->label(),
                'amount' => Decimal::add($porCuenta[$cuenta->value]['amount'] ?? '0.00', $linea->debit),
            ];
        }

        return array_values($porCuenta);
    }
}
