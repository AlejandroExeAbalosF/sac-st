<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre la caja de Aranceles con el efectivo que hay (reglas A80 y A81).
 *
 * Sin saldo bancario: la cuenta es compartida con Multas y no se reparte. Sin
 * cheques: la caja no los acepta.
 *
 * El efectivo declarado lleva una recepción sobre el mismo evento de apertura,
 * sin asiento nuevo: no es un ingreso, y cada ítem de un depósito necesita una
 * recepción. No es recaudación ni respalda cobros: solo se puede depositar.
 */
final class OpenFeeCashBook
{
    public const array ACCOUNTS = [LedgerAccount::CashOnHand->value];

    public function __construct(private readonly RegisterOpeningBalance $abrir) {}

    /**
     * @param  array<string, string>  $balances
     * @param  array<int|string, int|string>  $denominations
     *
     * @throws ValidationException
     */
    public function handle(
        CarbonInterface $date,
        int $bankAccountId,
        array $balances,
        array $denominations,
        ?string $notes,
        bool $declaredEmpty,
        int $actorId,
    ): CashBookOpening {
        if (array_diff(array_keys($balances), self::ACCOUNTS) !== []) {
            throw ValidationException::withMessages([
                'balances' => 'La caja de Aranceles abre solo con su efectivo. El saldo de la cuenta es compartido con Multas y no se declara al abrir.',
            ]);
        }

        $caja = (int) CashBox::query()->active()->where('code', CashBox::ARANCELES)->firstOrFail()->id;

        return DB::transaction(function () use ($caja, $date, $bankAccountId, $balances, $denominations, $notes, $declaredEmpty, $actorId): CashBookOpening {
            $apertura = $this->abrir->handle(
                cashBoxId: $caja,
                balances: $balances,
                date: $date,
                actorId: $actorId,
                currency: Currency::Ars,
                bankAccountId: $bankAccountId,
                notes: $notes,
                cheques: [],
                denominations: $denominations,
                declaredEmpty: $declaredEmpty,
            );

            $efectivo = Decimal::scale($balances[LedgerAccount::CashOnHand->value] ?? '0');

            if (! Decimal::equals($efectivo, '0')) {
                $evento = FinancialEvent::query()
                    ->where('cash_box_id', $caja)
                    ->where('event_type', FinancialEventType::OpeningBalance)
                    ->firstOrFail();

                FundReceipt::query()->create([
                    'financial_event_id' => $evento->id,
                    'cash_box_id' => $caja,
                    'currency' => Currency::Ars,
                    'medium' => PaymentMedium::Cash,
                    'origin' => FundReceiptOrigin::Opening,
                    'amount' => $efectivo,
                    'received_date' => $date,
                    'received_by' => $actorId,
                ]);
            }

            return $apertura;
        });
    }
}
