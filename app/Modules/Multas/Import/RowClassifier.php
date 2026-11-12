<?php

declare(strict_types=1);

namespace App\Modules\Multas\Import;

use App\Modules\Multas\Enums\FineActType;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Multas\Support\FineDocumentNumber;
use App\Modules\Multas\Support\TwoDigitYear;
use App\Support\Money\Decimal;

/**
 * Lee una fila de la planilla y dice qué es y qué problemas tiene.
 *
 * Lo que la planilla muestra se conserva como está. Lo que se infiere —el
 * expediente, la resolución, el acta— se normaliza con los mismos lectores que
 * la carga manual, y lo que no se entiende queda como texto, para completar.
 * Ningún año se completa con el de la hoja: es una deducción, no un dato.
 *
 * Tres controles con lo que la propia planilla dice: la suma de los meses
 * contra el total pagado, el importe menos lo pagado contra el saldo, y cada
 * pago contra el importe de la multa. Celda vacía y cero no son lo mismo.
 *
 * @phpstan-type Celda array{value: string, formula: string|null, missing: bool}
 * @phpstan-type Problema array{code: string, blocking: bool, column: string|null, value: string|null, message: string, meta?: array<string, mixed>}
 * @phpstan-type Pago array{column: string, month: string, amount: numeric-string}
 * @phpstan-type Fila array{kind: 'fine'|'footer'|'empty', row: int, cells: array<string, array{v: string, f?: string, m?: bool, h?: string}>, normalized: array<string, mixed>, identity_key: string|null, text_key: string|null, issues: list<Problema>}
 */
