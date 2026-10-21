<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineExcessReclassification;
use App\Modules\Multas\Models\FinePaymentApplication;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Lleva el libro al excedente que dice el saldo de la multa (M05).
 *
 * Un ajuste, una reversión o un antecedente validado cambian cuánto de lo ya
 * cobrado cubre la obligación y cuánto la excede. El dinero no se mueve: el
 * asiento solo pasa la diferencia entre lo recaudado y el excedente
 * pendiente, en la misma transacción que el cambio.
 *
 * Se llama con la multa ya bloqueada, desde el Action que la cambia. Sin
 * pagos en el sistema no hay nada que reclasificar: el excedente de un
 * antecedente no está en el libro.
 */
final class ReconcileFineExcess
{
    public function __construct(private readonly PostJournalEntry $asentar) {}

    public function handle(Fine $multa, string $reason, ?int $actorId): ?FineExcessReclassification
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('La reclasificación va en la transacción del cambio que la provoca.');
        }

        $asentado = Decimal::add(
            (string) (FinePaymentApplication::query()->where('fine_id', $multa->id)->sum('excess_amount') ?: '0'),
            (string) (FineExcessReclassification::query()->where('fine_id', $multa->id)->sum('amount') ?: '0'),
        );
        $calculado = (string) FineBalance::query()->whereKey($multa->id)->value('operational_excess');
        $diferencia = Decimal::sub($calculado, $asentado);

        if (Decimal::equals($diferencia, '0')) {
            return null;
        }

        $importe = Decimal::abs($diferencia);
        $haciaExcedente = ! Decimal::isNegative($diferencia);
        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->valueOrFail('id');
        $moneda = Currency::from($multa->currency);

        $evento = $this->asentar->handle(
            type: FinancialEventType::ExcessReclassified,
            idempotencyKey: 'multa-'.$multa->id.'-excedente-'.Str::uuid(),
            lines: [
                EntryLine::debit($haciaExcedente ? LedgerAccount::CollectedFunds : LedgerAccount::PendingExcess, $importe)
                    ->in($moneda)->onCashBox($caja),
                EntryLine::credit($haciaExcedente ? LedgerAccount::PendingExcess : LedgerAccount::CollectedFunds, $importe)
                    ->in($moneda)->onCashBox($caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $caja,
            description: $reason,
            actorId: $actorId,
        );

        return FineExcessReclassification::query()->create([
            'fine_id' => $multa->id,
            'event_id' => $evento->id,
            'amount' => $diferencia,
            'reason' => $reason,
            'created_by' => $actorId,
        ]);
    }
}
