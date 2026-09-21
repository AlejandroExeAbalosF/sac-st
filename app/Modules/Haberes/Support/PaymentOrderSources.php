<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Banking\Enums\BankAllocationRole;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\Money\Decimal;

/**
 * De dónde salió la plata de una cuota, y en qué cuenta está ahora.
 *
 * Contesta las dos preguntas que la Orden de Pago necesita y que ninguna
 * tabla responde sola:
 *
 * 1. **La tabla de depósitos** que el formulario imprime, con el número de
 *    operación, la fecha y la cuenta corriente de cada ingreso.
 * 2. **En qué cuenta del organismo está el dinero hoy**, que es la casilla
 *    que el papel marca. El área confirmó que la marca la pone el sistema.
 *
 * Los dos caminos que llegan a una cuenta bancaria son distintos y por eso
 * se resuelven por separado:
 *
 * - **entró por banco**: el crédito del extracto que confirmó la
 *   recepción, imputado en `bank_transaction_allocations`;
 * - **entró por mostrador y se depositó**: el traslado de efectivo, con
 *   su número de operación de depósito.
 *
 * Vive suelto de `InstallmentFunding` a propósito: aquella responde
 * *cuánto* tiene la cuota, y ésta *dónde está y cómo llegó*. Mezclarlas
 * haría que cada listado que solo quiere un total arrastre tres joins.
 */
final class PaymentOrderSources
{
    /**
     * Por dónde va a salir el dinero de esta cuota.
     *
     * `Counter` no es una negativa definitiva: es lo que corresponde
     * mientras el efectivo siga en la caja. Si la contadora lo deposita,
     * la misma cuota pasa a `Transfer` sin que nadie cambie nada
     * (§2.4.6 y §2.4.7).
     */
    public function channel(BeneficiaryInstallment $installment, ?PaymentMedium $medium = null): PaymentChannel
    {
        $medium ??= app(InstallmentFunding::class)->medium($installment);

        if ($medium === null) {
            return PaymentChannel::Undetermined;
        }

        if ($medium === PaymentMedium::Bank) {
            return PaymentChannel::Transfer;
        }

        $traslado = $this->transferOf($installment);

        if ($traslado === null) {
            return PaymentChannel::Counter;
        }

        return $traslado->status === CashTransferStatus::BankConfirmed
            ? PaymentChannel::Transfer
            : PaymentChannel::Undetermined;
    }

    /**
     * La tabla de depósitos, un renglón por asignación vigente.
     *
     * Son varias cuando el depósito llegó fraccionado (§2.1.9). El recibo
     * de ingreso es uno solo y no se repite en cada fila: va una vez en
     * `payment_orders.income_receipt_id`.
     *
     * @return list<FundingSourceRow>
     */
    public function rows(BeneficiaryInstallment $installment): array
    {
        $asignaciones = FundingAllocation::query()
            ->withRemainingBalance()
            ->where('beneficiary_installment_id', $installment->id)
            ->with('fundReceipt')
            ->orderBy('id')
            ->get();

        $revertido = FundingAllocation::query()
            ->whereIn('reversal_of_id', $asignaciones->modelKeys())
            ->groupBy('reversal_of_id')
            ->selectRaw('reversal_of_id, SUM(amount) as total')
            ->pluck('total', 'reversal_of_id');

        $renglones = [];

        foreach ($asignaciones as $asignacion) {
            $vigente = Decimal::sub(
                $asignacion->amount,
                Decimal::scale((string) ($revertido[$asignacion->id] ?? '0')),
            );

            if (Decimal::equals($vigente, '0')) {
                continue;
            }

            $renglones[] = $asignacion->fundReceipt->medium === PaymentMedium::Bank
                ? $this->rowFromBank($asignacion, $vigente)
                : $this->rowFromCashDeposit($asignacion, $vigente);
        }

        return $renglones;
    }

    /**
     * La cuenta del organismo que el formulario marca con la cruz.
     *
     * Sale de los renglones y no de una consulta propia: es la cuenta
     * donde está el dinero que respalda esta Orden, y esa es exactamente
     * la información que la tabla de depósitos ya reunió.
     *
     * `null` cuando ningún renglón llegó a una cuenta —el dinero sigue en
     * la caja—, que es el caso en que la Orden todavía no corresponde.
     *
     * @param  list<FundingSourceRow>  $rows
     */
    public function organismAccountId(array $rows): ?int
    {
        foreach ($rows as $renglon) {
            if ($renglon->organismBankAccountId !== null) {
                return $renglon->organismBankAccountId;
            }
        }

        return null;
    }