final class RowClassifier
{
    /**
     * @param  array<string, Celda>  $celdas
     * @param  string  $corte  el primer día del mes de la apertura, `Y-m-d`
     * @return Fila
     */
    public function classify(SheetLayout $layout, string $hoja, int $numero, array $celdas, string $corte): array
    {
        $originales = $this->originales($layout, $celdas);
        $valor = fn (?string $letra): string => $letra === null ? '' : trim($celdas[$letra]['value'] ?? '');

        if ($this->vacia($celdas)) {
            return ['kind' => 'empty', 'row' => $numero, 'cells' => $originales, 'normalized' => [], 'identity_key' => null, 'text_key' => null, 'issues' => []];
        }

        if ($this->esPie($layout, $celdas)) {
            return [
                'kind' => 'footer',
                'row' => $numero,
                'cells' => $originales,
                'normalized' => [
                    'amount' => $this->importe($valor($layout->amount)),
                    'paid' => $this->sumaDeMeses($layout, $celdas),
                ],
                'identity_key' => null,
                'text_key' => null,
                'issues' => [],
            ];
        }

        $problemas = [];
        $anioDeHoja = (int) $hoja;

        // ── Identificación ───────────────────────────────────────────────
        $fecha = $this->fecha($valor($layout->date));
        if ($fecha === null) {
            $problemas[] = $this->problema(FineImportIssue::MissingDate, $layout->date, $valor($layout->date), 'La fila no tiene una fecha que se pueda leer.');
        }

        $textoExpediente = $valor($layout->case);
        $expediente = $this->expediente($textoExpediente);
        if ($expediente === null || $expediente['year'] === null) {
            $problemas[] = $this->problema(FineImportIssue::CaseIncomplete, $layout->case, $textoExpediente,
                $textoExpediente === '' ? 'Falta el expediente.' : 'El expediente no tiene año o no se entiende: se conserva como está escrito.');
        }

        $textoResolucion = $valor($layout->resolution);
        $resolucion = $this->documento($textoResolucion, $anioDeHoja);
        if ($resolucion === null || $resolucion['year'] === null) {
            $problemas[] = $this->problema(FineImportIssue::ResolutionIncomplete, $layout->resolution, $textoResolucion,
                $textoResolucion === '' ? 'Falta la resolución.' : 'La resolución no tiene año o no se entiende: se conserva como está escrita.');
        }

        $textoActa = $valor($layout->act);
        $acta = $textoActa === '' ? null : $this->documento($textoActa, $anioDeHoja);
        if ($textoActa !== '' && ($acta === null || $acta['type'] === null || $acta['year'] === null)) {
            $problemas[] = $this->problema(FineImportIssue::ActIncomplete, $layout->act, $textoActa, 'El acta no dice su tipo, su número o su año: se conserva como está escrita.');
        }

        $caratula = $valor($layout->caption);

        // ── Importe ──────────────────────────────────────────────────────
        $importe = $this->importe($valor($layout->amount));
        $sinImporte = $importe === null || ! Decimal::isNegative(Decimal::sub('0', $importe));
        if ($sinImporte || $caratula === '' || $textoExpediente === '') {
            $problemas[] = $this->problema(FineImportIssue::MissingAmount, $layout->amount, $valor($layout->amount), match (true) {
                $sinImporte => 'La fila no tiene un importe positivo: se carga a mano.',
                $caratula === '' => 'La fila no tiene carátula: se carga a mano.',
                default => 'La fila no tiene expediente: se carga a mano.',
            });
        }

        // ── Pagos por mes ────────────────────────────────────────────────
        $pagos = [];
        $posteriores = [];
        $pagadoTodo = '0.00';
        $pagadoAntes = '0.00';

        foreach ($layout->months as $letra => $mes) {
            if (! isset($celdas[$letra])) {
                continue;
            }

            $celda = $celdas[$letra];
            $texto = trim($celda['value']);

            if ($celda['missing']) {
                $problemas[] = $this->problema(FineImportIssue::FormulaWithoutResult, $letra, $celda['formula'], 'La fórmula de este mes no tiene resultado guardado.');

                continue;
            }

            $monto = $this->importe($texto);

            if ($monto === null) {
                if ($texto !== '') {
                    $problemas[] = $this->problema(FineImportIssue::NoteInPayment, $letra, $texto, "La celda de {$this->mes($mes)} tiene una nota en vez de un importe.");
                }

                continue;
            }

            if (Decimal::equals($monto, '0')) {
                continue;
            }

            if (Decimal::isNegative($monto)) {
                $problemas[] = $this->problema(FineImportIssue::NegativePayment, $letra, $texto, "El pago de {$this->mes($mes)} es negativo.");

                continue;
            }

            $pagadoTodo = Decimal::add($pagadoTodo, $monto);

            if ($importe !== null && ! Decimal::equals($importe, '0') && ! Decimal::isNegative($importe) && Decimal::isNegative(Decimal::sub($importe, $monto))) {
                $problemas[] = $this->problema(FineImportIssue::PaymentExceedsAmount, $letra, $texto,
                    sprintf('El pago de %s ($ %s) es mayor que la multa ($ %s).', $this->mes($mes), Decimal::format($monto), Decimal::format($importe)));
            }

            if ($mes >= $corte) {
                $posteriores[] = ['column' => $letra, 'month' => $mes, 'amount' => $monto];

                continue;
            }

            $pagadoAntes = Decimal::add($pagadoAntes, $monto);
            $pagos[] = ['column' => $letra, 'month' => $mes, 'amount' => $monto];
        }

        foreach ($posteriores as $posterior) {
            $problemas[] = $this->problema(FineImportIssue::AfterCutoff, $posterior['column'], $posterior['amount'],
                sprintf('Pago de %s desde el mes de la apertura: no se importa y queda pendiente de identificar.', $this->mes($posterior['month'])));
        }

        // ── Controles con lo que dice la planilla ─────────────────────────
        $total = null;

        if ($layout->total !== null) {
            $celda = $celdas[$layout->total] ?? null;

            if ($celda !== null && $celda['missing']) {
                $problemas[] = $this->problema(FineImportIssue::FormulaWithoutResult, $layout->total, $celda['formula'], 'El total pagado es una fórmula sin resultado guardado.');
            } elseif ($celda === null || trim($celda['value']) === '') {
                if (! Decimal::equals($pagadoTodo, '0')) {
                    $problemas[] = $this->problema(FineImportIssue::TotalMissing, $layout->total, null, 'La fila tiene pagos pero no tiene total pagado.');
                }
            } elseif (($total = $this->importe($celda['value'])) === null || ! Decimal::equals($total, $pagadoTodo)) {
                $problemas[] = $this->problema(FineImportIssue::TotalMismatch, $layout->total, $celda['value'],
                    sprintf('El total pagado dice $ %s y los meses suman $ %s.', $total === null ? $celda['value'] : Decimal::format($total), Decimal::format($pagadoTodo)));
            }
        }

        if ($layout->balance !== null && $importe !== null) {
            $celda = $celdas[$layout->balance] ?? null;
            $esperado = Decimal::sub($importe, $total ?? $pagadoTodo);

            if ($celda !== null && $celda['missing']) {
                $problemas[] = $this->problema(FineImportIssue::FormulaWithoutResult, $layout->balance, $celda['formula'], 'El saldo es una fórmula sin resultado guardado.');
            } elseif ($celda === null || trim($celda['value']) === '') {
                $problemas[] = $this->problema(FineImportIssue::BalanceMissing, $layout->balance, null, 'La fila no tiene saldo.');
            } elseif (($saldo = $this->importe($celda['value'])) === null || ! Decimal::equals($saldo, $esperado)) {
                $problemas[] = $this->problema(FineImportIssue::BalanceMismatch, $layout->balance, $celda['value'],
                    sprintf('El saldo dice $ %s y el importe menos lo pagado da $ %s.', $saldo === null ? $celda['value'] : Decimal::format($saldo), Decimal::format($esperado)));
            }
        }

        if ($importe !== null && Decimal::isNegative(Decimal::sub($importe, $pagadoAntes))) {
            $problemas[] = $this->problema(FineImportIssue::Overpaid, $layout->amount, $importe,
                sprintf('Lo pagado ($ %s) supera la multa: el sobrante queda como excedente anterior al sistema.', Decimal::format($pagadoAntes)));
        }

        // ── Columnas que el importador no carga ──────────────────────────
        foreach ([[$layout->plan, FineImportIssue::PlanColumn], [$layout->refund, FineImportIssue::RefundColumn]] as [$letra, $codigo]) {
            if ($letra !== null && $this->tieneDato($celdas[$letra] ?? null)) {
                $problemas[] = $this->problema($codigo, $letra, $celdas[$letra]['value'], 'Esta columna no se importa: el importe queda en la planilla.');
            }
        }

        foreach ($celdas as $letra => $celda) {
            if (! $layout->knows($letra) && $this->tieneDato($celda)) {
                $problemas[] = $this->problema(FineImportIssue::UnknownColumn, $letra, $celda['value'], 'Hay un dato en una columna que el importador no reconoce.');
            }
        }

        $identidad = $expediente !== null && $expediente['year'] !== null && $resolucion !== null && $resolucion['year'] !== null
            ? sprintf('%d/%d/%s|%d/%d', $expediente['number'], $expediente['year'], $expediente['suffix'] ?? '', $resolucion['number'], $resolucion['year'])
            : null;

        return [
            'kind' => 'fine',
            'row' => $numero,
            'cells' => $originales,
            'normalized' => [
                'date' => $fecha,
                'case_text' => $textoExpediente,
                'case' => $expediente,
                'resolution_text' => $textoResolucion,
                'resolution' => $resolucion,
                'act_text' => $textoActa === '' ? null : $textoActa,
                'act' => $acta,
                'caption' => $caratula,
                'amount' => $importe,
                'payments' => $pagos,
                'after_cutoff' => $posteriores,
                'paid' => $pagadoTodo,
            ],
            'identity_key' => $identidad,
            'text_key' => $identidad === null ? self::textKey($textoExpediente, $textoResolucion) : null,
            'issues' => $problemas,
        ];
    }

