<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\SourceFormat;
use App\Support\Money\Decimal;

/**
 * Un extracto leído entero: su cabecera y sus filas.
 *
 * Todavía no tocó la base. Sirve para la vista previa —el operador ve qué
 * se va a importar antes de confirmar— y es lo que después consume el
 * Action que persiste.
 */
final class ParsedStatement
{
    /**
     * @param  list<ParsedRow>  $rows
     */
    public function __construct(
        public readonly SourceFormat $format,
        public readonly array $rows,
        /**
         * Cuenta declarada en el archivo. El CSV de MacroOnline no la
         * trae; el Excel sí, y es lo único que permite rechazar el
         * extracto de otra cuenta subido por error.
         */
        public readonly ?string $accountNumber = null,
        public readonly ?string $currency = null,
        public readonly ?string $downloadedAt = null,
        public readonly ?string $operator = null,
    ) {}

    /** @return list<ParsedRow> */
    public function usableRows(): array
    {
        return array_values(array_filter($this->rows, fn (ParsedRow $row): bool => $row->isUsable()));
    }

    public function rejectedCount(): int
    {
        return count($this->rows) - count($this->usableRows());
    }

    public function periodFrom(): ?string
    {
        $dates = $this->dates();

        return $dates === [] ? null : min($dates);
    }

    public function periodTo(): ?string
    {
        $dates = $this->dates();

        return $dates === [] ? null : max($dates);
    }

    /**
     * Saldo con el que arranca el extracto: el posterior al movimiento más
     * viejo, menos ese movimiento.
     *
     * @return numeric-string|null
     */
    public function openingBalance(): ?string
    {
        $ordered = $this->chronologicalRows();
        $first = $ordered[0] ?? null;

        if ($first === null || $first->balanceAfter === null) {
            return null;
        }

        $movement = $first->signedAmount();

        return $movement === null
            ? null
            : Decimal::sub($first->balanceAfter, $movement);
    }

    /**
     * @return numeric-string|null
     */
    public function closingBalance(): ?string
    {
        $ordered = $this->chronologicalRows();
        $last = $ordered === [] ? null : $ordered[count($ordered) - 1];

        return $last?->balanceAfter;
    }

    /**
     * Las filas del más viejo al más nuevo.
     *
     * MacroOnline exporta al revés —lo último arriba— y la cadena de
     * saldos solo se puede seguir en orden cronológico.
     *
     * **Dentro de un mismo día el archivo también va al revés**, así que
     * el desempate invierte el orden en que vienen las filas en vez de
     * conservarlo. En el extracto real del área, los tres movimientos del
     * 04/08 solo encadenan leídos de abajo hacia arriba:
     *
     * ```text
     * -891841.00  → 18473189.40   ← la ultima del archivo es la primera del dia
     *    -121.00  → 18473068.40
     * -1253491.05 → 17219577.35
     * ```
     *
     * @return list<ParsedRow>
     */
    public function chronologicalRows(): array
    {
        $rows = array_values(array_filter(
            $this->usableRows(),
            fn (ParsedRow $row): bool => $row->transactionDate !== null,
        ));

        usort($rows, function (ParsedRow $a, ParsedRow $b): int {
            return [$a->transactionDate, $b->rowNumber] <=> [$b->transactionDate, $a->rowNumber];
        });

        return $rows;
    }

    /**
     * Las fechas en formato `Y-m-d`, que se comparan como texto sin
     * convertirlas: el orden lexicográfico y el cronológico coinciden.
     *
     * @return list<string>
     */
    private function dates(): array
    {
        return array_values(array_filter(array_map(
            fn (ParsedRow $row): ?string => $row->transactionDate,
            $this->usableRows(),
        )));
    }
}
