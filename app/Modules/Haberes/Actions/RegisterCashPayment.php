<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El pago por mostrador, de punta a punta.
 *
 * **Recibir y asignar son un solo acto acá, y en el banco son dos.** La
 * diferencia no es de comodidad: en el circuito bancario el dinero llega
 * primero y recién después alguien averigua de quién es, y por eso existe
 * la cola de `UNASSIGNED_FUNDS`. En el mostrador el empleador está
 * enfrente diciendo para qué cuota paga; separar los dos pasos obligaría a
 * mandar a la cola algo que nunca estuvo sin identificar.
 *
 * Sigue siendo un asiento y una asignación —el libro no cambia— pero el
 * operador lo hace de una vez, que es lo que ocurre en el mostrador.
 */
final class RegisterCashPayment
{
    public function __construct(
        private readonly RegisterCashFundReceipt $recibir,
        private readonly AllocateFundsToInstallment $asignar,
        private readonly InstallmentFunding $financiacion,
    ) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $amount,
        string $idempotencyKey,
        int $cashBoxId,
        CarbonInterface $receivedDate,
        PaymentMedium $medium = PaymentMedium::Cash,
        ?int $depositorId = null,
        ?int $actorId = null,
        ?string $notes = null,
        ?array $cheque = null,
    ): FundingAllocation {
        $importe = Decimal::scale($amount);

        $this->assertFits($installment, $importe);

        return DB::transaction(function () use (
            $installment, $importe, $idempotencyKey, $cashBoxId, $receivedDate,
            $medium, $depositorId, $actorId, $notes, $cheque
        ): FundingAllocation {
            $recepcion = $this->recibir->handle(
                amount: $importe,
                idempotencyKey: $idempotencyKey,
                cashBoxId: $cashBoxId,
                receivedDate: $receivedDate,
                medium: $medium,
                depositorId: $depositorId,
                actorId: $actorId,
                notes: $notes,
                cheque: $cheque,
            );

            /*
             * La clave de la asignación deriva de la del ingreso: son el
             * mismo acto, así que un segundo envío tiene que ser inocuo
             * en los dos pasos, no solo en el primero.
             */
            return $this->asignar->handle(
                receipt: $recepcion,
                installment: $installment,
                amount: $importe,
                idempotencyKey: $idempotencyKey.':asignacion',
                actorId: $actorId,
                notes: $notes,
            );
        });
    }

    /**
     * @throws ValidationException
     */
    private function assertFits(BeneficiaryInstallment $installment, string $amount): void
    {
        if (Decimal::isNegative($amount) || Decimal::equals($amount, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'El importe recibido tiene que ser mayor que cero.',
            ]);
        }

        /*
         * Se comprueba antes de asentar nada. El Action de asignación lo
         * verifica igual y la base también, pero fallar acá evita crear
         * una recepción que después no se va a poder imputar y quedaría
         * dando vueltas en la cola sin dueño.
         */
        $falta = $this->financiacion->remaining($installment);

        if (Decimal::isNegative(Decimal::sub($falta, $amount))) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'A la cuota le faltan $ %s y se están recibiendo $ %s.',
                    Decimal::format($falta),
                    Decimal::format($amount),
                ),
            ]);
        }
    }
}