    /** Para cotejar lo que no tiene identidad completa: el texto, sin espacios de más ni mayúsculas. */
    public static function textKey(string $expediente, string $resolucion): string
    {
        $limpio = fn (string $t): string => (string) preg_replace('~\s+~', ' ', mb_strtoupper(trim($t)));

        return $limpio($expediente).'|'.$limpio($resolucion);
    }

    /**
     * @param  array<string, Celda>  $celdas
     * @return array<string, array{v: string, f?: string, m?: bool, h?: string}>
     */
    private function originales(SheetLayout $layout, array $celdas): array
    {
        $originales = [];

        foreach ($celdas as $letra => $celda) {
            $original = ['v' => $celda['value']];

            if ($celda['formula'] !== null) {
                $original['f'] = $celda['formula'];
            }

            if ($celda['missing']) {
                $original['m'] = true;
            }

            $encabezado = trim($layout->headers[$letra] ?? '');

            if ($encabezado !== '') {
                $original['h'] = $encabezado;
            }

            $originales[$letra] = $original;
        }

        return $originales;
    }

    /**
     * @param  array<string, Celda>  $celdas
     */
    private function vacia(array $celdas): bool
    {
        foreach ($celdas as $celda) {
            if ($this->tieneDato($celda)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Un pie de control: el rótulo TOTALES, o ningún dato de identificación a
     * la izquierda del importe (como los subtotales sin rótulo de 2019).
     *
     * @param  array<string, Celda>  $celdas
     */
    private function esPie(SheetLayout $layout, array $celdas): bool
    {
        $identifica = false;

        foreach ($layout->identifying as $letra) {
            $texto = mb_strtoupper(trim($celdas[$letra]['value'] ?? ''));

            if ($texto === 'TOTAL' || $texto === 'TOTALES') {
                return true;
            }

            if ($texto !== '' && $texto !== '0') {
                $identifica = true;
            }
        }

        return ! $identifica;
    }

    /**
     * @param  array<string, Celda>  $celdas
     * @return numeric-string
     */
    private function sumaDeMeses(SheetLayout $layout, array $celdas): string
    {
        $suma = '0.00';

        foreach ($layout->months as $letra => $mes) {
            $monto = $this->importe($celdas[$letra]['value'] ?? '');

            if ($monto !== null) {
                $suma = Decimal::add($suma, $monto);
            }
        }

        return $suma;
    }

    /**
     * @param  Celda|null  $celda
     */
    private function tieneDato(?array $celda): bool
    {
        if ($celda === null) {
            return false;
        }

        $texto = trim($celda['value']);

        return $texto !== '' && ($this->importe($texto) === null || ! Decimal::equals((string) $this->importe($texto), '0'));
    }

    /** @return numeric-string|null */
    private function importe(string $texto): ?string
    {
        $texto = trim($texto);

        return preg_match('~^-?\d+(\.\d+)?$~', $texto) === 1 ? Decimal::scale($texto) : null;
    }

    private function fecha(string $texto): ?string
    {
        return preg_match('~^(\d{4}-\d{2}-\d{2})~', $texto, $m) === 1 ? $m[1] : null;
    }

    /** @return array{number: int, year: int|null, suffix: string|null}|null */
    private function expediente(string $texto): ?array
    {
        $numero = FineCaseNumber::parse($texto);

        if ($numero !== null && TwoDigitYear::inRange($numero->year)) {
            return ['number' => $numero->number, 'year' => $numero->year, 'suffix' => $numero->suffix];
        }

        // Un número suelto, como en las hojas de 2007 y 2008: sin año.
        if (preg_match('~^\s*(\d{1,9})(?:\.0+)?\s*$~', $texto, $m) === 1 && (int) $m[1] > 0) {
            return ['number' => (int) $m[1], 'year' => null, 'suffix' => null];
        }

        return null;
    }

    /** @return array{number: int, year: int|null, type: string|null}|null */
    private function documento(string $texto, int $anioDeHoja): ?array
    {
        // «LAB.», «Nº», «N °», «2773.0»: variantes de la planilla que el lector manual no espera.
        $limpio = (string) preg_replace(['~\b(LAB|HYS)\.~i', '~N\s*[°º]~u', '~^(\d+)\.0+$~'], ['$1', 'N°', '$1'], trim($texto));
        $leido = FineDocumentNumber::parse($limpio, $anioDeHoja);

        if ($leido === null || $leido->number <= 0) {
            return null;
        }

        $anio = $leido->year !== null && TwoDigitYear::inRange($leido->year) ? $leido->year : null;

        return [
            'number' => $leido->number,
            'year' => $anio,
            'type' => match ($leido->actType) {
                FineActType::Labor => 'LAB',
                FineActType::HealthSafety => 'HYS',
                default => null,
            },
        ];
    }

    private function mes(string $mes): string
    {
        return substr($mes, 5, 2).'/'.substr($mes, 0, 4);
    }

    /**
     * @return Problema
     */
    private function problema(FineImportIssue $codigo, ?string $columna, ?string $valor, string $mensaje): array
    {
        return [
            'code' => $codigo->value,
            'blocking' => $codigo->blocking(),
            'column' => $columna,
            'value' => $valor,
            'message' => $mensaje,
        ];
    }
}
