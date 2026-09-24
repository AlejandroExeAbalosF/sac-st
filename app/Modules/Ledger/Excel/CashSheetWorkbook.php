<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Excel;

use App\Modules\Ledger\Models\CashCountLine;
use App\Support\Money\Decimal;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Dibuja la planilla de caja como la escribe el área.
 *
 * Reproduce `CAJA HABERES EN CONSIGNACION JUNIO 2026.xlsx`: dos hojas por
 * día, con los mismos nombres —`CAJA 020626`, `REVERSO 020626`—, las mismas
 * tres columnas y las mismas filas de totales. Un libro mensual es esas dos
 * hojas repetidas por cada día cerrado.
 *
 * **Una diferencia con el original, y es a favor.** La planilla de papel
 * tiene 19 filas fijas para ingresos y 19 para egresos, porque está
 * preimpresa; un día con veinte recibos no entra. Acá los bloques crecen
 * con los datos.
 *
 * No consulta nada: recibe `CashSheet` y dibuja. Eso permite probar el
 * dibujo contra datos armados a mano y probar la consulta por separado.
 */
final class CashSheetWorkbook
{
    /**
     * La versión del dibujo, no la de los datos.
     *
     * **Sube con cualquier cambio en cómo se ve la planilla**, no solo con
     * los estructurales: una fila nueva, un rótulo distinto, un borde, un
     * color, una alineación. El criterio es que dos archivos con la misma
     * versión tienen que salir idénticos —si no, la marca no sirve para
     * comparar el papel firmado contra lo que baja hoy—.
     *
     * Queda escrita en el adjunto, y es lo que permite saber si una
     * planilla guardada la dibujó el generador de hoy o uno anterior.
     *
     * `2`: la apertura pasó al SALDO INICIAL, el reverso lista las diez
     * denominaciones siempre y el renglón de lo no recontado lleva el
     * nombre que le da el área.
     *
     * `3`: la rendición del efectivo lleva el recuadro y el encabezado
     * coloreado del original, y el total se repite al costado.
     *
     * `4`: la cantidad de billetes va centrada.
     *
     * `5`: el rótulo de la tercera columna del reverso y las
     * denominaciones que se listan salen de la moneda del arqueo. Una
     * planilla en pesos se dibuja igual que en `4`; sube igual porque el
     * generador dejó de ser el mismo.
     *
     * `6`: el renglón del motivo sale del reverso. Declarar un saldo sin
     * recontar dejó de exigirlo: en la planilla del área lo arrastran los
     * veinte días, así que el campo se llenaba igual todas las tardes.
     */
    public const VERSION = '6';

    private const AZUL = 'FF1F3864';

    /**
     * El lila del encabezado de la rendición.
     *
     * Sale de la planilla del área. No es decoración: es lo que separa de
     * un vistazo el recuadro del conteo del resto de la hoja, y quien la
     * firma todos los días lo reconoce antes de leer el rótulo.
     */
    private const LILA = 'FFE4DFEC';

    private const GRIS = 'FFF2F2F2';

    private const FORMATO_IMPORTE = '"$" #,##0.00';

    /**
     * Escribe el libro en disco y devuelve la ruta.
     *
     * @param  list<CashSheet>  $sheets
     */
    public function write(array $sheets, string $path): string
    {
        if ($sheets === []) {
            throw new RuntimeException('No hay ninguna planilla que exportar.');
        }

        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        foreach ($sheets as $planilla) {
            $this->anverso($libro, $planilla);

            if ($planilla->hasReverse()) {
                $this->reverso($libro, $planilla);
            }
        }

        $libro->setActiveSheetIndex(0);

        (new Xlsx($libro))->save($path);

        $libro->disconnectWorksheets();

        return $path;
    }

    /** El anverso: saldo inicial, ingresos, egresos, depósitos y saldo final. */
    private function anverso(Spreadsheet $libro, CashSheet $planilla): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle($this->safeTitle($planilla->sheetName));

        $cierre = $planilla->closing;

