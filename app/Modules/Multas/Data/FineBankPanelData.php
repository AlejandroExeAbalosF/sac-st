<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La cuenta con la que opera la caja de Multas, en dos números que no se
 * suman nunca (M08, M12):
 *
 * - lo que dice el banco de la cuenta entera, compartida con Aranceles;
 * - lo reconocido en el libro de Multas, que no es su parte del saldo del
 *   banco: de las salidas de la cuenta solo se conocen las que el libro
 *   registró.
 */
#[TypeScript]
final class FineBankPanelData extends Data
{
    public function __construct(
        public int $bankAccountId,
        public string $bankAccountLabel,
        /**
         * El último saldo que informó un extracto: el de la cuenta entera.
         *
         * @var numeric-string|null
         */
        public ?string $latestStatementBalance,
        public ?string $latestStatementDate,
        /**
         * Lo que el libro de Multas tiene asentado en la cuenta: depósitos
         * acreditados y pagos verificados, menos los débitos de cheques
         * rechazados.
         *
         * @var numeric-string
         */
        public string $bookBalance,
        /** Créditos desde la apertura de la caja que todavía ninguna caja reconoció. */
        public int $unclassifiedCount,
        /** @var numeric-string */
        public string $unclassifiedAmount,
    ) {}
}
