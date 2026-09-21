<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Models\BankTransaction;

/**
 * Un movimiento que podría ser el del ticket, con el porqué a la vista.
 *
 * **Sin puntaje numérico.** Un `85 %` obliga a confiar; las señales dejan
 * decidir. El operador tiene que poder mirar dos candidatos y entender por
 * qué uno está primero, sobre todo cuando se equivoca el orden.
 */
final class TicketCandidate
{
    /**
     * @param  array<string, string>  $signals  qué coincidió, en palabras
     */
    public function __construct(
        public readonly BankTransaction $transaction,
        public readonly int $dayGap,
        public readonly bool $operationMatches,
        public readonly bool $employerMatches,
        public readonly array $signals,
    ) {}

    /**
     * El orden del listado, de la señal más concluyente a la más débil.
     *
     * 1. El **número de operación**, cuando coincide: es el dato que el
     *    ticket y el extracto comparten por identidad, no por parecido.
     * 2. El **CUIT del empleador**: confirma que el dinero viene de quien
     *    tiene que venir.
     * 3. La **cercanía en el tiempo**.
     *
     * El importe no ordena porque es un filtro duro: todos los candidatos
     * lo tienen exacto.
     */
    public function rank(): int
    {
        return ($this->operationMatches ? 0 : 1_000_000)
            + ($this->employerMatches ? 0 : 1_000)
            + abs($this->dayGap);
    }
}
