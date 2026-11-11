<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Models\BankTransaction;

/**
 * Un débito que podría ser la transferencia de esta Orden.
 *
 * **Sin puntaje numérico**, igual que los candidatos de los tickets y de
 * los depósitos: un «85 %» obliga a confiar en una cuenta que nadie puede
 * revisar; las señales en palabras dejan decidir a quien mira.
 */
final readonly class TransferDebitCandidate
{
    /**
     * @param  array<string, string>  $signals  qué coincidió, en palabras
     */
    public function __construct(
        /** La transferencia: lo que recibió el beneficiario. */
        public BankTransaction $transaction,
        /** La comisión de la misma operación, si el banco la cobró. */
        public ?BankTransaction $fee,
        /** Si cierra pero no se puede elegir, por qué. */
        public ?string $reviewReason,
        /** Días entre el informe del organismo y el débito. */
        public int $dayGap,
        public bool $referenceMatches,
        public bool $beneficiaryMatches,
        public array $signals,
    ) {}

    /**
     * El orden: primero la referencia informada cuando coincide, después
     * el nombre del beneficiario, y por último la cercanía en el tiempo.
     * Los que quedan para revisión van al final: se muestran, pero no se
     * eligen.
     *
     * El importe no ordena porque es un filtro duro — todos los candidatos
     * lo tienen exacto, con su comisión o sin ella.
     */
    public function rank(): int
    {
        return ($this->reviewReason === null ? 0 : 100_000)
            + ($this->referenceMatches ? 0 : 10_000)
            + ($this->beneficiaryMatches ? 0 : 1_000)
            + abs($this->dayGap);
    }
}