        $hoja->setCellValue('A1', $planilla->title);
        $hoja->mergeCells('A1:D1');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $hoja->setCellValue('A3', mb_strtoupper($cierre->cashBox->name));
        $hoja->mergeCells('A3:D3');
        $hoja->getStyle('A3')->getFont()->setBold(true);
        $hoja->getStyle('A3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $fila = 4;
        $this->encabezado($hoja, $fila);

        $fila++;
        $this->totalRow($hoja, $fila, 'SALDO INICIAL', [
            $cierre->opening_cash, $cierre->opening_cheques, $cierre->opening_bank_deposits,
        ]);

        $fila = $this->detalle($hoja, $fila + 1, $planilla->income);
        $this->totalRow($hoja, $fila, 'INGRESOS', [
            $cierre->received_cash, $cierre->received_cheques, $cierre->received_bank_deposits,
        ]);

        $fila = $this->detalle($hoja, $fila + 1, $planilla->expense);
        $this->totalRow($hoja, $fila, 'EGRESOS', [
            $cierre->disbursed_cash, $cierre->disbursed_cheques, $cierre->disbursed_bank_deposits,
        ]);

        /*
         * El subtotal es lo que queda antes de llevar plata al banco. La
         * planilla lo muestra aunque casi siempre coincida con el saldo
         * final, porque el día que haya un depósito los dos números se
         * separan y ahí se ve de dónde salió.
         */
        $fila++;
        $this->totalRow($hoja, $fila, 'SUBTOTAL', [
            bcadd($cierre->closing_cash, $cierre->deposited_to_bank_cash, 2),
            bcadd($cierre->closing_cheques, $cierre->deposited_to_bank_cheques, 2),
            $cierre->closing_bank_deposits,
        ]);

        $fila++;
        $this->totalRow($hoja, $fila, $planilla->bankDepositsLabel, [
            $cierre->deposited_to_bank_cash, $cierre->deposited_to_bank_cheques, '0.00',
        ]);

        $fila++;
        $this->totalRow($hoja, $fila, 'SALDO FINAL', [
            $cierre->closing_cash, $cierre->closing_cheques, $cierre->closing_bank_deposits,
        ], destacada: true);

        $hoja->getColumnDimension('A')->setWidth(42);

        foreach (['B', 'C', 'D'] as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(18);
        }
    }

