<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\TalonarioSequence;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un pago por el banco se verifica contra el crédito que lo trajo (M02, M08).
 *
 * Recién entonces toca la deuda: la recepción bancaria del crédito, su
 * aplicación a la multa y el recibo, en una transacción. El término ya quedó
 * fijado por la presentación; si nadie lo presentó y se identifica del
 * extracto, la presentación es la verificación misma.
 *
 * Un crédito por pago y por su importe entero, hasta que el área responda P14.
 * Una transferencia es de una sola multa (M06). La base repite las dos
 * reglas (`fine_payment_application_bank_credit`).
 */
final class VerifyFineBankPayment
{
    public function __construct(
        private readonly RegisterBankFundReceipt $recibir,
        private readonly ApplyFinePayment $aplicar,
        private readonly FineBankCredits $creditos,
        private readonly TalonarioSequence $talonarios,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        FinePayment $pago,
        int $bankTransactionId,
        ?int $actorId,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
    ): FinePayment {
        $talonario = trim((string) $talonarioNumber) ?: null;

        try {
            return $this->verify($pago, $bankTransactionId, $actorId, $talonario, $signedById);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->talonarios->translate(
                $e, ReceiptType::Income, (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id'), $talonario,
            ) ?? $e;
        }
    }

    /**
     * @throws ValidationException
     * @throws UniqueConstraintViolationException dos verificaciones simultáneas con la misma hoja del talonario
     */
    private function verify(FinePayment $pago, int $creditoId, ?int $actorId, ?string $talonario, ?int $signedById): FinePayment
    {
        return DB::transaction(function () use ($pago, $creditoId, $actorId, $talonario, $signedById): FinePayment {
            $multa = Fine::query()->whereKey($pago->fine_id)->lockForUpdate()->firstOrFail();
            $pago = FinePayment::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            // El mismo clic dos veces: el segundo encuentra el pago ya aplicado.
            if ($pago->status === FinePaymentStatus::Applied) {
                return $pago;
            }

            if ($pago->status !== FinePaymentStatus::Reported || $pago->medium->isCounter()) {
                throw ValidationException::withMessages(['bankTransactionId' => 'Ese pago no está esperando la verificación del banco.']);
            }

            $apertura = $this->creditos->book();

            if ($apertura === null) {
                throw ValidationException::withMessages([
                    'bankTransactionId' => 'La caja de Multas todavía no se abrió con su cuenta: no hay contra qué verificar.',
                ]);
            }

            $credito = BankTransaction::query()->whereKey($creditoId)->lockForUpdate()->first();

            if ($credito === null || ! $this->creditos->pending($apertura)->whereKey($creditoId)->exists()) {
                throw ValidationException::withMessages([
                    'bankTransactionId' => 'Ese crédito no está disponible: tiene que ser de la cuenta de Multas, desde la apertura de la caja, y nadie tiene que haberlo identificado.',
                ]);
            }

            if (! Decimal::equals($credito->amount, $pago->amount)) {
                throw ValidationException::withMessages([
                    'bankTransactionId' => sprintf(
                        'El crédito es de $ %s y el pago informado de $ %s. Un pago se verifica con un solo crédito, por su importe entero.',
                        Decimal::format($credito->amount),
                        Decimal::format($pago->amount),
                    ),
                ]);
            }

            $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->valueOrFail('id');
            $this->talonarios->assertFree(ReceiptType::Income, $caja, $talonario);

            $pago->forceFill(['status' => FinePaymentStatus::Applied])->save();

            $recepcion = $this->recibir->handle(
                transaction: $credito,
                amount: $pago->amount,
                idempotencyKey: $pago->idempotency_key.':recepcion',
                cashBoxId: $caja,
                depositorId: $pago->payer_person_id,
                actorId: $actorId,
                notes: 'Multa '.$multa->caption,
            );

            $comprobante = $this->aplicar->handle($pago, $recepcion, BusinessDate::today(), $talonario, $signedById, $actorId);

            $this->auditar->handle(
                'multa.pago-verificado',
                $multa,
                after: ['debt' => (string) FineBalance::query()->whereKey($multa->id)->value('debt')],
                metadata: array_filter([
                    'medio' => $pago->medium->label(),
                    'amount' => $pago->amount,
                    'recibo' => $comprobante->receipt()->value('formatted_number'),
                    'credito' => trim(($credito->transaction_date?->format('d/m/Y') ?? '').' '.($credito->operation_id ?? $credito->description ?? '')),
                ]),
                actorId: $actorId,
            );

            return $pago->refresh();
        });
    }
}
