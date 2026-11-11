<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Banking\Support\TransferFees;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Support\Money\Decimal;

/**
 * Si un débito del extracto —con su comisión— es el pago de una Orden.
 *
 * **La única definición de la regla.** La usan el buscador, la vinculación
 * y la validación, así que el sistema nunca ofrece un candidato que después
 * rechaza ni valida algo distinto de lo que vinculó. La base repite la
 * parte estructural al confirmar (`disbursements_transfer_debits_match`).
 *
 * El organismo paga con una transferencia, y si fue por MacrOnline E-set
 * el banco debita además una comisión en un renglón aparte, con la misma
 * fecha y referencia. **La comisión la absorbe el beneficiario**: la Orden
 * se cancela con los dos débitos, y el trabajador recibe la transferencia.
 *
 * ```text
 *   Orden                    891.962,00
 *   Transferencia (3913)     891.841,00   ← lo que recibió el beneficiario
 *   Comisión      (3914)         121,00   ← lo que cobró el banco
 * ```
 *
 * La regla, en orden:
 *
 * 1. La transferencia es un débito de la cuenta de la Orden, no anterior a
 *    ella —con un día de margen— y no es a su vez una comisión.
 * 2. Su comisión es la de la misma cuenta, fecha y operación. Ninguna: el
 *    pago es la transferencia sola. Una: el par. Dos o más: no se puede
 *    saber cuál, y queda **para revisión**.
 * 3. El par suma **exacto** el importe del egreso, que es el que autorizó
 *    la Orden. Una transferencia que por sí sola iguala la Orden pero trajo
 *    comisión no coincide: el banco habría cobrado de más.
 * 4. Cada movimiento está **entero y libre**: sin imputaciones y sin otro
 *    egreso vivo que lo reserve. Sumar saldos libres aceptaría un débito
 *    usado en parte.
 */
final class TransferDebitPair
{
    /**
     * El organismo no transfiere antes de que se le pida. El único margen
     * es un día, por si la Orden se fechó distinto de cuando salió.
     */
    public const DAYS_BEFORE_ORDER = 1;

    public function __construct(
        private readonly TransferFees $comisiones,
        private readonly AllocatableAmount $disponible,
    ) {}

    /**
     * @param  numeric-string  $amount  el importe del egreso
     * @param  int|null  $disbursementId  el egreso que se está conciliando,
     *                                    que no cuenta como «otro» que reserva
     * @param  bool  $lock  bloquear los movimientos: solo dentro de una transacción
     */
    public function resolve(
        BankTransaction $transfer,
        PaymentOrder $order,
        string $amount,
        ?int $disbursementId = null,
        bool $lock = false,
    ): TransferDebitMatch {
        $problema = $this->problemaDeLaTransferencia($transfer, $order);

        if ($problema !== null) {
            return new TransferDebitMatch($transfer, problem: $problema);
        }

        $comisiones = $this->comisiones->siblingsOf($transfer);
        $neto = Decimal::abs($transfer->amount);

        if ($comisiones->count() > 1) {
            $alguna = $comisiones->contains(
                fn (BankTransaction $c): bool => Decimal::equals(Decimal::add($neto, Decimal::abs($c->amount)), $amount),
            );

            return new TransferDebitMatch(
                $transfer,
                problem: $alguna ? null : sprintf(
                    'Ninguna de las comisiones de esa operación cierra con el egreso por $%s.',
                    Decimal::format($amount),
                ),
                reviewReason: sprintf(
                    'La operación %s trae %d comisiones el mismo día: no se puede saber cuál es la de esta '
                    .'transferencia. Hay que revisarlo contra el extracto.',
                    $transfer->operation_id,
                    $comisiones->count(),
                ),
            );
        }

        /** @var BankTransaction|null $comision */
        $comision = $comisiones->first();
        $par = new TransferDebitMatch($transfer, $comision);

        if (! Decimal::equals($par->total(), $amount)) {
            return new TransferDebitMatch($transfer, $comision, problem: $this->noCierra($neto, $par->feeAmount(), $amount));
        }

        if ($comision !== null && $comision->reconciliation_status === ReconciliationStatus::Ignored) {
            return new TransferDebitMatch($transfer, $comision, reviewReason: sprintf(
                'La comisión de $%s de esta operación está marcada fuera del circuito, así que el pago no '
                .'puede conciliarse completo.',
                Decimal::format(Decimal::abs($comision->amount)),
            ));
        }

        $problema = $this->problemaDeUso($transfer, 'Ese débito', $disbursementId, $lock)
            ?? ($comision === null ? null : $this->problemaDeUso($comision, 'La comisión de esa operación', $disbursementId, $lock));

        return $problema === null ? $par : new TransferDebitMatch($transfer, $comision, problem: $problema);
    }

    private function problemaDeLaTransferencia(BankTransaction $transfer, PaymentOrder $order): ?string
    {
        if ($transfer->bank_account_id !== $order->organism_bank_account_id) {
            return 'Ese movimiento es de otra cuenta: la Orden pide transferir desde la cuenta del organismo '
                .'que tiene el dinero.';
        }

        if ($transfer->direction !== TransactionDirection::Debit) {
            return 'Un pago sale de la cuenta: ese movimiento es un crédito.';
        }

        if ($this->comisiones->isFee($transfer)) {
            return 'Ese movimiento es una comisión bancaria: hay que elegir la transferencia a la que acompaña.';
        }

        if ($transfer->reconciliation_status === ReconciliationStatus::Ignored) {
            return 'Ese movimiento está marcado fuera del circuito.';
        }

        $fecha = $transfer->transaction_date;

        if ($fecha !== null && $fecha->lt($order->order_date->copy()->subDays(self::DAYS_BEFORE_ORDER))) {
            return 'Ese débito es anterior a la Orden: no puede ser su transferencia.';
        }

        return null;
    }

    /**
     * @param  numeric-string  $neto
     * @param  numeric-string|null  $comision
     * @param  numeric-string  $amount
     */
    private function noCierra(string $neto, ?string $comision, string $amount): string
    {
        if ($comision === null) {
            return sprintf(
                'Ese débito es de $%s y el egreso es por $%s.',
                Decimal::format($neto),
                Decimal::format($amount),
            );
        }

        return sprintf(
            'La transferencia de $%s y su comisión de $%s suman $%s, y el egreso es por $%s.',
            Decimal::format($neto),
            Decimal::format($comision),
            Decimal::format(Decimal::add($neto, $comision)),
            Decimal::format($amount),
        );
    }

    /** Entero, sin imputaciones y sin otro egreso vivo que lo reserve. */
    private function problemaDeUso(
        BankTransaction $movimiento,
        string $quien,
        ?int $disbursementId,
        bool $lock,
    ): ?string {
        $libre = $lock ? $this->disponible->forUpdate($movimiento) : $this->disponible->for($movimiento);

        if (! Decimal::equals($libre, Decimal::abs($movimiento->amount))) {
            return "{$quien} ya tiene una parte imputada: un pago se concilia con débitos enteros.";
        }

        $reservado = Disbursement::query()
            ->live()
            ->when($disbursementId !== null, fn ($q) => $q->whereKeyNot($disbursementId))
            ->where(fn ($q) => $q
                ->where('bank_transaction_id', $movimiento->id)
                ->orWhere('fee_bank_transaction_id', $movimiento->id))
            ->exists();

        return $reservado ? "{$quien} ya está en el egreso de otra cuota." : null;
    }
}
