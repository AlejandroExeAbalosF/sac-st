<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un traslado entero de una recepción, con su asiento, escrito directo en la
 * base: un escritor que no pasa por ningún Action.
 *
 * El asiento tampoco pasa por `PostJournalEntry`, que bloquea la caja antes de
 * escribir: con ese bloqueo, dos escritores se serializarían antes de llegar a
 * la guarda y una prueba de concurrencia no probaría la guarda. Así, el único
 * lugar donde pueden encontrarse es `transfer_follows_box_policy`.
 */
final class RawCashDeposit
{
    public static function write(int $receipt, string $amount, int $account, int $box, int $actor): int
    {
        $ahora = now();

        $evento = DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $box,
            'event_type' => 'cash_deposited_to_bank',
            'event_date' => BusinessDate::today()->toDateString(),
            'status' => 'posted',
            'idempotency_key' => (string) Str::uuid(),
            'created_by' => $actor,
            'posted_by' => $actor,
            'posted_at' => $ahora,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        DB::table('journal_lines')->insert(array_map(
            fn (EntryLine $linea): array => [...$linea->toRow($evento), 'created_at' => $ahora],
            [
                EntryLine::debit(LedgerAccount::CashInTransit, $amount)->in(Currency::Ars)->onBankAccount($account)->onCashBox($box),
                EntryLine::credit(LedgerAccount::CashOnHand, $amount)->in(Currency::Ars)->onCashBox($box),
            ],
        ));

        $traslado = DB::table('cash_to_bank_transfers')->insertGetId([
            'deposit_event_id' => $evento, 'cash_box_id' => $box, 'bank_account_id' => $account,
            'amount' => $amount, 'deposit_date' => BusinessDate::today()->toDateString(), 'status' => 'deposited',
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        DB::table('cash_to_bank_transfer_items')->insert([
            'cash_to_bank_transfer_id' => $traslado, 'fund_receipt_id' => $receipt, 'amount' => $amount,
            'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        return $traslado;
    }
}
