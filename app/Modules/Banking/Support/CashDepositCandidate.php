<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Models\BankTransaction;

/**
 * Un crédito que podría ser la acreditación de un depósito nuestro.
 *
 * **Sin puntaje numérico**, por lo mismo que en los tickets del
 * expediente: un «85 %» obliga a confiar, las señales dejan decidir.
 */
final readonly class CashDepositCandidate
{
    /**
     * @param  array<string, string>  $signals  qué coincidió, en palabras
     */
    public function __construct(
        public BankTransaction $transaction,
        public int $dayGap,
        public bool $operationMatches,
        public array $signals,
    ) {}

    /**
     * El orden: primero el número de operación cuando coincide, después
     * la cercanía en el tiempo.
     *
     * El importe no ordena porque es un filtro duro — todos los
     * candidatos lo tienen exacto.
     */
    public function rank(): int
    {
        return ($this->operationMatches ? 0 : 1_000) + abs($this->dayGap);
    }
}
