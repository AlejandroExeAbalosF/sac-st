<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * La consulta operativa de un mes de Multas: qué pasó, por sección.
 *
 * **No es la rendición oficial**: su modelo lo define el área (P02, Etapa 8).
 * Por eso cada sección dice qué fecha filtra y se suma sola, sin un total
 * general: un cobro, su aplicación, su depósito y el reemplazo de su cheque
 * son hechos distintos del mismo dinero, y sumarlos lo contaría varias veces
 * (M10). El reemplazo de un cheque no es recaudación; un depósito es un
 * traslado, no un ingreso.
 *
 * Cada multa se nombra con lo que congeló su recibo —expediente y carátula
 * del día del cobro—, por la aplicación de la raíz de la cadena del cheque.
 *
 * Una fila puede **no sumar**: un depósito cancelado queda como antecedente,
 * pero ese dinero no salió de la caja, y contarlo lo sumaría dos veces el
 * día que se vuelva a depositar. La sección informa aparte cuánto quedó
 * fuera de su total.
 *
 * @phpstan-type Fila array{date: string, receipt: string, caseLabel: string, caption: string, detail: string, amount: numeric-string, counted: bool}
 */
final class FineMovements
{
    /** Las secciones, en el orden del recorrido del dinero, con la fecha que filtra cada una. */
    public const SECTIONS = [
        'cobros' => ['title' => 'Cobros en caja', 'dateLabel' => 'Fecha de recepción'],
        'aplicaciones' => ['title' => 'Aplicaciones a la deuda', 'dateLabel' => 'Fecha de aplicación'],
        // Lo que un pago trajo de más (M05): no reduce la deuda, y se suma aparte.
        'excedentes' => ['title' => 'Excedentes al aplicar', 'dateLabel' => 'Fecha de aplicación'],
        'depositos' => ['title' => 'Depósitos al banco', 'dateLabel' => 'Fecha del depósito'],
        'rechazos' => ['title' => 'Cheques rechazados', 'dateLabel' => 'Fecha del rechazo'],
        'reemplazos' => ['title' => 'Reemplazos de cheques', 'dateLabel' => 'Fecha de recepción del reemplazo'],
        'creditos' => ['title' => 'Créditos del banco identificados', 'dateLabel' => 'Fecha de verificación'],
    ];

    /**
     * Las filas de una sección en el mes, de la más vieja a la más nueva, por
     * cursor: el exporte no carga el mes entero en memoria.
     *
     * @return Generator<int, Fila>
     */
    public function rows(string $section, CarbonImmutable $month): Generator
    {
        foreach ($this->query($section, $month)->orderBy('fecha')->orderBy('orden')->cursor() as $fila) {
            yield $this->fila($section, $fila);
        }
    }

    /**
     * Cuántas filas, cuánto suman las que cuentan, cuánto quedó fuera del
     * total y las primeras filas, para la pantalla.
     *
     * @return array{count: int, total: numeric-string, excludedCount: int, excludedTotal: numeric-string, rows: list<Fila>}
     */
    public function summary(string $section, CarbonImmutable $month, int $limit): array
    {
        $base = $this->query($section, $month);
        $totales = DB::query()->fromSub($base, 'seccion')
            ->selectRaw('COUNT(*) AS cantidad')
            ->selectRaw('COALESCE(SUM(importe) FILTER (WHERE cuenta), 0) AS total')
            ->selectRaw('COUNT(*) FILTER (WHERE NOT cuenta) AS apartadas')
            ->selectRaw('COALESCE(SUM(importe) FILTER (WHERE NOT cuenta), 0) AS apartado')
            ->first();

        $filas = [];
        foreach ((clone $base)->orderBy('fecha')->orderBy('orden')->limit($limit)->get() as $fila) {
            $filas[] = $this->fila($section, $fila);
        }

        return [
            'count' => (int) ($totales->cantidad ?? 0),
            'total' => Decimal::scale((string) ($totales->total ?? '0')),
            'excludedCount' => (int) ($totales->apartadas ?? 0),
            'excludedTotal' => Decimal::scale((string) ($totales->apartado ?? '0')),
            'rows' => $filas,
        ];
    }

