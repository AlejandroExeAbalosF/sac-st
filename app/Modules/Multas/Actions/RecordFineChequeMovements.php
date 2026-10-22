<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\RecordChequeRejectionDebit;
use App\Modules\Banking\Actions\RecordRejectedChequeCredit;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lo que el extracto trae de un cheque rechazado (DER físico de Multas §6.6):
 * el crédito que el banco hizo antes del rechazo, el débito con que lo
 * revierte, o los dos juntos si llegaron juntos. Cada hecho tiene su asiento
 * en Banking; acá queda el rastro en la multa. No toca la regularización de
 * la empresa.
 */
final class RecordFineChequeMovements
{
    public function __construct(
        private readonly RecordRejectedChequeCredit $credito,
        private readonly RecordChequeRejectionDebit $debito,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        ChequeRejection $rechazo,
        ?BankTransaction $credit,
        ?BankTransaction $debit,
        string $idempotencyKey,
        ?int $actorId,
    ): ChequeRejection {
        if (! config('multas.cheque_bank_followup_enabled', false)) {
            throw ValidationException::withMessages(['debitTransactionId' => 'El seguimiento bancario del rechazo queda pendiente para una próxima etapa.']);
        }

        return DB::transaction(function () use ($rechazo, $credit, $debit, $idempotencyKey, $actorId): ChequeRejection {
            $antes = ChequeRejection::query()->lockForUpdate()->findOrFail($rechazo->id)->bank_status;

            $despues = match (true) {
                $credit !== null => $this->credito->handle($rechazo, $credit, $debit, $idempotencyKey, $actorId),
                $debit !== null => $this->debito->handle($rechazo, $debit, $idempotencyKey, $actorId),
                default => throw ValidationException::withMessages(['debitTransactionId' => 'Elegí el movimiento del extracto.']),
            };

            $pago = $this->depositos->paymentOfRejection($despues);

            // Un segundo envío no avanza el seguimiento ni repite el rastro.
            if ($despues->bank_status !== $antes && $pago !== null) {
                $this->auditar->handle('multa.rechazo-banco-registrado', $pago->fine, metadata: array_filter([
                    'credito' => $credit?->transaction_date?->format('d/m/Y'),
                    'debito' => $debit?->transaction_date?->format('d/m/Y'),
                    'banco' => $despues->bank_status->label(),
                ]), actorId: $actorId);
            }

            return $despues;
        });
    }
}