    /**
     * El total de la tabla, que es el pie del cuadro impreso.
     *
     * @param  list<FundingSourceRow>  $rows
     * @return numeric-string
     */
    public function total(array $rows): string
    {
        $total = '0.00';

        foreach ($rows as $renglon) {
            $total = Decimal::add($total, $renglon->amount);
        }

        return $total;
    }

    /**
     * El traslado al banco del efectivo de esta cuota, si lo hubo.
     *
     * Se busca por las asignaciones de la cuota, que es lo que
     * `cash_to_bank_transfer_items` referencia: el traslado en sí no sabe
     * de quién era el dinero.
     */
    public function transferOf(BeneficiaryInstallment $installment): ?CashToBankTransfer
    {
        return $this->transfersFor([(int) $installment->id])[$installment->id] ?? null;
    }

    /**
     * Lo mismo para muchas cuotas, en una sola consulta.
     *
     * **Es la única escritura de este criterio**, y por eso el singular
     * delega acá en vez de repetirla. Vivía dos veces —la tarjeta de la
     * cuota la resolvía por su cuenta en `HaberController`— y las dos
     * copias no filtraban igual: una pedía asignaciones con saldo en pie y
     * la otra cualquiera que no fuera una reversión. Coincidían, pero de
     * casualidad: lo que las mantenía de acuerdo era una guarda que vive
     * en otro archivo, no algo que se leyera desde acá.
     *
     * ── Por qué `live()` y no el saldo ────────────────────────────────
     *
     * Porque lo que se pregunta es si **el efectivo salió del cajón**, y
     * eso lo decide el estado del traslado, no cuánto le queda asignado a
     * la cuota. La asignación es el puente entre las dos cosas, nada más.
     *
     * Hoy da igual: `UnallocateFunds` y `VoidCashCollection` no dejan
     * revertir una asignación con traslado vigente —«ese efectivo ya se
     * depositó en el banco»—, así que una asignación trasladada siempre
     * conserva su saldo. Si esa guarda alguna vez cediera, el criterio de
     * acá seguiría siendo el correcto: el depósito ocurrió, y ocultarlo
     * sería afirmar que la plata está en una caja de la que ya salió.
     *
     * El historial no usa esto y hace bien: `InstallmentHistoryController`
     * mira todas las imputaciones, incluidas las deshechas, porque contar
     * lo que se deshizo es justamente su trabajo.
     *
     * @param  list<int>  $installmentIds
     * @return array<int, CashToBankTransfer>
     */
    public function transfersFor(array $installmentIds): array
    {
        if ($installmentIds === []) {
            return [];
        }

        $items = CashToBankTransferItem::query()
            ->with(['transfer', 'fundingAllocation:id,beneficiary_installment_id'])
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled->value)
            ->whereIn(
                'funding_allocation_id',
                FundingAllocation::query()
                    ->live()
                    ->whereIn('beneficiary_installment_id', $installmentIds)
                    ->select('id'),
            )
            /*
             * El más nuevo primero. Una cuota con dos traslados vigentes ya
             * sería un problema anterior a esta consulta, pero si pasara, el
             * que describe dónde está el dinero es el último.
             */
            ->orderByDesc('cash_to_bank_transfer_id')
            ->get();

        $porCuota = [];

        foreach ($items as $item) {
            $porCuota[(int) $item->fundingAllocation->beneficiary_installment_id] ??= $item->transfer;
        }

