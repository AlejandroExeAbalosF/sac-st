<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * El informe de un mes de Aranceles: cuánto se recaudó por tipo y qué más pasó
 * con el dinero (reglas A100 a A105).
 *
 * **Es una consulta con Excel, no una rendición formal:** el área todavía no dio
 * un modelo. Cada sección dice qué fecha filtra y se suma sola, sin total general:
 * un cobro y su traspaso son hechos distintos del mismo dinero.
 *
 * - **Recaudación:** por el mes del primer recibo, con el importe vigente de cada
 *   renglón. Una anulación o corrección es una fila negativa en su propio mes: un
 *   mes ya cerrado no cambia. Un reemplazo de recibo no cambia nada.
 * - **Ingresos parciales:** lo que entró por el banco y no tenía recibo al terminar
 *   el mes. No es recaudación. Se conserva aunque el cobro se complete después.
 * - **Traspasos y acreditaciones:** dónde está la plata, no cuánto se recaudó. Una
 *   cancelación es una fila negativa en su propio mes, igual que una anulación.
 * - **Diferencias:** lo cobrado distinto de lo calculado, para control. Es una
 *   diferencia, no otro importe: ya está dentro de la recaudación. Cada fila es el
 *   neto del renglón, y el total por motivo suma lo que aportó cada cambio.
 *
 * Quien lo consulta lee todas las secciones de una misma foto (`ReadSnapshot`).
 *
 * @phpstan-type Filtros array{medium?: string|null, feeType?: int|null}
 * @phpstan-type Fila array{date: string, receipt: string, payer: string, concept: string, detail: string, amount: numeric-string}
 * @phpstan-type PorTipo array{code: int, name: string, quantity: int, cash: numeric-string, bank: numeric-string, total: numeric-string}
 * @phpstan-type PorMotivo array{reason: string, count: int, total: numeric-string}
 */
final class FeeMovements
{
    /** Las secciones, con la fecha que filtra cada una y si los filtros le aplican. */
    public const SECTIONS = [
        'recaudacion' => ['title' => 'Recaudación', 'dateLabel' => 'Fecha del recibo o de su anulación', 'filtered' => true],
        'parciales' => ['title' => 'Ingresos parciales, sin recibo al terminar el mes', 'dateLabel' => 'Fecha de registro', 'filtered' => false],
        'traspasos' => ['title' => 'Traspasos del efectivo al banco', 'dateLabel' => 'Fecha del traspaso o de su cancelación', 'filtered' => false],
        'acreditaciones' => ['title' => 'Depósitos acreditados', 'dateLabel' => 'Fecha de acreditación', 'filtered' => false],
        'diferencias' => ['title' => 'Importes distintos de lo calculado', 'dateLabel' => 'Fecha del recibo', 'filtered' => true],
    ];

