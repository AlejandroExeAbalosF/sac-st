<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\RejectCheque;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Se rechazó el cheque con que se pagó la multa (M03).
 *
 * El pago, su aplicación y su recibo quedan como están: la multa no vuelve a
 * deber, pero muestra aparte lo pendiente de regularizar. El rechazo es del
 * cheque vigente de la cadena —el original o su último reemplazo—; lo
 * registra Banking (`RejectCheque`) y acá queda el rastro en la multa.
 */
final class RejectFineCheque
{
    public function __construct(
        private readonly RejectCheque $rechazar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        FinePayment $pago,
        CarbonInterface $rejectedOn,
        string $reason,
        ?UploadedFile $evidence,
        ?BankTransaction $debit,
        string $idempotencyKey,
        ?int $actorId,
    ): ChequeRejection {
        if ($debit !== null && ! config('multas.cheque_bank_followup_enabled', false)) {
            throw ValidationException::withMessages(['debitTransactionId' => 'Por ahora registrá el rechazo con el aviso o constancia del banco; la imputación del débito queda pendiente.']);
        }

        return DB::transaction(function () use ($pago, $rejectedOn, $reason, $evidence, $debit, $idempotencyKey, $actorId): ChequeRejection {
            $yaRegistrado = FinancialEvent::query()->where('idempotency_key', $idempotencyKey)->exists();

            if ($pago->status !== FinePaymentStatus::Applied || $pago->medium !== FinePaymentMedium::Cheque) {
                throw ValidationException::withMessages(['chequeId' => 'Se rechaza el cheque de un cobro en caja ya aplicado.']);
            }

            $cheque = $this->depositos->receiptOf($pago)
                ?? throw ValidationException::withMessages(['chequeId' => 'Ese cobro no tiene su cheque en la caja.']);

            $rechazo = $this->rechazar->handle($cheque, $rejectedOn, $reason, $evidence, $debit, $idempotencyKey, $actorId);

            // Un segundo envío: el rastro ya está.
            if (! $yaRegistrado) {
                $this->auditar->handle('multa.cheque-rechazado', $pago->fine, metadata: [
                    'cheque' => $cheque->cheque_number,
                    'amount' => $cheque->amount,
                    'fecha' => $rejectedOn->format('d/m/Y'),
                    'motivo' => trim($reason),
                    'banco' => $rechazo->refresh()->bank_status->label(),
                ], actorId: $actorId);
            }

            return $rechazo;
        });
    }
}