    /** El reverso: la rendición del efectivo y el inventario de cheques. */
    private function reverso(Spreadsheet $libro, CashSheet $planilla): void
    {
        $hoja = $libro->createSheet();
        $hoja->setTitle($this->safeTitle($planilla->reverseSheetName));

        $hoja->setCellValue('B2', mb_strtoupper($planilla->closing->cashBox->name));
        $hoja->getStyle('B2')->getFont()->setBold(true);

        $fila = 4;
        $arqueo = $planilla->count;

        if ($arqueo !== null) {
            $hoja->setCellValue('A'.$fila, 'RENDICION DEL EFECTIVO '.$arqueo->counted_on->format('d/m/y'));
            $hoja->getStyle('A'.$fila)->getFont()->setBold(true);

            $hoja->mergeCells("A{$fila}:C{$fila}");

            $fila++;
            $primeraDelCuadro = $fila;

            /*
             * El papel del área dice «PESOS» en la tercera columna. Con un
             * arqueo en dólares ese rótulo sería falso, así que lo pone la
             * moneda del arqueo. **El resto de la planilla sigue siendo la
             * de pesos** --el título, el pie, el recibo que la acompaña--:
             * cómo es la rendición de dólares es una pregunta abierta con
             * el área (corrección 23), no una decisión de diseño.
             */
            $hoja->fromArray(
                ['CANTIDAD', 'BILLETES', mb_strtoupper($arqueo->currency->label())],
                null,
                'A'.$fila,
            );
            $hoja->getStyle("A{$fila}:C{$fila}")->getFont()->setBold(true);
            $hoja->getStyle("A{$fila}:C{$fila}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::LILA);
            $hoja->getStyle("A{$fila}:C{$fila}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);

            /*
             * Las denominaciones de la moneda van todas, contadas o no.
             *
             * Antes se imprimían solo las que tenían billetes, y el cuadro
             * cambiaba de alto cada día: quien firma la rendición busca el
             * renglón de los diez mil en el mismo lugar que ayer. El papel
             * del área las tiene preimpresas en cero por ese motivo.
             */
            /*
              * Los dos conteos van juntos, y sumados por denominación.
              *
              * El papel lista **lo que hay en el cajón**: no le interesa si
              * un billete de diez mil vino de la recaudación de hoy o del
              * fajo que se abrió esta tarde. Separarlos acá daría dos
              * renglones de la misma denominación y un cuadro que no suma
              * el total contado.
              */
            $contadas = [];

            foreach ($arqueo->allLines as $linea) {
                $denominacion = (string) (int) $linea->denomination;
                $contadas[$denominacion] = ($contadas[$denominacion] ?? 0) + (int) $linea->quantity;
            }

            foreach (CashCountLine::suggestedDenominations($arqueo->currency) as $denominacion) {
                $fila++;
                $cantidad = $contadas[(string) $denominacion] ?? 0;

                if ($cantidad > 0) {
                    $hoja->setCellValue('A'.$fila, $cantidad);
                }

                // La cantidad de billetes va centrada, como en el papel:
                // es un recuento, no un importe, y alinearla a la derecha
                // la hacía leerse como una tercera columna de plata.
                $hoja->getStyle('A'.$fila)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);

                $hoja->setCellValueExplicit('B'.$fila, (string) $denominacion, 'n');
                $hoja->setCellValueExplicit(
                    'C'.$fila,
                    Decimal::scale((string) ($denominacion * $cantidad)),
                    'n',
                );
                $hoja->getStyle("B{$fila}:C{$fila}")
                    ->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);
            }

            $fila++;
            $this->reverseTotal($hoja, $fila, 'RECAUDACION DEL DIA', $arqueo->counted_amount);

            /*
             * El renglón que el papel llama «SALDO DIA ANTERIOR».
             *
             * Es el fajo que no se recontó, que casi siempre es el saldo
             * que venía de ayer: por eso el área lo nombra así. Se imprime
             * con los dos nombres —el suyo y el nuestro— y va siempre,
             * aunque dé cero, porque en el papel es una fila fija.
             *
             * El motivo se aclara debajo solo cuando hay algo declarado:
             * un renglón en cero no tiene nada que explicar.
             */
            $fila++;
            $this->reverseTotal(
                $hoja,
                $fila,
                'SALDO DIA ANTERIOR (no recontado)',
                $arqueo->uncounted_amount,
            );

            $fila++;
            $total = bcadd($arqueo->counted_amount, $arqueo->uncounted_amount, 2);

            $this->reverseTotal(
                $hoja,
                $fila,
                'TOTAL CAJA '.mb_strtoupper($planilla->closing->cashBox->name),
                $total,
                destacada: true,
            );

            /*
             * El total repetido al costado, como en el original. Parece
             * redundante y no lo es: en el papel del área esa celda suelta
             * es la que se compara contra el arqueo del día siguiente, y
             * queda fuera del recuadro para poder leerla sin buscarla.
             */
            $hoja->setCellValueExplicit('D'.$fila, $total, 'n');
            $hoja->getStyle('D'.$fila)->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);
            $hoja->getStyle('D'.$fila)->getFont()->setBold(true);
            $hoja->getStyle('D'.$fila)->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            /*
             * Y el recuadro entero, del encabezado al total. El área lo
             * imprime con borde: sin él las tres columnas se confunden con
             * el inventario de cheques que viene abajo.
             */
            $hoja->getStyle("A{$primeraDelCuadro}:C{$fila}")->getBorders()
                ->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            /*
             * Y la diferencia, que el papel no tiene porque siempre la
             * cuadra. Solo se imprime cuando existe: es el número que el
             * contador tiene que explicar.
             */
            if (! $arqueo->isBalanced()) {
                $fila++;
                $this->reverseTotal($hoja, $fila, 'DIFERENCIA CONTRA EL LIBRO', $arqueo->difference_amount);

                $fila++;
                $hoja->setCellValue('A'.$fila, (string) $arqueo->explanation);
                $hoja->mergeCells("A{$fila}:H{$fila}");
                $hoja->getStyle('A'.$fila)->getFont()->setItalic(true);
            }

            $fila += 2;
        }

        $hoja->getColumnDimension('A')->setWidth(30);
        $hoja->getColumnDimension('B')->setWidth(16);
        $hoja->getColumnDimension('C')->setWidth(18);
        $hoja->getColumnDimension('D')->setWidth(18);

