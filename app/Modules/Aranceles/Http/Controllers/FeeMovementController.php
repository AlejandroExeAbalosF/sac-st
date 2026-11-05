<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Http\Requests\FeeMovementFilterRequest;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Support\FeeMovements;
use App\Support\BusinessDate;
use App\Support\Database\ReadSnapshot;
use App\Support\Excel\SpreadsheetWriter;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Generator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El informe de un mes de Aranceles, en pantalla y en Excel (regla A105).
 *
 * **No es una rendición formal:** lo dice la pantalla y lo dice el archivo. Cada
 * sección filtra por su propia fecha y se suma sola, y todas leen la misma foto: si
 * alguien cobra mientras se arma, el resumen y el detalle siguen coincidiendo.
 */
final class FeeMovementController extends Controller
{
    /** Las filas de cada sección que muestra la pantalla; el Excel las trae todas. */
    private const EN_PANTALLA = 100;

    public const ROTULO = 'Consulta operativa: no es una rendición formal. Las secciones no se suman entre sí.';

    public function __construct(
        private readonly FeeMovements $movimientos,
        private readonly ReadSnapshot $foto,
    ) {}

    public function index(FeeMovementFilterRequest $request): Response
    {
        $mes = $request->month();
        $filtros = $request->filters();

        $informe = $this->foto->run(fn (): array => [
            'byType' => $this->movimientos->byType($mes, $filtros),
            'byReason' => $this->movimientos->byReason($mes, $filtros),
            'sections' => array_map(
                fn (string $clave): array => [
                    'key' => $clave,
                    'title' => FeeMovements::SECTIONS[$clave]['title'],
                    'dateLabel' => FeeMovements::SECTIONS[$clave]['dateLabel'],
                    'filtered' => FeeMovements::SECTIONS[$clave]['filtered'],
                    ...$this->movimientos->summary($clave, $mes, self::EN_PANTALLA, $filtros),
                ],
                array_keys(FeeMovements::SECTIONS),
            ),
        ]);

        return Inertia::render('aranceles/movimientos', [
            'month' => $mes->format('Y-m'),
            'currentMonth' => BusinessDate::today()->format('Y-m'),
            'filters' => ['medio' => $filtros['medium'], 'tipo' => $filtros['feeType']],
            'feeTypes' => FeeType::query()->orderBy('code')->get(['id', 'code', 'name'])
                ->map(fn (FeeType $t): array => ['id' => $t->id, 'code' => $t->code, 'name' => $t->name])
                ->values()
                ->all(),
            ...$informe,
            'shown' => self::EN_PANTALLA,
        ]);
    }

    public function export(FeeMovementFilterRequest $request): StreamedResponse
    {
        $mes = $request->month();
        $filtros = $request->filters();

        return SpreadsheetWriter::download(
            sprintf('movimientos-aranceles-%s.xlsx', $mes->format('Y-m')),
            ['Sección', 'Fecha que filtra', 'Fecha', 'Recibo', 'Quién paga', 'Concepto', 'Detalle', 'Importe'],
            $this->foto->rows(fn (): Generator => $this->filas($mes, $filtros)),
        );
    }

    /**
     * El rótulo y los filtros, la recaudación por tipo, y cada sección con su total.
     *
     * @param  array{medium: string|null, feeType: int|null}  $filtros
     * @return Generator<int, list<string|null>>
     */
    private function filas(CarbonImmutable $mes, array $filtros): Generator
    {
        yield [self::ROTULO, null, null, null, null, null, null, null];

        if ($filtros['medium'] !== null || $filtros['feeType'] !== null) {
            yield ['Con filtros: la recaudación y las diferencias no son las del mes entero.', null, null, null, null, null, null, null];
        }

        $total = '0.00';

        foreach ($this->movimientos->byType($mes, $filtros) as $tipo) {
            $total = Decimal::add($total, $tipo['total']);

            yield [
                'Recaudación por tipo', null, null, null, null, $tipo['code'].' · '.$tipo['name'],
                sprintf('Cantidad %d · efectivo $ %s · banco $ %s', $tipo['quantity'], Decimal::format($tipo['cash']), Decimal::format($tipo['bank'])),
                Decimal::format($tipo['total']),
            ];
        }

        yield ['Recaudación por tipo', 'Total', null, null, null, null, null, Decimal::format($total)];

        foreach (FeeMovements::SECTIONS as $clave => $seccion) {
            $total = '0.00';

            foreach ($this->movimientos->rows($clave, $mes, $filtros) as $fila) {
                $total = Decimal::add($total, $fila['amount']);

                yield [
                    $seccion['title'], $seccion['dateLabel'], CarbonImmutable::parse($fila['date'])->format('d/m/Y'),
                    $fila['receipt'], $fila['payer'], $fila['concept'], $fila['detail'], Decimal::format($fila['amount']),
                ];
            }

            yield [$seccion['title'], 'Total de la sección', null, null, null, null, null, Decimal::format($total)];
        }

        foreach ($this->movimientos->byReason($mes, $filtros) as $motivo) {
            yield ['Diferencias por motivo', null, null, null, null, $motivo['reason'], sprintf('%d cambios', $motivo['count']), Decimal::format($motivo['total'])];
        }
    }
}
