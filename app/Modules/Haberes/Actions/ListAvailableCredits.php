<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Models\DepositTicket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Todos los créditos a los que este ticket **podría** corresponder.
 *
 * Es la salida al callejón: `FindTicketCandidates` aplica filtros duros
 * —importe exacto, ventana de días— y cuando no encuentra nada la pantalla
 * queda sin nada que ofrecer. Pero «no hay candidatos» casi nunca
 * significa que el depósito no exista; significa que **algo de lo que se
 * transcribió del papel no coincide**, y lo más frecuente es un dígito.
 *
 * Mostrando los créditos disponibles el operador ve el movimiento real,
 * entiende que el ticket decía `$682.500` donde el banco dice `$682.000`,
 * y lo corrige. Por eso esta lista y la edición del ticket son la misma
 * solución vista desde dos lados.
 *
 * **El orden por defecto es la cercanía**, no la fecha ni el importe: con
 * un dígito mal tipeado, el movimiento correcto difiere en pocos pesos y
 * pocos días, y así aparece primero.
 */
final class ListAvailableCredits
{
    public const POR_PAGINA = 25;

    /** @var list<string> */
    public const ORDENES = ['cercania', 'fecha', 'importe'];

    /**
     * @return LengthAwarePaginator<int, BankTransaction>
     */
    public function handle(DepositTicket $ticket, string $orden = 'cercania'): LengthAwarePaginator
    {
        $consulta = BankTransaction::query()
            ->where('bank_account_id', $ticket->bank_account_id)
            ->where('direction', TransactionDirection::Credit->value)
            /*
             * Lo ignorado quedó fuera del circuito por decisión de una
             * persona —una comisión, un movimiento entre cuentas—, y
             * volver a ofrecerlo sería deshacer ese trabajo en silencio.
             */
            ->where('reconciliation_status', '!=', ReconciliationStatus::Ignored->value)
            // Lo que ya tiene otro ticket tiene dueño.
            ->whereNotExists(function ($query) use ($ticket): void {
                $query->selectRaw('1')
                    ->from('deposit_tickets')
                    ->whereColumn('deposit_tickets.bank_transaction_id', 'bank_transactions.id')
                    ->where('deposit_tickets.id', '!=', $ticket->id);
            })
            /*
             * Y lo que ya se imputó por completo tampoco puede respaldar
             * otra recepción: el trigger de §9.3 lo rechazaría igual, así
             * que ofrecerlo sería prometer algo que va a fallar.
             */
            ->whereRaw(
                'ABS(amount) > (
                    SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0)
                    FROM bank_transaction_allocations
                    WHERE bank_transaction_id = bank_transactions.id
                )'
            );

        return $this->ordenar($consulta, $ticket, $orden)
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * @param  Builder<BankTransaction>  $consulta
     * @return Builder<BankTransaction>
     */
    private function ordenar(
        Builder $consulta,
        DepositTicket $ticket,
        string $orden,
    ): Builder {
        return match ($orden) {
            'fecha' => $consulta->orderByDesc('transaction_date')->orderByDesc('id'),
            'importe' => $consulta->orderByDesc('amount')->orderByDesc('id'),
            /*
             * Cercanía: primero la diferencia de importe, y solo para
             * desempatar la de días.
             *
             * En ese orden y no al revés porque el importe es el dato más
             * discriminante —hay un solo crédito por $682.000 en el mes,
             * pero decenas el mismo día—. No se mezclan en un puntaje
             * único: sumar pesos con días exigiría elegir cuántos pesos
             * vale un día, y esa constante no la sostiene ningún dato.
             */
            default => $consulta
                ->orderByRaw('ABS(ABS(amount) - ?::numeric)', [$ticket->amount])
                ->orderByRaw('ABS(transaction_date - ?::date)', [$ticket->deposited_at->toDateString()])
                ->orderByDesc('id'),
        };
    }
}
