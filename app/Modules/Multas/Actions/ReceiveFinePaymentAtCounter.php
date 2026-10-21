<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\TalonarioSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * El dinero de un pago entra por el mostrador: se recibe en la caja de
 * Multas, se aplica a la multa y sale su recibo (M02).
 *
 * Lo usan el cobro en caja y el recurso del 20 % en efectivo, al registrarlo
 * o al resolver su diferencia: en los tres casos el pago ya está marcado como
 * aplicado y la multa bloqueada, en la transacción de quien llama.
 */
final class ReceiveFinePaymentAtCounter
{
    public function __construct(
        private readonly RegisterCashFundReceipt $recibir,
        private readonly ApplyFinePayment $aplicar,
        private readonly TalonarioSequence $talonarios,
    ) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    public function handle(
        FinePayment $pago,
        CarbonImmutable $fecha,
        ?array $cheque,
        ?string $talonario,
        ?int $signedById,
        ?int $actorId,
    ): FinePaymentReceipt {
        if (DB::transactionLevel() === 0 || $pago->status !== FinePaymentStatus::Applied || ! $pago->medium->isCounter()) {
            throw new RuntimeException('Se recibe en la caja un pago en efectivo o cheque ya aplicado, en la transacción que lo registra.');
        }

        $multa = $pago->fine()->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->valueOrFail('id');
        $moneda = Currency::from($multa->currency);

        if (CashBookOpening::for($caja, $moneda) === null) {
            throw ValidationException::withMessages([
                'amount' => $moneda === Currency::Ars
                    ? 'La caja de Multas todavía no se abrió: no puede cobrar.'
                    : 'La caja de Multas no opera en '.$multa->currency.'.',
            ]);
        }

        // Una hoja del talonario se usa una vez, aunque el recibo se haya anulado.
        $this->talonarios->assertFree(ReceiptType::Income, $caja, $talonario);

        $recepcion = $this->recibir->handle(
            amount: $pago->amount,
            idempotencyKey: $pago->idempotency_key.':recepcion',
            cashBoxId: $caja,
            receivedDate: $fecha,
            medium: $pago->medium === FinePaymentMedium::Cheque ? PaymentMedium::Cheque : PaymentMedium::Cash,
            depositorId: $multa->person_id,
            actorId: $actorId,
            notes: 'Multa '.$multa->caption,
            cheque: $cheque,
            currency: $moneda,
        );

        return $this->aplicar->handle($pago, $recepcion, $fecha, $talonario, $signedById, $actorId);
    }
}
