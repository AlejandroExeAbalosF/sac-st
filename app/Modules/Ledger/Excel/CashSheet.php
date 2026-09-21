<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Excel;

use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;

/**
 * Una hoja de la planilla, con todo lo que hay que imprimir.
 *
 * Es el intermediario entre lo que la base sabe y lo que el papel muestra.
 * Existe para que el que arma el Excel no tenga que consultar nada: recibe
 * esto y dibuja. Así el renderizador se puede probar con datos armados a
 * mano, sin base.
 *
 * **Los importes son cadenas decimales**, como en toda la pila. El único
 * lugar donde se vuelven números es la celda de Excel, y ahí es el formato
 * de la celda el que decide cómo se ven.
 */
final readonly class CashSheet
{
    /**
     * @param  list<array{number: string, cash: numeric-string, cheques: numeric-string, bank: numeric-string}>  $income
     * @param  list<array{number: string, cash: numeric-string, cheques: numeric-string, bank: numeric-string}>  $expense
     * @param  list<array{receipt: string, expediente: string, company: string, beneficiary: string, number: string, bank: string, date: string, amount: numeric-string}>  $cheques
     */
    public function __construct(
        public PeriodClosing $closing,
        public string $title,
        public string $sheetName,
        public string $reverseSheetName,
        public array $income,
        public array $expense,
        public ?CashCount $count,
        public array $cheques,
        public string $bankDepositsLabel,
    ) {}

    /**
     * Si hay reverso que imprimir.
     *
     * En la planilla de junio hay días sin arqueo y sin cheques —el
     * 19/06, por ejemplo— y en esos casos la hoja de reverso queda vacía.
     * Generarla igual sería agregar una hoja en blanco a un libro que ya
     * tiene cuarenta.
     */
    public function hasReverse(): bool
    {
        return $this->count !== null || $this->cheques !== [];
    }
}
