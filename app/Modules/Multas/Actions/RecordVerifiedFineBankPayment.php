<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un crédito del extracto que se identifica como el pago de una multa sin que
 * nadie lo haya informado antes (M08).
 *
 * Es el caso habitual: el área lee el extracto, reconoce la transferencia y
 * emite el recibo. Se informa y se verifica en el mismo acto, por el importe
 * del crédito; la presentación es este momento, así que una identificación
 * posterior al último día del mes de la multa queda fuera de término.
 */
final class RecordVerifiedFineBankPayment
{
    public function __construct(
        private readonly ReportFineBankPayment $informar,
        private readonly VerifyFineBankPayment $verificar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        Fine $multa,
        FinePaymentMedium $medio,
        int $bankTransactionId,
        string $idempotencyKey,
        ?int $actorId,
        ?string $judicialReference = null,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
    ): FinePayment {
        $importe = BankTransaction::query()->whereKey($bankTransactionId)->value('amount');

        if ($importe === null) {
            throw ValidationException::withMessages(['bankTransactionId' => 'Elegí el crédito del extracto que trajo el pago.']);
        }

        return DB::transaction(function () use (
            $multa, $medio, $bankTransactionId, $idempotencyKey, $actorId, $judicialReference, $talonarioNumber, $signedById, $importe
        ): FinePayment {
            $pago = $this->informar->handle(
                multa: $multa,
                medio: $medio,
                amount: (string) $importe,
                idempotencyKey: $idempotencyKey,
                actorId: $actorId,
                judicialReference: $judicialReference,
            );

            return $this->verificar->handle($pago, $bankTransactionId, $actorId, $talonarioNumber, $signedById);
        });
    }
}
