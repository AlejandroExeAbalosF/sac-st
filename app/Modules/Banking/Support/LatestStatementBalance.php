<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;
use Carbon\CarbonImmutable;

/**
 * El último saldo de una cuenta que los extractos importados demuestran.
 *
 * **Solo afirma lo que el orden del banco demuestra.** Dentro de un día,
 * MacroOnline exporta de abajo hacia arriba, y dos extractos pueden traer el
 * mismo día en órdenes que no se pueden reconciliar: ese día no da saldo
 * (`BankDayOrder`) y se sigue con el anterior. Así un extracto viejo
 * importado tarde no hace retroceder el saldo, y un día dudoso no lo inventa.
 */
final class LatestStatementBalance
{
    public function __construct(private readonly BankDayOrder $orden) {}

    /**
     * El último saldo que informó un extracto, con su fecha.
     *
     * Se busca del día más reciente hacia atrás; dentro del día, el último
     * movimiento del orden bancario que trae saldo.
     *
     * @return array{balance: numeric-string, date: string}|null
     */
    public function of(int $bankAccountId): ?array
    {
        $dias = BankTransaction::query()
            ->where('bank_account_id', $bankAccountId)
            ->whereNotNull('balance_after')
            ->distinct()
            ->orderByDesc('transaction_date')
            ->pluck('transaction_date');

        foreach ($dias as $dia) {
            $fecha = CarbonImmutable::parse($dia)->toDateString();
            $orden = $this->orden->of($bankAccountId, $fecha);

            if ($orden === null) {
                continue;
            }

            for ($i = count($orden) - 1; $i >= 0; $i--) {
                if ($orden[$i]->balance_after !== null) {
                    return ['balance' => $orden[$i]->balance_after, 'date' => $fecha];
                }
            }
        }

        return null;
    }
}
