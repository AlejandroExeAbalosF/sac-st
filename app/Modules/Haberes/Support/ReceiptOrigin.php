<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Ledger\Models\FundReceipt;
use Illuminate\Support\Facades\DB;

/**
 * De dónde vino una recepción.
 *
 * La cadena que la tanda anterior dejó armada se recorre entera:
 *
 * ```text
 *   recepción → evento → imputación → movimiento → ticket → expediente
 * ```
 *
 * Es la que permite que la pantalla proponga las cuotas correctas sin que
 * el operador tenga que buscar el expediente: el ticket llegó dentro de
 * él, así que el sistema ya sabe cuál es.
 *
 * **Ninguna recepción está obligada a tener origen.** Una en efectivo no
 * tiene movimiento bancario, y una bancaria puede no tener ticket —el área
 * confirmó que el comprobante se pide pero no se exige—. En ambos casos
 * la respuesta es `null` y la pantalla ofrece buscar a mano.
 */
final class ReceiptOrigin
{
    /**
     * @return array{
     *     bankTransactionId: int,
     *     bankTransactionDescription: string|null,
     *     bankAccountLabel: string|null,
     *     expedienteId: int|null,
     *     expedienteNumber: string|null,
     *     employerName: string|null,
     * }|null
     */
    public function for(FundReceipt $receipt): ?array
    {
        /** @var object{bank_transaction_id: int, description: string|null, label: string|null, expediente_id: int|null, display_number: string|null, employer_name: string|null}|null $fila */
        $fila = DB::table('bank_transaction_allocations as bta')
            ->join('bank_transactions as bt', 'bt.id', '=', 'bta.bank_transaction_id')
            ->leftJoin('bank_accounts as ba', 'ba.id', '=', 'bt.bank_account_id')
            ->leftJoin('deposit_tickets as dt', 'dt.bank_transaction_id', '=', 'bt.id')
            ->leftJoin('expedientes as e', 'e.id', '=', 'dt.expediente_id')
            ->leftJoin('people as p', 'p.id', '=', 'e.employer_id')
            ->where('bta.financial_event_id', $receipt->financial_event_id)
            ->whereNull('bta.reversal_of_id')
            ->select([
                'bta.bank_transaction_id',
                'bt.description',
                'ba.label',
                'e.id as expediente_id',
                'e.display_number',
                'p.name as employer_name',
            ])
            ->first();

        if ($fila === null) {
            return null;
        }

        return [
            'bankTransactionId' => (int) $fila->bank_transaction_id,
            'bankTransactionDescription' => $fila->description,
            'bankAccountLabel' => $fila->label,
            'expedienteId' => $fila->expediente_id === null ? null : (int) $fila->expediente_id,
            'expedienteNumber' => $fila->display_number,
            'employerName' => $fila->employer_name,
        ];
    }

    /**
     * El expediente de cada recepción, en una sola consulta.
     *
     * El listado muestra decenas de recepciones y preguntarle el origen a
     * cada una sería el problema de siempre: una consulta por fila.
     *
     * @param  list<int>  $receiptIds
     * @return array<int, array{id: int, number: string}>
     */
    public function expedientesFor(array $receiptIds): array
    {
        if ($receiptIds === []) {
            return [];
        }

        $filas = DB::table('fund_receipts as fr')
            ->join('bank_transaction_allocations as bta', 'bta.financial_event_id', '=', 'fr.financial_event_id')
            ->join('deposit_tickets as dt', 'dt.bank_transaction_id', '=', 'bta.bank_transaction_id')
            ->join('expedientes as e', 'e.id', '=', 'dt.expediente_id')
            ->whereIn('fr.id', $receiptIds)
            ->whereNull('bta.reversal_of_id')
            ->select(['fr.id as receipt_id', 'e.id as expediente_id', 'e.display_number'])
            ->get();

        $porRecepcion = [];

        foreach ($filas as $fila) {
            /** @var object{receipt_id: int, expediente_id: int, display_number: string} $fila */
            $porRecepcion[(int) $fila->receipt_id] = [
                'id' => (int) $fila->expediente_id,
                'number' => $fila->display_number,
            ];
        }

        return $porRecepcion;
    }
}
