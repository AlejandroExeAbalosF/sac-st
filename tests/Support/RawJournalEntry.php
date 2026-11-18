<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Un asiento escrito directo en la base, sin `PostJournalEntry`, por el único
 * camino que la base admite: el evento nace en borrador, recibe sus líneas y
 * recién después pasa al estado pedido (`journal_lines_sealed`).
 *
 * Las pruebas lo usan para armar escenarios que el Action no deja —un evento
 * con fecha vieja, un saldo histórico— o para que la guarda de la base sea la
 * única que decida.
 */
final class RawJournalEntry
{
    /**
     * @param  array<string, mixed>  $event  Las columnas del evento, con el
     *                                       estado final y sus datos de posteo.
     * @param  list<array<string, mixed>>  $lines  Las filas de `journal_lines`, sin el evento.
     */
    public static function insert(array $event, array $lines): int
    {
        $estado = $event['status'] ?? 'posted';
        $posteo = [
            'status' => $estado,
            'posted_at' => $event['posted_at'] ?? ($estado === 'draft' ? null : now()),
            'posted_by' => $event['posted_by'] ?? null,
        ];

        $id = (int) DB::table('financial_events')->insertGetId([
            ...$event,
            'status' => 'draft',
            'posted_at' => null,
            'posted_by' => null,
        ]);

        if ($lines !== []) {
            DB::table('journal_lines')->insert(array_map(
                static fn (array $linea): array => [
                    'created_at' => now(),
                    ...$linea,
                    'financial_event_id' => $id,
                    'cash_box_id' => $linea['cash_box_id'] ?? $event['cash_box_id'] ?? null,
                ],
                $lines,
            ));
        }

        if ($estado !== 'draft') {
            // A «reversed» se llega desde «posted», nunca desde el borrador.
            DB::table('financial_events')->where('id', $id)->update([...$posteo, 'status' => 'posted']);
        }

        if ($estado === 'reversed') {
            DB::table('financial_events')->where('id', $id)->update(['status' => 'reversed']);
        }

        return $id;
    }
}
