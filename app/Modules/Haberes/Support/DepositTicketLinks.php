<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Shared\Enums\AttachmentSubject;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cuelga de cada comprobante de depósito, en dos consultas.
 *
 * La tarjeta de la cuota necesita dos datos que no viven en el ticket: la
 * foto que se le adjuntó y la recepción que nació del crédito con el que
 * se lo cruzó. Preguntarlos ticket por ticket es una consulta por fila en
 * un plan que puede tener sesenta.
 *
 * **Indexado por ticket y no por cuota.** Una cuota puede tener más de un
 * comprobante vigente —el depósito fraccionado del §2.1.9—, y agrupar por
 * cuota obligaba a elegir uno con un `MAX()` que no tenía por qué ser el
 * que la pantalla termina mostrando: la tarjeta llegó a exhibir un ticket
 * con la foto de otro.
 */
final class DepositTicketLinks
{
    /**
     * La recepción ya registrada del crédito que cruzó cada ticket.
     *
     * **Cruzar el ticket y registrar la recepción son dos actos**, y desde
     * la tarjeta se ven iguales: en los dos casos el papel figura
     * «encontrado en el extracto». Sin esto, la pantalla no puede
     * distinguir «falta registrar» de «falta asignar», y el cartel que
     * dice qué hacer termina diciendo lo que no es. Pasó.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, int> id de ticket => id de recepción
     */
    public function receipts(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $filas = DB::table('deposit_tickets')
            ->join(
                'bank_transaction_allocations',
                'bank_transaction_allocations.bank_transaction_id',
                '=',
                'deposit_tickets.bank_transaction_id',
            )
            ->join(
                'fund_receipts',
                'fund_receipts.financial_event_id',
                '=',
                'bank_transaction_allocations.financial_event_id',
            )
            ->whereIn('deposit_tickets.beneficiary_installment_id', $installmentIds)
            ->whereNotNull('deposit_tickets.bank_transaction_id')
            // Una reversión no registra nada: deshace.
            ->whereNull('bank_transaction_allocations.reversal_of_id')
            ->orderBy('fund_receipts.id')
            ->get([
                'deposit_tickets.id as ticket',
                'fund_receipts.id as recepcion',
            ]);

        $porTicket = [];

        foreach ($filas as $fila) {
            // Si el crédito se dividió, al menos el enlace queda estable:
            // el Action detecta la multiplicidad y deriva al flujo manual.
            $porTicket[(int) $fila->ticket] ??= (int) $fila->recepcion;
        }

        return $porTicket;
    }

    /**
     * El adjunto más nuevo de cada ticket.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, int> id de ticket => id de adjunto
     */
    public function attachments(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        return DB::table('deposit_tickets')
            ->join('attachments', function ($join): void {
                $join->on('attachments.subject_id', '=', 'deposit_tickets.id')
                    ->where('attachments.subject_type', AttachmentSubject::DepositTicket->value);
            })
            ->whereIn('deposit_tickets.beneficiary_installment_id', $installmentIds)
            ->groupBy('deposit_tickets.id')
            ->selectRaw('deposit_tickets.id AS ticket, MAX(attachments.id) AS adjunto')
            ->pluck('adjunto', 'ticket')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
