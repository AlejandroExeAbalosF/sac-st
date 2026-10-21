<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El extracto acreditó el depósito de un cobro de la multa.
 *
 * Cierra el tránsito contra un crédito entero y sin otro uso: con la
 * política de la caja de Multas, el remanente de un crédito mayor no es
 * este depósito. Lo hace Banking (`ConfirmCashDepositCredit`); acá queda el
 * rastro en la multa.
 */
final class ConfirmFineDeposit
{
    public function __construct(
        private readonly ConfirmCashDepositCredit $confirmar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        CashToBankTransfer $traslado,
        BankTransaction $credito,
        string $idempotencyKey,
        ?int $actorId,
    ): CashToBankTransfer {
        return DB::transaction(function () use ($traslado, $credito, $idempotencyKey, $actorId): CashToBankTransfer {
            $yaAcreditado = CashToBankTransfer::query()->lockForUpdate()->findOrFail($traslado->id)->status
                === CashTransferStatus::BankConfirmed;

            $traslado = $this->confirmar->handle($traslado, $credito, $idempotencyKey, $actorId);

            // Un segundo envío: el rastro ya está.
            if (! $yaAcreditado) {
                $pago = $this->depositos->paymentOf($traslado);

                if ($pago !== null) {
                    $this->auditar->handle('multa.deposito-acreditado', $pago->fine, metadata: [
                        'amount' => $traslado->amount,
                        'traslado' => 'N.º '.$traslado->id,
                        'credito' => trim(($credito->transaction_date?->format('d/m/Y') ?? '').' '.($credito->description ?? '')),
                    ], actorId: $actorId);
                }
            }

            return $traslado;
        });
    }
}
