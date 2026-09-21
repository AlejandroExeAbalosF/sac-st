<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;

/**
 * Todos los créditos a los que un traslado **podría** corresponder.
 *
 * Es la salida al callejón, la misma que `ListAvailableCredits` da para
 * los comprobantes del expediente: `FindCashDepositCandidates` aplica
 * filtros duros —importe exacto, ventana de días— y cuando no encuentra
 * nada la pantalla queda sin nada que ofrecer.
 *
 * **Pero «no hay candidatos» casi nunca significa que el crédito no
 * exista.** Acá el importe no puede estar mal tipeado —lo pone la cuota,
 * no el operador— así que lo que suele fallar es otra cosa: la fecha del
 * depósito se cargó equivocada, el banco acreditó más tarde que la
 * ventana, o el extracto de esos días todavía no se importó. Mostrando
 * los créditos disponibles el operador ve el movimiento real y lo
 * reconoce.
 *
 * **El orden es la cercanía**: primero la diferencia de importe y solo
 * para desempatar la de días, por lo mismo que en el otro circuito —el
 * importe es lo más discriminante, y sumar pesos con días exigiría elegir
 * cuántos pesos vale un día, constante que no sostiene ningún dato—.
 */
final class ListCreditsForTransfer
{
    /**
     * Cuántos se ofrecen.
     *
     * No pagina, a diferencia del listado de los tickets: aquel vive en
     * una pantalla propia y este en un diálogo. Con el orden por cercanía,
     * lo que no está en los primeros veinticinco no lo está por cercanía
     * sino por casualidad.
     */
    private const LIMITE = 25;

    /** @return list<BankTransaction> */
    public function handle(CashToBankTransfer $transfer): array
    {
        /** @var list<BankTransaction> $creditos */
        $creditos = BankTransaction::query()
            ->where('bank_account_id', $transfer->bank_account_id)
            ->where('direction', TransactionDirection::Credit->value)
            /*
             * Lo ignorado quedó fuera del circuito por decisión de una
             * persona —una comisión, un movimiento entre cuentas— y volver
             * a ofrecerlo sería deshacer ese trabajo en silencio.
             */
            ->where('reconciliation_status', '!=', ReconciliationStatus::Ignored->value)
            /*
             * Y lo que ya se imputó por completo no puede respaldar otra
             * cosa: el trigger de §9.3 lo rechazaría igual, así que
             * ofrecerlo sería prometer algo que va a fallar.
             */
            ->whereRaw(
                'ABS(amount) > (
                    SELECT COALESCE(SUM(CASE WHEN reversal_of_id IS NULL THEN amount ELSE -amount END), 0)
                    FROM bank_transaction_allocations
                    WHERE bank_transaction_id = bank_transactions.id
                )'
            )
            ->orderByRaw('ABS(ABS(amount) - ?::numeric)', [$transfer->amount])
            ->orderByRaw('ABS(transaction_date - ?::date)', [$transfer->deposit_date->toDateString()])
            ->orderByDesc('id')
            ->limit(self::LIMITE)
            ->get()
            ->all();

        return $creditos;
    }

    /**
     * Si el extracto de la fecha del depósito ya se importó.
     *
     * Convierte «puede que falte importar el extracto» en una afirmación
     * o su contraria. Sin esto la pantalla ofrece una excusa genérica que
     * el operador no puede verificar sin salir a mirar.
     */
    public function periodIsImported(CashToBankTransfer $transfer): bool
    {
        return BankStatementImport::query()
            ->where('bank_account_id', $transfer->bank_account_id)
            ->whereNotNull('period_from')
            ->whereNotNull('period_to')
            ->whereDate('period_from', '<=', $transfer->deposit_date)
            ->whereDate('period_to', '>=', $transfer->deposit_date)
            ->exists();
    }
}
