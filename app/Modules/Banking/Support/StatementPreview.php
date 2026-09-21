<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

/**
 * Lo que se sabe de un extracto antes de decidir importarlo.
 *
 * Existe porque la importación no debería ser un acto de fe: el operador
 * sube el archivo, ve cuántos movimientos son nuevos, cuántos ya estaban,
 * si los saldos encadenan y si el archivo es de la cuenta que eligió. Con
 * eso decide. Nada de esto tocó todavía la base.
 */
final class StatementPreview
{
    /**
     * @param  list<string>  $problems  motivos por los que el archivo no se puede importar
     * @param  list<string>  $newFingerprints
     * @param  list<string>  $knownFingerprints  movimientos que ya estaban en el sistema
     * @param  list<int>  $repeatedRowNumbers  filas cuyo movimiento no se va a crear
     * @param  string|null  $continuityWarning  salto respecto de lo ya importado
     */
    public function __construct(
        public readonly ParsedStatement $statement,
        public readonly BalanceChain $chain,
        public readonly array $problems,
        public readonly array $newFingerprints,
        public readonly array $knownFingerprints,
        public readonly array $repeatedRowNumbers,
        public readonly string $parserVersion,
        public readonly ?string $continuityWarning = null,
    ) {}

    /** Si esta fila trae un movimiento que el sistema ya tiene. */
    public function isRepeated(int $rowNumber): bool
    {
        return in_array($rowNumber, $this->repeatedRowNumbers, true);
    }

    public function isImportable(): bool
    {
        return $this->problems === [];
    }

    public function newCount(): int
    {
        return count($this->newFingerprints);
    }

    public function duplicateCount(): int
    {
        return count($this->repeatedRowNumbers);
    }

    /** El primero de los motivos, que es el que se muestra y se guarda. */
    public function failureReason(): ?string
    {
        return $this->problems[0] ?? null;
    }
}
