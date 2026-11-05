<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Support\FineMovements;
use App\Support\BusinessDate;
use App\Support\Database\ReadSnapshot;
use App\Support\Excel\SpreadsheetWriter;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * La consulta operativa de un mes de Multas, en pantalla y en Excel.
 *
 * **No es la rendición oficial** (P02, Etapa 8): lo dice la pantalla y lo
 * dice el archivo. Cada sección filtra por su propia fecha y se suma sola.
 */
final class FineMovementController extends Controller
{
    /** Las filas de cada sección que muestra la pantalla; el Excel las trae todas. */
    private const EN_PANTALLA = 100;

    public const ROTULO = 'Consulta operativa: no es la rendición oficial.';

    public function __construct(
        private readonly FineMovements $movimientos,
        private readonly ReadSnapshot $foto,
    ) {}

    public function index(Request $request): Response
    {
        $mes = $this->month($request);

        return Inertia::render('multas/movimientos', [
            'month' => $mes->format('Y-m'),
            'currentMonth' => BusinessDate::today()->format('Y-m'),
            'sections' => $this->foto->run(fn (): array => array_map(
                fn (string $clave): array => [
                    'key' => $clave,
                    'title' => FineMovements::SECTIONS[$clave]['title'],
                    'dateLabel' => FineMovements::SECTIONS[$clave]['dateLabel'],
                    ...$this->movimientos->summary($clave, $mes, self::EN_PANTALLA),
                ],
                array_keys(FineMovements::SECTIONS),
            )),
            'shown' => self::EN_PANTALLA,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $mes = $this->month($request);

        return SpreadsheetWriter::download(
            sprintf('movimientos-multas-%s.xlsx', $mes->format('Y-m')),
            ['Sección', 'Fecha que filtra', 'Fecha', 'Recibo', 'Expediente', 'Carátula', 'Detalle', 'Importe'],
            $this->foto->rows(fn (): Generator => $this->filas($mes)),
        );
    }

    /**
     * El rótulo primero, después cada sección con su total. Sin total
     * general: las secciones son hechos distintos del mismo dinero.
     *
     * @return Generator<int, list<string|null>>
     */
    private function filas(CarbonImmutable $mes): Generator
    {
        yield [self::ROTULO, null, null, null, null, null, null, null];

        foreach (FineMovements::SECTIONS as $clave => $seccion) {
            $total = '0.00';

            foreach ($this->movimientos->rows($clave, $mes) as $fila) {
                $total = Decimal::add($total, $fila['amount']);

                yield [
                    $seccion['title'], $seccion['dateLabel'], date('d/m/Y', (int) strtotime($fila['date'])),
                    $fila['receipt'], $fila['caseLabel'], $fila['caption'], $fila['detail'], Decimal::format($fila['amount']),
                ];
            }

            yield [$seccion['title'], 'Total de la sección', null, null, null, null, null, Decimal::format($total)];
        }
    }

    /**
     * El mes pedido (`?mes=AAAA-MM`), el actual por defecto y nunca uno futuro.
     * El año cero no existe en PostgreSQL: se trata como cualquier mes inválido.
     */
    private function month(Request $request): CarbonImmutable
    {
        $hoy = BusinessDate::today()->startOfMonth();
        $pedido = $request->query('mes');

        if (! is_string($pedido) || preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/', $pedido) !== 1) {
            return $hoy;
        }

        try {
            $mes = CarbonImmutable::createFromFormat('!Y-m', $pedido);
        } catch (Throwable) {
            return $hoy;
        }

        return $mes === null || $mes->greaterThan($hoy) ? $hoy : $mes;
    }
}