    private function query(string $section, CarbonImmutable $month): Builder
    {
        $desde = $month->startOfMonth()->toDateString();
        $hasta = $month->endOfMonth()->toDateString();
        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');

        /*
         * Qué filas suman. Solo el depósito cancelado queda fuera: no
         * ocurrió. Uno devuelto por un rechazo sí ocurrió —el cheque fue al
         * banco y volvió— y suma; su reemplazo es otro instrumento.
         */
        $cuenta = DB::raw($section === 'depositos' ? "traslado.status <> 'cancelled' AS cuenta" : 'TRUE AS cuenta');

        return match ($section) {
            // Lo que entró en la caja: la recepción original, no su reemplazo.
            'cobros' => $this->conMulta(DB::table('fund_receipts AS cheque'))
                ->where('cheque.cash_box_id', $caja)
                ->where('cheque.origin', 'received')
                ->whereIn('cheque.medium', ['cash', 'cheque'])
                ->whereBetween('cheque.received_date', [$desde, $hasta])
                ->addSelect([
                    'cheque.received_date AS fecha', 'cheque.id AS orden', 'cheque.amount AS importe',
                    'cheque.medium AS medio', 'cheque.cheque_number AS numero', $cuenta,
                ]),

            'aplicaciones' => $this->conPapel(DB::table('fine_payment_applications AS aplicacion'))
                ->join('financial_events AS aplicada', 'aplicada.id', '=', 'aplicacion.application_event_id')
                ->join('fine_payments AS pago', 'pago.id', '=', 'aplicacion.fine_payment_id')
                ->whereBetween('aplicada.event_date', [$desde, $hasta])
                ->addSelect([
                    // Lo que redujo la deuda; el excedente va en su sección.
                    'aplicada.event_date AS fecha', 'aplicacion.id AS orden', 'aplicacion.applied_amount AS importe',
                    'pago.medium AS medio', 'aplicacion.timeliness AS termino',
                    'aplicacion.excess_amount AS excedente', $cuenta,
                ]),

            'excedentes' => $this->conPapel(DB::table('fine_payment_applications AS aplicacion'))
                ->join('financial_events AS aplicada', 'aplicada.id', '=', 'aplicacion.application_event_id')
                ->join('fine_payments AS pago', 'pago.id', '=', 'aplicacion.fine_payment_id')
                ->where('aplicacion.excess_amount', '>', 0)
                ->whereBetween('aplicada.event_date', [$desde, $hasta])
                ->addSelect([
                    'aplicada.event_date AS fecha', 'aplicacion.id AS orden', 'aplicacion.excess_amount AS importe',
                    'pago.medium AS medio', 'aplicacion.amount AS pagado', $cuenta,
                ]),

            'depositos' => $this->conMulta(
                DB::table('cash_to_bank_transfers AS traslado')
                    ->join('cash_to_bank_transfer_items AS item', 'item.cash_to_bank_transfer_id', '=', 'traslado.id')
                    ->join('fund_receipts AS cheque', 'cheque.id', '=', 'item.fund_receipt_id'),
            )
                ->where('traslado.cash_box_id', $caja)
                ->whereBetween('traslado.deposit_date', [$desde, $hasta])
                ->addSelect([
                    'traslado.deposit_date AS fecha', 'traslado.id AS orden', 'item.amount AS importe',
                    'cheque.medium AS medio', 'cheque.cheque_number AS numero', 'traslado.status AS estado', $cuenta,
                ]),

            'rechazos' => $this->conMulta(
                DB::table('cheque_rejections AS rechazo')
                    ->join('fund_receipts AS cheque', 'cheque.id', '=', 'rechazo.fund_receipt_id'),
            )
                ->where('cheque.cash_box_id', $caja)
                ->whereBetween('rechazo.rejected_on', [$desde, $hasta])
                ->addSelect([
                    'rechazo.rejected_on AS fecha', 'rechazo.id AS orden', 'cheque.amount AS importe',
                    'cheque.cheque_number AS numero', 'rechazo.reason AS motivo', $cuenta,
                ]),

            'reemplazos' => $this->conMulta(
                DB::table('fund_receipts AS cheque')
                    ->join('fund_receipts AS rechazado', 'rechazado.id', '=', 'cheque.replaces_fund_receipt_id'),
            )
                ->where('cheque.cash_box_id', $caja)
                ->where('cheque.origin', 'replacement')
                ->whereBetween('cheque.received_date', [$desde, $hasta])
                ->addSelect([
                    'cheque.received_date AS fecha', 'cheque.id AS orden', 'cheque.amount AS importe',
                    'cheque.cheque_number AS numero', 'rechazado.cheque_number AS reemplazado', $cuenta,
                ]),

            // Lo que llegó por el banco y se reconoció: el día en que se verificó.
            'creditos' => $this->conPapel(DB::table('fine_payment_applications AS aplicacion'))
                ->join('financial_events AS aplicada', 'aplicada.id', '=', 'aplicacion.application_event_id')
                ->join('fine_payments AS pago', 'pago.id', '=', 'aplicacion.fine_payment_id')
                ->whereNotIn('pago.medium', ['cash', 'cheque'])
                ->whereBetween('aplicada.event_date', [$desde, $hasta])
                ->addSelect([
                    'aplicada.event_date AS fecha', 'aplicacion.id AS orden', 'aplicacion.amount AS importe',
                    'pago.medium AS medio', $cuenta,
                ]),

            default => throw new InvalidArgumentException("La sección «{$section}» no existe."),
        };
    }

