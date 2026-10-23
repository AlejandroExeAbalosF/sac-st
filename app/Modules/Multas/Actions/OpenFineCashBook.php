<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Abre la caja de Multas con lo que ya está en el cajón (M12).
 *
 * Es la apertura de cualquier libro —`RegisterOpeningBalance`, con el
 * efectivo contado por billete y los cheques detallados— con dos reglas
 * propias de esta caja:
 *
 * - **Sin saldo bancario.** La cuenta de Multas es compartida con
 *   Aranceles, y su saldo no se reparte: cada caja reconoce solo lo que
 *   identifica. Ponerlo acá lo atribuiría entero a Multas.
 * - **Con su cuenta.** El libro dice con qué cuenta opera, y su fecha de
 *   apertura separa un crédito viejo, que va como pago anterior, de un
 *   cobro, que entra al libro.
 *
 * Solo en pesos: Multas no cobra en dólares.
 */
final class OpenFineCashBook
{
    /** Dónde puede estar el dinero de Multas al abrir: el cajón y los cheques. */
    public const array ACCOUNTS = [LedgerAccount::CashOnHand->value, LedgerAccount::ChequesInCustody->value];

    public function __construct(private readonly RegisterOpeningBalance $abrir) {}

    /**
     * @param  array<string, string>  $balances
     * @param  array<int|string, int|string>  $denominations
     * @param  list<array{number: string, bank: string, issueDate: string, amount: string, expediente?: ?string, company?: ?string, beneficiary?: ?string}>  $cheques
     *
     * @throws ValidationException
     */
    public function handle(
        CarbonInterface $date,
        int $bankAccountId,
        array $balances,
        array $denominations,
        array $cheques,
        ?string $notes,
        bool $declaredEmpty,
        int $actorId,
    ): CashBookOpening {
        $ajenas = array_diff(array_keys($balances), self::ACCOUNTS);

        if ($ajenas !== []) {
            throw ValidationException::withMessages([
                'balances' => 'La caja de Multas abre con su efectivo y sus cheques. El saldo de la cuenta bancaria se declara una vez, para la cuenta, en Banco › Cuentas.',
            ]);
        }

        $caja = CashBox::query()->active()->where('code', CashBox::MULTAS)->firstOrFail();

        return $this->abrir->handle(
            cashBoxId: (int) $caja->id,
            balances: $balances,
            date: $date,
            actorId: $actorId,
            currency: Currency::Ars,
            bankAccountId: $bankAccountId,
            notes: $notes,
            // Quien la libró y la multa que pagaba: el beneficiario es de Haberes.
            cheques: array_map(fn (array $cheque): array => [...$cheque, 'beneficiary' => null], $cheques),
            denominations: $denominations,
            declaredEmpty: $declaredEmpty,
        );
    }
}
