<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las recepciones de los cobros de Haberes ya anulados, marcadas como revertidas.
 *
 * Anular un cobro asentaba la reversión sin marcar la recepción, que seguía
 * ofreciendo un dinero que ya no estaba. La que se volvió a imputar queda como
 * está: marcarla ocultaría plata imputada sin respaldo, y eso se resuelve a mano.
 *
 * No tiene vuelta atrás: una reversión no se deshace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            UPDATE fund_receipts AS recepcion
            SET reversal_event_id = reversion.id,
                reversed_at = reversion.created_at,
                reversed_by = reversion.created_by,
                reversal_reason = reversion.reversal_reason,
                reversal_reason_code = 'other'
            FROM financial_events AS reversion, cash_boxes AS caja
            WHERE reversion.reversal_of_id = recepcion.financial_event_id
              AND recepcion.reversal_event_id IS NULL
              AND caja.id = recepcion.cash_box_id
              AND caja.code = 'haberes'
              AND COALESCE((
                  SELECT SUM(CASE WHEN imputacion.allocation_kind = 'reversal' THEN -imputacion.amount ELSE imputacion.amount END)
                  FROM funding_allocations AS imputacion
                  WHERE imputacion.fund_receipt_id = recepcion.id
              ), 0) = 0;
        SQL);
    }

    public function down(): void {}
};