    /**
     * La multa del instrumento `cheque` —efectivo o cheque, con ese alias en
     * todas las secciones—, por la aplicación de la raíz de su cadena.
     */
    private function conMulta(Builder $query): Builder
    {
        return $this->conPapel($query->leftJoin(
            'fine_payment_applications AS aplicacion',
            DB::raw('aplicacion.fund_receipt_id'),
            '=',
            DB::raw('fund_receipt_root(cheque.id)'),
        ));
    }

    /** El recibo de la aplicación, con lo que congeló de la multa. */
    private function conPapel(Builder $query): Builder
    {
        return $query
            ->leftJoin('fine_payment_receipts AS papel', 'papel.fine_payment_id', '=', 'aplicacion.fine_payment_id')
            ->leftJoin('receipts AS recibo', 'recibo.id', '=', 'papel.receipt_id')
            ->addSelect([
                DB::raw("papel.snapshot->>'caseLabel' AS expediente"),
                DB::raw("papel.snapshot->>'caption' AS caratula"),
                DB::raw('COALESCE(recibo.talonario_number, recibo.formatted_number) AS recibo'),
            ]);
    }

    /** @return Fila */
    private function fila(string $section, object $fila): array
    {
        /** @var array<string, mixed> $datos */
        $datos = (array) $fila;
        $texto = fn (string $columna): string => is_scalar($datos[$columna] ?? null) ? (string) $datos[$columna] : '';

        $medio = FinePaymentMedium::tryFrom($texto('medio'))?->label();
        $cheque = $texto('numero') === '' ? null : 'cheque n.º '.$texto('numero');

        $detalle = match ($section) {
            'cobros' => $cheque ?? $medio,
            'aplicaciones' => implode(' · ', array_filter([
                $medio,
                FineTimeliness::tryFrom($texto('termino'))?->label(),
                Decimal::equals($texto('excedente') ?: '0', '0') ? null : 'excedente '.Decimal::format($texto('excedente')),
            ])),
            'depositos' => implode(' · ', array_filter([
                $cheque ?? $medio,
                CashTransferStatus::tryFrom($texto('estado'))?->label(),
            ])),
            'rechazos' => implode(' · ', array_filter([$cheque, $texto('motivo')])),
            'reemplazos' => sprintf('%s, reemplaza el cheque n.º %s — no es recaudación', $cheque ?? 'cheque', $texto('reemplazado')),
            'creditos' => $medio,
            'excedentes' => implode(' · ', array_filter([
                $medio,
                $texto('pagado') === '' ? null : 'pagó '.Decimal::format($texto('pagado')),
                'no reduce la deuda',
            ])),
            default => null,
        };

        return [
            'date' => CarbonImmutable::parse($texto('fecha'))->toDateString(),
            'receipt' => $texto('recibo'),
            'caseLabel' => $texto('expediente'),
            'caption' => $texto('caratula'),
            'detail' => (string) $detalle,
            'amount' => Decimal::scale($texto('importe') ?: '0'),
            'counted' => (bool) ($datos['cuenta'] ?? true),
        ];
    }
}