        if ($planilla->cheques !== []) {
            $hoja->setCellValue('A'.$fila, mb_strtoupper($planilla->closing->cashBox->name));
            $hoja->getStyle('A'.$fila)->getFont()->setBold(true);

            $fila++;
            $hoja->fromArray(
                ['RECIBO', 'EXPTE', 'EMPRESA', 'BENEFICIARIO', 'CHEQUE Nº', 'BANCO', 'FECHA', 'IMPORTE'],
                null,
                'A'.$fila,
            );
            $hoja->getStyle("A{$fila}:H{$fila}")->getFont()->setBold(true);
            $hoja->getStyle("A{$fila}:H{$fila}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GRIS);

            $total = '0.00';

            foreach ($planilla->cheques as $cheque) {
                $fila++;
                $hoja->fromArray([
                    $cheque['receipt'], $cheque['expediente'], $cheque['company'],
                    $cheque['beneficiary'], $cheque['number'], $cheque['bank'], $cheque['date'],
                ], null, 'A'.$fila);
                $hoja->setCellValueExplicit('H'.$fila, $cheque['amount'], 'n');
                $hoja->getStyle('H'.$fila)->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);

                $total = bcadd($total, $cheque['amount'], 2);
            }

            $fila++;
            $hoja->setCellValue('A'.$fila, 'TOTAL');
            $hoja->setCellValueExplicit('H'.$fila, $total, 'n');
            $hoja->getStyle("A{$fila}:H{$fila}")->getFont()->setBold(true);
            $hoja->getStyle('H'.$fila)->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);
        }

        $hoja->getColumnDimension('A')->setWidth(30);

        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $columna) {
            $hoja->getColumnDimension($columna)->setWidth(20);
        }
    }

    private function encabezado(Worksheet $hoja, int $fila): void
    {
        $hoja->fromArray(['RECIBO Nº', 'EFECTIVO', 'CHEQUES', 'DEPOSITOS DIRECTOS'], null, 'A'.$fila);

        $estilo = $hoja->getStyle("A{$fila}:D{$fila}");
        $estilo->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::AZUL);
        $estilo->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * Las filas de detalle, una por recibo.
     *
     * @param  list<array{number: string, cash: numeric-string, cheques: numeric-string, bank: numeric-string}>  $rows
     * @return int La fila siguiente a la última escrita.
     */
    private function detalle(Worksheet $hoja, int $fila, array $rows): int
    {
        foreach ($rows as $row) {
            $hoja->setCellValueExplicit('A'.$fila, $row['number'], 's');

            foreach (['B' => 'cash', 'C' => 'cheques', 'D' => 'bank'] as $columna => $clave) {
                // Las celdas en cero se dejan vacías, como el papel: una
                // grilla llena de ceros esconde dónde estuvo el movimiento.
                if (bccomp($row[$clave], '0', 2) === 0) {
                    continue;
                }

                $hoja->setCellValueExplicit($columna.$fila, $row[$clave], 'n');
                $hoja->getStyle($columna.$fila)->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);
            }

            $fila++;
        }

        return $fila;
    }

    /**
     * Una fila de totales: rótulo y los tres importes.
     *
     * @param  list<numeric-string>  $amounts
     */
    private function totalRow(Worksheet $hoja, int $fila, string $label, array $amounts, bool $destacada = false): void
    {
        $hoja->setCellValue('A'.$fila, $label);

        foreach (['B', 'C', 'D'] as $indice => $columna) {
            $hoja->setCellValueExplicit($columna.$fila, $amounts[$indice], 'n');
            $hoja->getStyle($columna.$fila)->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);
        }

        $estilo = $hoja->getStyle("A{$fila}:D{$fila}");
        $estilo->getFont()->setBold(true);
        $estilo->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

        if ($destacada) {
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GRIS);
            $estilo->getBorders()->getBottom()->setBorderStyle(Border::BORDER_DOUBLE);
        }
    }

    /** @param  numeric-string  $amount */
    private function reverseTotal(Worksheet $hoja, int $fila, string $label, string $amount, bool $destacada = false): void
    {
        $hoja->setCellValue('A'.$fila, $label);
        // El rótulo ocupa las dos primeras columnas, como en el papel: la
        // de la cantidad de billetes no tiene nada que decir en un total.
        $hoja->mergeCells("A{$fila}:B{$fila}");
        $hoja->setCellValueExplicit('C'.$fila, $amount, 'n');
        $hoja->getStyle('C'.$fila)->getNumberFormat()->setFormatCode(self::FORMATO_IMPORTE);

        $estilo = $hoja->getStyle("A{$fila}:C{$fila}");
        $estilo->getFont()->setBold(true);
        $estilo->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

        if ($destacada) {
            $estilo->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::GRIS);
        }
    }

    /**
     * Excel no admite más de 31 caracteres ni `: \ / ? * [ ]` en el nombre
     * de una hoja, y guardar un libro con uno inválido falla sin decir por
     * qué. Los nombres que usamos son cortos, pero el nombre de la caja
     * entra en juego y ese lo escribe el área.
     */
    private function safeTitle(string $name): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', $name), 0, 31);
    }
}
