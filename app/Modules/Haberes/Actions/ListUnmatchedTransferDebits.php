<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\TransferFees;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\TransferDebitMatch;
use App\Modules\Haberes\Support\TransferDebitPair;

/**
 * Los débitos sin conciliar de la cuenta que **no** son el pago de la cuota,
 * cada uno con el motivo.
 *
 * Es para cuando el buscador no encuentra el pago. Lo útil entonces no es
 * otra lista para elegir, sino entender por qué: el organismo transfirió
 * otro importe, el débito es anterior a la Orden, la comisión es de otra
 * operación o el movimiento ya lo tomó otra cuota. **Solo se consulta**: la
 * regla del pago es exacta y la vinculación rechazaría cualquiera de estos.
 *
 * El motivo sale de `TransferDebitPair`, la misma regla que aplican el
 * buscador y la vinculación, así que la pantalla no puede decir algo
 * distinto de lo que el sistema acepta.
 */
final class ListUnmatchedTransferDebits
{
    /** Cuánto antes de la Orden se mira: un mes alcanza para ver un error de fecha. */
    public const DAYS_BEFORE_ORDER = 30;

    /** Una consulta de apoyo, no el extracto entero. */
    public const LIMIT = 50;

    public function __construct(
        private readonly TransferDebitPair $par,
        private readonly TransferFees $comisiones,
    ) {}

    /**
     * @return list<TransferDebitMatch> los que no coinciden, con `problem` o `reviewReason`
     */
    public function handle(BeneficiaryInstallment $installment): array
    {
        $orden = PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($orden === null || $orden->organism_bank_account_id === null) {
            return [];
        }

        $egreso = Disbursement::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        $importe = $egreso->amount ?? $orden->amount;

        /*
         * Sin conciliar: pendientes o fuera del circuito. Lo que ya está
         * imputado a otra cosa no ayuda a encontrar este pago.
         */
        $movimientos = BankTransaction::query()
            ->where('bank_account_id', $orden->organism_bank_account_id)
            ->where('direction', TransactionDirection::Debit->value)
            ->whereIn('reconciliation_status', [ReconciliationStatus::Pending->value, ReconciliationStatus::Ignored->value])
            ->whereDate('transaction_date', '>=', $orden->order_date->copy()->subDays(self::DAYS_BEFORE_ORDER)->toDateString())
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(self::LIMIT * 2)
            ->get();

        $otros = [];

        foreach ($movimientos as $movimiento) {
            /*
             * Una comisión que acompaña a su transferencia ya aparece debajo
             * de ella. Sola, en cambio, se muestra: es un renglón que nadie
             * explica.
             */
            if ($this->comisiones->isFee($movimiento)) {
                if ($this->tieneTransferencia($movimiento, $movimientos)) {
                    continue;
                }

                $otros[] = new TransferDebitMatch(
                    $movimiento,
                    problem: 'Comisión bancaria sin una transferencia E-set de su operación: no acompaña a ningún pago.',
                );

                continue;
            }

            $par = $this->par->resolve($movimiento, $orden, $importe, $egreso?->id);

            /*
             * Un débito que no es transferencia E-set no toma comisión,
             * aunque haya una de su misma operación. Se dice, porque el par
             * puede sumar justo la Orden y confundir.
             */
            if ($par->problem !== null && $this->comisiones->feeCausalFor($movimiento) === null
                && $this->tieneComisionSuelta($movimiento, $movimientos)) {
                $par = new TransferDebitMatch(
                    $movimiento,
                    problem: $par->problem.' No es una transferencia E-set (3913), así que no lleva la comisión de su operación.',
                );
            }

            // Los que coinciden o quedan para revisión ya están en la lista de arriba.
            if ($par->problem === null) {
                continue;
            }

            $otros[] = $par;

            if (count($otros) === self::LIMIT) {
                break;
            }
        }

        return $otros;
    }

    /**
     * Si hay una comisión con la misma operación y fecha que este débito.
     *
     * @param  iterable<BankTransaction>  $movimientos
     */
    private function tieneComisionSuelta(BankTransaction $debito, iterable $movimientos): bool
    {
        foreach ($movimientos as $otro) {
            if ($otro->id !== $debito->id
                && $this->comisiones->isFee($otro)
                && trim((string) $otro->operation_id) !== ''
                && $otro->operation_id === $debito->operation_id
                && $otro->transaction_date?->toDateString() === $debito->transaction_date?->toDateString()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si la comisión tiene, en la misma consulta, la transferencia a la que
     * acompaña: misma operación, misma fecha y el causal del par.
     *
     * @param  iterable<BankTransaction>  $movimientos
     */
    private function tieneTransferencia(BankTransaction $comision, iterable $movimientos): bool
    {
        foreach ($movimientos as $otro) {
            if ($otro->id !== $comision->id
                && $this->comisiones->feeCausalFor($otro) === trim((string) $comision->causal_code)
                && trim((string) $otro->operation_id) !== ''
                && $otro->operation_id === $comision->operation_id
                && $otro->transaction_date?->toDateString() === $comision->transaction_date?->toDateString()) {
                return true;
            }
        }

        return false;
    }
}