    /** Lo que los dos lados de la recaudación y las diferencias leen de un renglón. */
    private const REVERTIDO_EL = '(SELECT max(e.event_date) FROM fee_charge_fundings f
        JOIN financial_events e ON e.id = f.application_reversal_event_id
        WHERE f.fee_charge_id = cobro.id)';

    /** El último recibo del cobro: un reemplazo corrige nombre y número sin mover nada. */
    private const ULTIMO_RECIBO_NUMERO = '(SELECT COALESCE(r.talonario_number, r.formatted_number)
        FROM fee_charge_receipts l JOIN receipts r ON r.id = l.receipt_id
        WHERE l.fee_charge_id = cobro.id ORDER BY l.id DESC LIMIT 1)';

    private const ULTIMO_RECIBO_PAGADOR = "(SELECT l.snapshot->>'payerName'
        FROM fee_charge_receipts l
        WHERE l.fee_charge_id = cobro.id ORDER BY l.id DESC LIMIT 1)";

    /**
     * Las filas de una sección, de la más vieja a la más nueva, por cursor: el
     * exporte no carga el mes entero en memoria.
     *
     * @param  Filtros  $filtros
     * @return Generator<int, Fila>
     */
    public function rows(string $section, CarbonImmutable $month, array $filtros = []): Generator
    {
        foreach ($this->query($section, $month, $filtros)->orderBy('fecha')->orderBy('orden')->orderBy('renglon')->cursor() as $fila) {
            yield $this->fila($section, $fila);
        }
    }

    /**
     * @param  Filtros  $filtros
     * @return array{count: int, total: numeric-string, rows: list<Fila>}
     */
    public function summary(string $section, CarbonImmutable $month, int $limit, array $filtros = []): array
    {
        $totales = DB::query()->fromSub($this->query($section, $month, $filtros), 'seccion')
            ->selectRaw('COUNT(*) AS cantidad')
            ->selectRaw('COALESCE(SUM(importe), 0) AS total')
            ->first();

        $filas = [];

        foreach ($this->query($section, $month, $filtros)->orderBy('fecha')->orderBy('orden')->orderBy('renglon')->limit($limit)->get() as $fila) {
            $filas[] = $this->fila($section, $fila);
        }

        return [
            'count' => (int) ($totales->cantidad ?? 0),
            'total' => Decimal::scale((string) ($totales->total ?? '0')),
            'rows' => $filas,
        ];
    }

    /**
     * La recaudación por tipo de arancel y medio. Una anulación resta su cantidad.
     *
     * @param  Filtros  $filtros
     * @return list<PorTipo>
     */
    public function byType(CarbonImmutable $month, array $filtros = []): array
    {
        $filas = DB::query()
            ->fromSub($this->query('recaudacion', $month, $filtros), 'r')
            ->join('fee_types AS tipo', 'tipo.id', '=', 'r.tipo')
            ->groupBy('tipo.id', 'tipo.code', 'tipo.name')
            ->orderBy('tipo.code')
            ->select('tipo.code', 'tipo.name')
            ->selectRaw('COALESCE(SUM(r.cantidad), 0) AS cantidad')
            ->selectRaw("COALESCE(SUM(r.importe) FILTER (WHERE r.medio = 'cash'), 0) AS efectivo")
            ->selectRaw("COALESCE(SUM(r.importe) FILTER (WHERE r.medio = 'bank'), 0) AS banco")
            ->selectRaw('COALESCE(SUM(r.importe), 0) AS total')
            ->get();

        $porTipo = [];

        foreach ($filas as $fila) {
            $porTipo[] = [
                'code' => (int) $fila->code,
                'name' => (string) $fila->name,
                'quantity' => (int) $fila->cantidad,
                'cash' => Decimal::scale((string) $fila->efectivo),
                'bank' => Decimal::scale((string) $fila->banco),
                'total' => Decimal::scale((string) $fila->total),
            ];
        }

        return $porTipo;
    }

    /**
     * Lo que aportó cada motivo: el cambio al cobrar y cada ajuste, por separado.
     * Sumados dan el total de la sección de diferencias.
     *
     * @param  Filtros  $filtros
     * @return list<PorMotivo>
     */
    public function byReason(CarbonImmutable $month, array $filtros = []): array
    {
        [$desde, $hasta] = $this->limites($month);

        $iniciales = $this->renglones($filtros)
            ->selectRaw('item.change_reason_code AS motivo_codigo, item.original_amount - item.calculated_amount AS importe')
            ->whereBetween('cobro.paid_on', [$desde, $hasta])
            ->whereColumn('item.original_amount', '<>', 'item.calculated_amount');

        $ajustes = $this->renglones($filtros)
            ->join('fee_charge_item_adjustments AS ajuste', 'ajuste.fee_charge_item_id', '=', 'item.id')
            ->selectRaw('ajuste.change_reason_code AS motivo_codigo, ajuste.new_amount - ajuste.previous_amount AS importe')
            ->whereBetween('cobro.paid_on', [$desde, $hasta]);

        $filas = DB::query()
            ->fromSub($iniciales->unionAll($ajustes), 'cambios')
            ->groupBy('motivo_codigo')
            ->orderBy('motivo_codigo')
            ->select('motivo_codigo')
            ->selectRaw('COUNT(*) AS cantidad')
            ->selectRaw('COALESCE(SUM(importe), 0) AS total')
            ->get();

        $porMotivo = [];

        foreach ($filas as $fila) {
            $porMotivo[] = [
                'reason' => FeeReasonCode::tryFrom((string) $fila->motivo_codigo)?->label() ?? (string) $fila->motivo_codigo,
                'count' => (int) $fila->cantidad,
                'total' => Decimal::scale((string) $fila->total),
            ];
        }

        return $porMotivo;
    }

    /** @param  Filtros  $filtros */
    private function query(string $section, CarbonImmutable $month, array $filtros): Builder
    {
        [$desde, $hasta] = $this->limites($month);

        return match ($section) {
            'recaudacion' => $this->recaudacion($desde, $hasta, $filtros),
            'parciales' => $this->parciales($desde, $hasta),
            'traspasos' => $this->traspasos($desde, $hasta),
            'acreditaciones' => $this->acreditaciones($desde, $hasta),
            'diferencias' => $this->diferencias($desde, $hasta, $filtros),
            default => throw new InvalidArgumentException("La sección «{$section}» no existe."),
        };
    }

    /** @return array{0: string, 1: string} */
    private function limites(CarbonImmutable $month): array
    {
        return [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()];
    }

    /**
     * Un renglón en la fecha de su recibo y, si se anuló o corrigió, en negativo en
     * la fecha de su reversión: anulado en el mismo mes da cero.
     *
     * @param  Filtros  $filtros
     */
    private function recaudacion(string $desde, string $hasta, array $filtros): Builder
    {
        $emitidos = $this->renglones($filtros)
            ->selectRaw('cobro.paid_on AS fecha, vigente.amount AS importe, COALESCE(item.quantity, 1) AS cantidad, FALSE AS es_anulacion')
            ->whereNotNull('cobro.paid_on')
            ->whereBetween('cobro.paid_on', [$desde, $hasta]);

        $revertidos = DB::query()
            ->fromSub(
                $this->renglones($filtros)
                    ->selectRaw(self::REVERTIDO_EL.' AS fecha, -vigente.amount AS importe, -COALESCE(item.quantity, 1) AS cantidad, TRUE AS es_anulacion')
                    ->whereNotNull('cobro.paid_on')
                    ->where('cobro.status', 'voided'),
                'revertidos',
            )
            ->whereBetween('fecha', [$desde, $hasta]);

        return DB::query()->fromSub($emitidos->unionAll($revertidos), 'recaudacion');
    }

    /** @param  Filtros  $filtros */
    private function renglones(array $filtros): Builder
    {
        return DB::table('fee_charge_items AS item')
            ->join('fee_charges AS cobro', 'cobro.id', '=', 'item.fee_charge_id')
            ->join('fee_charge_item_amounts AS vigente', 'vigente.fee_charge_item_id', '=', 'item.id')
            ->leftJoin('fee_charge_corrections AS corregido', 'corregido.corrected_charge_id', '=', 'cobro.id')
            ->leftJoin('fee_charge_corrections AS corrige', 'corrige.new_charge_id', '=', 'cobro.id')
            ->when(($filtros['medium'] ?? null) !== null, fn (Builder $q) => $q->where('cobro.medium', $filtros['medium']))
            ->when(($filtros['feeType'] ?? null) !== null, fn (Builder $q) => $q->where('item.fee_type_id', $filtros['feeType']))
            ->selectRaw('cobro.id AS orden, item.line_number AS renglon, item.fee_type_id AS tipo, cobro.medium AS medio')
            ->selectRaw('item.code_snapshot AS codigo, item.name_snapshot AS nombre')
            ->selectRaw('cobro.void_reason_code AS anulacion_codigo, cobro.void_reason AS anulacion_detalle')
            ->selectRaw('corregido.id AS corregido, corrige.id AS corrige')
            ->selectRaw(self::REVERTIDO_EL.' AS revertido_el')
            ->selectRaw(self::ULTIMO_RECIBO_NUMERO.' AS recibo')
            ->selectRaw('COALESCE('.self::ULTIMO_RECIBO_PAGADOR.', cobro.payer_name) AS pagador');
    }

    /**
     * Lo que entró por el banco y no tenía recibo al terminar el mes: si se completa
     * después, este mes no cambia. Una corrección nace después y no reescribe el pasado.
     */
    private function parciales(string $desde, string $hasta): Builder
    {
        $base = fn (): Builder => DB::table('fee_charge_fundings AS vinculo')
            ->join('fee_charges AS cobro', 'cobro.id', '=', 'vinculo.fee_charge_id')
            ->join('fund_receipts AS recepcion', 'recepcion.id', '=', 'vinculo.fund_receipt_id')
            ->where('recepcion.medium', 'bank')
            ->where('cobro.requested_on', '<=', $hasta)
            ->selectRaw('vinculo.id AS orden, 0 AS renglon, recepcion.received_date AS en_banco, cobro.paid_on AS pagado_el, cobro.void_reason_code AS anulacion_codigo, cobro.void_reason AS anulacion_detalle')
            ->selectRaw('cobro.payer_name AS pagador');

        $ingresos = $base()
            ->join('financial_events AS evento', 'evento.id', '=', 'recepcion.financial_event_id')
            ->whereBetween('evento.event_date', [$desde, $hasta])
            ->where(fn (Builder $q) => $q->whereNull('cobro.paid_on')->orWhere('cobro.paid_on', '>', $hasta))
            ->selectRaw('evento.event_date AS fecha, vinculo.amount AS importe, FALSE AS es_anulacion');

        $reversiones = $base()
            ->join('financial_events AS evento', 'evento.id', '=', 'recepcion.reversal_event_id')
            ->whereBetween('evento.event_date', [$desde, $hasta])
            ->whereNull('cobro.paid_on')
            ->selectRaw('evento.event_date AS fecha, -vinculo.amount AS importe, TRUE AS es_anulacion');

        return DB::query()->fromSub($ingresos->unionAll($reversiones), 'parciales');
    }

    /**
     * Cada traspaso en su mes y su cancelación en negativo, en la fecha del asiento
     * inverso: un mes pasado no cambia, y cancelado en el mismo mes da cero.
     */
    private function traspasos(string $desde, string $hasta): Builder
    {
        $base = fn (): Builder => DB::table('cash_to_bank_transfers AS traslado')
            ->join('bank_accounts AS cuenta', 'cuenta.id', '=', 'traslado.bank_account_id')
            ->where('traslado.cash_box_id', $this->cajaId())
            ->selectRaw('traslado.id AS orden, traslado.status AS estado, cuenta.label AS cuenta_bancaria');

        $salidas = $base()
            ->whereBetween('traslado.deposit_date', [$desde, $hasta])
            ->selectRaw('traslado.deposit_date AS fecha, 0 AS renglon, traslado.amount AS importe, FALSE AS es_cancelacion, NULL::text AS motivo_cancelacion')
            ->selectRaw('(SELECT r.event_date FROM financial_events r WHERE r.reversal_of_id = traslado.deposit_event_id) AS cancelado_el');

        $cancelaciones = $base()
            ->join('financial_events AS revertido', 'revertido.reversal_of_id', '=', 'traslado.deposit_event_id')
            ->where('traslado.status', CashTransferStatus::Cancelled->value)
            ->whereBetween('revertido.event_date', [$desde, $hasta])
            ->selectRaw('revertido.event_date AS fecha, 1 AS renglon, -traslado.amount AS importe, TRUE AS es_cancelacion, revertido.reversal_reason AS motivo_cancelacion')
            ->selectRaw('NULL::date AS cancelado_el');

        return DB::query()->fromSub($salidas->unionAll($cancelaciones), 'traspasos');
    }

    private function acreditaciones(string $desde, string $hasta): Builder
    {
        return DB::table('cash_to_bank_transfers AS traslado')
            ->join('bank_accounts AS cuenta', 'cuenta.id', '=', 'traslado.bank_account_id')
            ->join('financial_events AS evento', 'evento.id', '=', 'traslado.credit_event_id')
            ->where('traslado.cash_box_id', $this->cajaId())
            ->where('traslado.status', CashTransferStatus::BankConfirmed->value)
            ->whereBetween('evento.event_date', [$desde, $hasta])
            ->selectRaw('evento.event_date AS fecha, traslado.id AS orden, 0 AS renglon, traslado.amount AS importe, traslado.deposit_date AS traspaso_el, cuenta.label AS cuenta_bancaria');
    }

    /**
     * Cada renglón que cambió alguna vez, con su neto y lo que aportó cada motivo.
     * También uno que volvió a lo calculado: el neto da cero, pero hubo cambios.
     *
     * @param  Filtros  $filtros
     */
    private function diferencias(string $desde, string $hasta, array $filtros): Builder
    {
        return $this->renglones($filtros)
            ->selectRaw('cobro.paid_on AS fecha, vigente.amount - item.calculated_amount AS importe')
            ->selectRaw('item.calculated_amount AS calculado, vigente.amount AS cobrado')
            ->selectRaw("CASE WHEN item.original_amount <> item.calculated_amount THEN json_build_object('codigo', item.change_reason_code, 'detalle', item.change_reason, 'importe', (item.original_amount - item.calculated_amount)::text)::text END AS cambio_inicial")
            ->selectRaw("(SELECT json_agg(json_build_object('codigo', a.change_reason_code, 'detalle', a.change_reason, 'importe', (a.new_amount - a.previous_amount)::text) ORDER BY a.id)::text FROM fee_charge_item_adjustments a WHERE a.fee_charge_item_id = item.id) AS ajustes")
            ->whereBetween('cobro.paid_on', [$desde, $hasta])
            ->where(fn (Builder $q) => $q
                ->whereColumn('item.original_amount', '<>', 'item.calculated_amount')
                ->orWhereExists(fn (Builder $a) => $a->selectRaw('1')->from('fee_charge_item_adjustments AS a')->whereColumn('a.fee_charge_item_id', 'item.id')));
    }

    private function cajaId(): int
    {
        return (int) CashBox::query()->where('code', CashBox::ARANCELES)->value('id');
    }

    /** @return Fila */
    private function fila(string $section, object $fila): array
    {
        /** @var array<string, mixed> $datos */
        $datos = (array) $fila;
        $texto = fn (string $columna): string => is_scalar($datos[$columna] ?? null) ? (string) $datos[$columna] : '';
        $dia = fn (string $columna): string => CarbonImmutable::parse($texto($columna))->format('d/m/Y');
        $anulacion = fn (): ?string => Reason::text(FeeReasonCode::tryFrom($texto('anulacion_codigo')), $texto('anulacion_detalle') ?: null);
        $esAnulacion = ($datos['es_anulacion'] ?? false) === true;

        $detalle = match ($section) {
            'recaudacion' => implode(' · ', array_filter($esAnulacion
                ? [$texto('corregido') !== '' ? 'Corrección: sale el cobro anterior' : 'Anulación', $anulacion()]
                : [
                    FeeChargeMedium::tryFrom($texto('medio'))?->label(),
                    $texto('corrige') !== '' ? 'corrige un cobro anterior' : null,
                    $texto('revertido_el') !== '' ? 'anulado el '.$dia('revertido_el') : null,
                ])),
            'parciales' => implode(' · ', array_filter($esAnulacion
                ? ['Anulación del ingreso', $anulacion()]
                : [
                    'En el banco el '.$dia('en_banco'),
                    $texto('pagado_el') !== '' ? 'se completó el '.$dia('pagado_el') : null,
                ])),
            'traspasos' => implode(' · ', array_filter(($datos['es_cancelacion'] ?? false) === true
                ? ['Cancelación del traspaso', $texto('cuenta_bancaria'), $texto('motivo_cancelacion')]
                : [
                    $texto('cuenta_bancaria'),
                    $texto('cancelado_el') !== '' ? 'cancelado el '.$dia('cancelado_el') : CashTransferStatus::tryFrom($texto('estado'))?->label(),
                ])),
            'acreditaciones' => implode(' · ', [$texto('cuenta_bancaria'), 'traspaso del '.$dia('traspaso_el')]),
            'diferencias' => implode(' · ', array_filter([
                'calculado $ '.Decimal::format($texto('calculado')),
                'cobrado $ '.Decimal::format($texto('cobrado')),
                ...$this->cambios($texto('cambio_inicial'), $texto('ajustes')),
                $texto('revertido_el') !== '' ? 'anulado el '.$dia('revertido_el') : null,
            ])),
            default => '',
        };

        return [
            'date' => CarbonImmutable::parse($texto('fecha'))->toDateString(),
            'receipt' => $texto('recibo'),
            'payer' => $texto('pagador'),
            'concept' => $texto('codigo') === '' ? '' : $texto('codigo').' · '.$texto('nombre'),
            'detail' => $detalle,
            'amount' => Decimal::scale($texto('importe') ?: '0'),
        ];
    }

    /**
     * Cada cambio del renglón, con su motivo y lo que aportó. Los importes viajan como
     * texto: decodificarlos como número los pasaría por `float`.
     *
     * @return list<string>
     */
    private function cambios(string $inicial, string $ajustes): array
    {
        $lista = $ajustes === '' ? [] : (array) json_decode($ajustes, true, 512, JSON_THROW_ON_ERROR);

        if ($inicial !== '') {
            array_unshift($lista, json_decode($inicial, true, 512, JSON_THROW_ON_ERROR));
        }

        $textos = [];

        foreach ($lista as $cambio) {
            if (! is_array($cambio)) {
                continue;
            }

            $motivo = Reason::text(FeeReasonCode::tryFrom((string) ($cambio['codigo'] ?? '')), isset($cambio['detalle']) ? (string) $cambio['detalle'] : null);
            $importe = Decimal::format((string) ($cambio['importe'] ?? '0'));
            $textos[] = $motivo.': '.(str_starts_with($importe, '-') ? '−$ '.substr($importe, 1) : '$ '.$importe);
        }

        return $textos;
    }
}
