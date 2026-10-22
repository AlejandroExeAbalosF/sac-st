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
     * Las columnas del reverso de antes de que cada caja declarara las
     * suyas. Es con lo que se lee la evidencia de `schema 1`, que es toda de
     * Haberes.
     */
    public const HABERES_CHEQUE_COLUMNS = [
        ['key' => 'receipt', 'header' => 'RECIBO'],
        ['key' => 'expediente', 'header' => 'EXPTE'],
        ['key' => 'company', 'header' => 'EMPRESA'],
        ['key' => 'beneficiary', 'header' => 'BENEFICIARIO'],
    ];

    /**
     * @param  list<array{number: string, cash: numeric-string, cheques: numeric-string, bank: numeric-string, eventId?: int|null}>  $income
     * @param  list<array{number: string, cash: numeric-string, cheques: numeric-string, bank: numeric-string, eventId?: int|null}>  $expense
     * @param  list<array<string, string>>  $cheques  Una fila por cheque: las columnas de `$chequeColumns` y además `number`, `bank`, `date` y `amount`.
     * @param  list<array{key: string, header: string}>  $chequeColumns  Las que identifican cada cheque, congeladas con la evidencia.
     * @param  string|null  $bankColumnNote  Qué representa la columna de banco, si la cuenta no es solo de esta caja.
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
        public array $chequeColumns = self::HABERES_CHEQUE_COLUMNS,
        public ?string $bankColumnNote = null,
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
