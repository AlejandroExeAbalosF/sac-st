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
 * - lo que el libro de Multas tiene cobrado en ella.
 */
#[TypeScript]
final class FineBankPanelData extends Data
{
    public function __construct(
        public int $bankAccountId,
        public string $bankAccountLabel,
        /** El último día incluido en el saldo declarado; nulo si todavía no se declaró. */
        public ?string $cutoffOn,
        /** @var numeric-string|null */
        public ?string $cutoffBalance,
        /** @var numeric-string|null */
        public ?string $latestStatementBalance,
        public ?string $latestStatementDate,
        /**
         * Lo que el libro de Multas tiene en la cuenta. En cero hasta que
         * existan los cobros (Etapa 4).
         *
         * @var numeric-string
         */
        public string $bookBalance,
        /** Créditos posteriores al corte que todavía ninguna caja reconoció. */
        public int $unclassifiedCount,
        /** @var numeric-string */
        public string $unclassifiedAmount,
    ) {}
}