        return $porCuota;
    }

    /**
     * El renglón de un ingreso que llegó por transferencia bancaria.
     *
     * El número de operación y la cuenta salen del movimiento del extracto
     * que confirmó la recepción, no del comprobante que trajo el
     * empleador: lo que el organismo tiene que poder verificar es lo que
     * el banco informó.
     *
     * @param  numeric-string  $importeVigente
     */
    private function rowFromBank(FundingAllocation $asignacion, string $importeVigente): FundingSourceRow
    {
        $imputacion = BankTransactionAllocation::query()
            ->where('financial_event_id', $asignacion->fundReceipt->financial_event_id)
            ->whereNull('reversal_of_id')
            ->first();

        $movimiento = $imputacion === null
            ? null
            : BankTransaction::query()->with('account')->find($imputacion->bank_transaction_id);

        return new FundingSourceRow(
            fundingAllocationId: $asignacion->id,
            amount: $importeVigente,
            organismBankAccountId: $movimiento?->bank_account_id,
            bankTransactionId: $movimiento?->id,
            operationNumber: $movimiento?->operation_id,
            operationDate: $movimiento->transaction_date ?? $asignacion->fundReceipt->received_date,
            bankAccountNumber: $movimiento?->account?->account_number,
            bankName: $movimiento?->account?->bank_name,
        );
    }

    /**
     * El renglón de un ingreso que entró por mostrador y se depositó.
     *
     * **El número que va impreso es el del depósito, no el de la
     * recepción.** Lo que el organismo verifica contra su cuenta es la
     * boleta con la que la contadora llevó el efectivo al banco; el hecho
     * de que antes ese dinero hubiera entrado en mano no deja rastro en el
     * extracto.
     *
     * @param  numeric-string  $importeVigente
     */
    private function rowFromCashDeposit(FundingAllocation $asignacion, string $importeVigente): FundingSourceRow
    {
        $item = CashToBankTransferItem::query()
            ->with('transfer.bankAccount')
            ->where('funding_allocation_id', $asignacion->id)
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled->value)
            ->first();

        $traslado = $item?->transfer;
        $movimiento = $this->movimientoQueAcredito($traslado);

        /*
         * El tipeado primero, y el del extracto cuando no lo hay.
         *
         * **No se pisa lo que una persona escribió.** El
         * `deposit_operation_number` lo lee la contadora de la boleta que
         * tiene en la mano; el `operation_id` es cómo el banco llama a ese
         * mismo movimiento en su archivo. Los dos son respuestas válidas a
         * «DEPÓSITO U OPERACIÓN N°» —el rótulo admite las dos— así que la
         * que se escribió a conciencia manda, y la del extracto tapa el
         * agujero.
         *
         * Sin esto la celda salía vacía teniendo el dato a un join de
         * distancia, que es lo que pasaba con toda recepción en efectivo
         * cuyo depósito nadie numeró a mano. La rama de las transferencias
         * ya usaba `operation_id`; ésta se había quedado atrás.
         */
        $numeroDeOperacion = $traslado?->deposit_operation_number;
        $numeroDeOperacion ??= $movimiento?->operation_id;

        return new FundingSourceRow(
            fundingAllocationId: $asignacion->id,
            amount: $importeVigente,
            /*
             * Solo si el banco lo acreditó. En tránsito el dinero salió de
             * la caja pero la cuenta todavía no lo tiene, y marcar esa
             * cuenta en el papel afirmaría un saldo que no existe.
             */
            organismBankAccountId: $traslado !== null && $traslado->status === CashTransferStatus::BankConfirmed
                ? $traslado->bank_account_id
                : null,
            bankTransactionId: $movimiento?->id,
            operationNumber: $numeroDeOperacion,
            operationDate: $traslado->deposit_date ?? $asignacion->fundReceipt->received_date,
            bankAccountNumber: $traslado?->bankAccount?->account_number,
            bankName: $traslado?->bankAccount?->bank_name,
        );
    }

    /**
     * El movimiento del extracto con el que se acreditó el depósito.
     *
     * El traslado no guarda la foránea al movimiento: guarda el evento
     * contable de la acreditación, y es la imputación de ese evento la que
     * sabe qué fila del extracto lo respalda. Un traslado todavía en
     * tránsito no tiene ninguno.
     */
    private function movimientoQueAcredito(?CashToBankTransfer $traslado): ?BankTransaction
    {
        if ($traslado?->credit_event_id === null) {
            return null;
        }

        $imputacion = BankTransactionAllocation::query()
            ->where('financial_event_id', $traslado->credit_event_id)
            ->where('allocation_role', BankAllocationRole::CashDepositConfirmation)
            ->whereNull('reversal_of_id')
            ->first();

        return $imputacion === null
            ? null
            : BankTransaction::query()->find($imputacion->bank_transaction_id);
    }
}
