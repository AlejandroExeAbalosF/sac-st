<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Data\MovementRowData;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Support\Money\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lo último que se movió en el sistema.
 *
 * El tablero no lo usa para cuadrar nada —para eso está la caja del día—
 * sino para contestar «¿qué pasó desde ayer?». Por eso no filtra por caja
 * ni por fecha, y por eso incluye los asientos revertidos: una reversión
 * es exactamente el tipo de novedad que alguien quiere ver.
 *
 * Los borradores quedan afuera. Un asiento en `draft` todavía no ocurrió.
 */
final class RecentMovements
{
    /** @return list<MovementRowData> */
    public function latest(int $limit = 8, Currency $currency = Currency::Ars): array
    {
        /*
         * El importe sale de una subconsulta y no de un `join` con
         * `group by`: un asiento tiene varias líneas, y agrupar obligaría
         * a arrastrar todas las columnas del hecho al `GROUP BY` para
         * volver a la misma fila.
         */
        $importe = DB::table('journal_lines')
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0)')
            ->whereColumn('journal_lines.financial_event_id', 'financial_events.id')
            ->where('journal_lines.currency', $currency->value);

        $filas = DB::table('financial_events')
            ->leftJoin('cash_boxes', 'cash_boxes.id', '=', 'financial_events.cash_box_id')
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->orderByDesc('financial_events.posted_at')
            ->orderByDesc('financial_events.id')
            ->limit($limit)
            ->select([
                'financial_events.public_id',
                'financial_events.event_type',
                'financial_events.event_date',
                'financial_events.posted_at',
                'financial_events.status',
                'financial_events.description',
                'cash_boxes.name AS cash_box_name',
            ])
            ->selectSub($importe, 'total')
            ->get();

        return array_values($filas
            ->map(function (object $fila): MovementRowData {
                /** @var object{public_id: string, event_type: string, event_date: string, posted_at: string, status: string, description: ?string, cash_box_name: ?string, total: string} $fila */
                $tipo = FinancialEventType::from($fila->event_type);
                $fecha = Carbon::parse($fila->event_date)->toDateString();

                return new MovementRowData(
                    publicId: $fila->public_id,
                    typeLabel: $tipo->label(),
                    type: $tipo->value,
                    eventDate: $fecha,
                    recordedAt: Carbon::parse($fila->posted_at)->toIso8601String(),
                    amount: Decimal::scale((string) $fila->total),
                    description: $fila->description,
                    cashBoxName: $fila->cash_box_name,
                    isReversed: $fila->status === FinancialEventStatus::Reversed->value,
                    href: route('caja.dia', ['fecha' => $fecha], absolute: false),
                );
            })
            ->all());
    }
}
