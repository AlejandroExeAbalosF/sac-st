<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Banking\Support\CashDepositCandidate;
use App\Support\Money\Decimal;

/**
 * Busca en el extracto el crédito de un depósito nuestro.
 *
 * Es el espejo de `FindTicketCandidates`, con una diferencia de fondo: ahí
 * el depósito lo hizo un tercero y hay que averiguar de quién viene; acá
 * lo hicimos nosotros y sabemos exactamente qué buscar. Por eso las
 * señales son menos y el filtro alcanza casi siempre.
 *
 * **No vincula: propone.** Confirmar es de una persona, porque un crédito
 * mal atribuido cierra una ventana de tránsito que en realidad sigue
 * abierta —y deja un depósito perdido que nadie va a reclamar—.
 */
final class FindCashDepositCandidates
{
    /**
     * La ventana es asimétrica y esa asimetría no es un detalle: **el
     * banco no puede acreditar antes de que uno deposite**. Hacia adelante
     * hay margen —depósito después del cierre, fin de semana, feriado
     * largo—; hacia atrás solo un día, y únicamente por si quien cargó el
     * ticket tipeó mal la fecha.
     */
    private const DAYS_FORWARD = 5;

    private const DAYS_BACKWARD = 1;

    public function __construct(private readonly AllocatableAmount $disponible) {}

    /** @return list<CashDepositCandidate> */
    public function handle(CashToBankTransfer $transfer): array
    {
        $desde = $transfer->deposit_date->copy()->subDays(self::DAYS_BACKWARD);
        $hasta = $transfer->deposit_date->copy()->addDays(self::DAYS_FORWARD);

        /*
         * Importe exacto, sin tolerancia: en el extracto real las
         * comisiones son movimientos aparte, así que el crédito de un
         * depósito entra completo. Si difiere, es otro depósito.
         */
        $movimientos = BankTransaction::query()
            ->where('bank_account_id', $transfer->bank_account_id)
            ->where('direction', TransactionDirection::Credit->value)
            ->whereBetween('transaction_date', [$desde->toDateString(), $hasta->toDateString()])
            ->whereRaw('amount = ?::numeric', [$transfer->amount])
            ->orderBy('transaction_date')
            ->get();

        $candidatos = $movimientos
            /*
             * Lo que ya está imputado no se ofrece. Un crédito consumido
             * por una recepción de fondos no puede ser además la
             * acreditación de un traslado: sería la misma plata contada
             * dos veces.
             */
            ->filter(fn (BankTransaction $m): bool => ! Decimal::equals($this->disponible->for($m), '0'))
            ->map(fn (BankTransaction $m): CashDepositCandidate => $this->describe($transfer, $m))
            ->sortBy(fn (CashDepositCandidate $c): int => $c->rank())
            ->values()
            ->all();

        /** @var list<CashDepositCandidate> $candidatos */
        return $candidatos;
    }

    private function describe(CashToBankTransfer $transfer, BankTransaction $movimiento): CashDepositCandidate
    {
        $fecha = $movimiento->transaction_date;
        $dias = $fecha === null ? 0 : (int) $transfer->deposit_date->diffInDays($fecha, false);

        /*
         * El número de operación del ticket se compara, pero no manda: el
         * área confirmó que no es fiable. Cuando coincide es la señal más
         * fuerte que hay; cuando no, no significa nada.
         */
        $operacion = $transfer->deposit_operation_number !== null
            && $movimiento->operation_id !== null
            && $this->soloDigitos($transfer->deposit_operation_number) === $this->soloDigitos($movimiento->operation_id);

        $signals = ['importe' => 'exacto'];

        $signals['fecha'] = match (true) {
            $dias === 0 => 'el mismo día del depósito',
            $dias === 1 => 'el día siguiente',
            $dias > 1 => "{$dias} días después",
            default => 'un día antes del depósito',
        };

        if ($operacion) {
            $signals['operación'] = 'coincide con el ticket';
        }

        return new CashDepositCandidate(
            transaction: $movimiento,
            dayGap: $dias,
            operationMatches: $operacion,
            signals: $signals,
        );
    }

    private function soloDigitos(string $valor): string
    {
        return preg_replace('/\D+/', '', $valor) ?? '';
    }
}
