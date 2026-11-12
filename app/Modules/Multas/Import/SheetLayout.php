<?php

declare(strict_types=1);

namespace App\Modules\Multas\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * Dónde está cada dato en una hoja anual de la cuenta corriente de Multas.
 *
 * Cada año se armó un poco distinto: el acta está a veces en una columna sin
 * título entre la resolución y la carátula, el expediente se llama «EXPTE.» o
 * «EXPEDIENTE», y los meses de pago vienen como fecha o como texto («SET-08»,
 * «En-15», «AGO.18»). Las columnas se reconocen por su encabezado, en la fila 1.
 */
final class SheetLayout
{
    private const MESES = [
        'EN' => 1, 'ENE' => 1, 'FEB' => 2, 'MAR' => 3, 'AB' => 4, 'ABR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AG' => 8, 'AGO' => 8, 'SET' => 9, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DIC' => 12,
    ];

    /**
     * @param  array<string, string>  $months  letra de columna => primer día del mes (`Y-m-d`)
     * @param  list<string>  $identifying  las columnas de la izquierda del importe: si están todas vacías, la fila no es una multa
     * @param  array<string, string>  $headers  letra de columna => encabezado, tal como está
     */
    public function __construct(
        public readonly string $date,
        public readonly string $case,
        public readonly string $resolution,
        public readonly ?string $act,
        public readonly string $caption,
        public readonly string $amount,
        public readonly array $months,
        public readonly ?string $total,
        public readonly ?string $balance,
        public readonly ?string $plan,
        public readonly ?string $refund,
        public readonly array $identifying,
        public readonly array $headers,
    ) {}

    /**
     * @param  array<string, string>  $header  letra de columna => valor de la fila 1
     */
    public static function detect(array $header): ?self
    {
        $texto = [];

        foreach ($header as $letra => $valor) {
            $texto[$letra] = self::normalize($valor);
        }

        $buscar = function (callable $coincide) use ($texto): ?string {
            foreach ($texto as $letra => $valor) {
                if ($coincide($valor)) {
                    return $letra;
                }
            }

            return null;
        };

        $expediente = $buscar(fn (string $t): bool => str_starts_with($t, 'EXPTE') || str_starts_with($t, 'EXPEDIENTE'));
        $resolucion = $buscar(fn (string $t): bool => str_starts_with($t, 'RESOL'));
        $caratula = $buscar(fn (string $t): bool => str_starts_with($t, 'CARATULA'));
        $importe = $buscar(fn (string $t): bool => str_starts_with($t, 'IMPORTE'));

        if ($expediente === null || $resolucion === null || $caratula === null || $importe === null) {
            return null;
        }

        $acta = $buscar(fn (string $t): bool => str_starts_with($t, 'ACTA'));

        // Sin título: la única columna entre la resolución y la carátula.
        if ($acta === null && self::index($caratula) - self::index($resolucion) === 2) {
            $acta = Coordinate::stringFromColumnIndex(self::index($resolucion) + 1);
        }

        $meses = [];
        $total = $saldo = $plan = $restitucion = null;

        foreach ($header as $letra => $valor) {
            if (self::index($letra) <= self::index($importe)) {
                continue;
            }

            $t = $texto[$letra];
            $mes = self::month($valor);

            match (true) {
                $mes !== null => $meses[$letra] = $mes,
                str_starts_with($t, 'TOTAL') || $t === 'PAGOS' => $total ??= $letra,
                str_starts_with($t, 'SALDO') => $saldo ??= $letra,
                str_starts_with($t, 'PLAN DE PAGO') => $plan ??= $letra,
                str_starts_with($t, 'RESTITUCION') => $restitucion ??= $letra,
                default => null,
            };
        }

        $identifican = [];

        for ($i = 1; $i < self::index($importe); $i++) {
            $identifican[] = Coordinate::stringFromColumnIndex($i);
        }

        return new self(
            date: 'A',
            case: $expediente,
            resolution: $resolucion,
            act: $acta,
            caption: $caratula,
            amount: $importe,
            months: $meses,
            total: $total,
            balance: $saldo,
            plan: $plan,
            refund: $restitucion,
            identifying: $identifican,
            headers: $header,
        );
    }

    /** Las columnas que el importador entiende: lo demás con datos es una columna sin identificar. */
    public function knows(string $letra): bool
    {
        return in_array($letra, [$this->date, $this->case, $this->resolution, $this->act, $this->caption, $this->amount,
            $this->total, $this->balance, $this->plan, $this->refund], true)
            || isset($this->months[$letra])
            || in_array($letra, $this->identifying, true);
    }

    /** El mes de un encabezado: una fecha, un número de serie de Excel o un texto como «SET-08». */
    public static function month(string $valor): ?string
    {
        $valor = trim($valor);

        if (preg_match('~^(\d{4})-(\d{2})-\d{2}~', $valor, $m) === 1) {
            return sprintf('%s-%s-01', $m[1], $m[2]);
        }

        if (preg_match('~^\d{5}(?:\.0+)?$~', $valor) === 1 && (int) $valor > 30000 && (int) $valor < 60000) {
            return Date::excelToDateTimeObject((int) $valor)->format('Y-m-01');
        }

        if (preg_match('~^([A-Za-z]+)[\.\-\s]*(\d{2}|\d{4})$~', $valor, $m) !== 1) {
            return null;
        }

        $mes = self::MESES[strtoupper($m[1])] ?? null;

        if ($mes === null) {
            return null;
        }

        $anio = (int) $m[2];

        return sprintf('%04d-%02d-01', $anio < 100 ? 2000 + $anio : $anio, $mes);
    }

    public static function index(string $letra): int
    {
        return Coordinate::columnIndexFromString($letra);
    }

    private static function normalize(string $valor): string
    {
        $sinTildes = strtr(mb_strtoupper(trim($valor)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);

        return (string) preg_replace('~\s+~', ' ', $sinTildes);
    }
}
