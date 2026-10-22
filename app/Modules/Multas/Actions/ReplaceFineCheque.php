<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\ReplaceRejectedCheque;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Llegó el cheque que reemplaza al rechazado (M03): otro cheque del mismo
 * importe, que vuelve a custodia y cierra la regularización de la empresa.
 * Sin recibo nuevo ni nueva recaudación. Lo registra Banking
 * (`ReplaceRejectedCheque`); acá queda el rastro en la multa.
 */
final class ReplaceFineCheque
{
    public function __construct(
        private readonly ReplaceRejectedCheque $reemplazar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}  $cheque
     *
     * @throws ValidationException
     */
    public function handle(ChequeRejection $rechazo, array $cheque, string $idempotencyKey, ?int $actorId): FundReceipt
    {
        return DB::transaction(function () use ($rechazo, $cheque, $idempotencyKey, $actorId): FundReceipt {
            $yaRecibido = FinancialEvent::query()->where('idempotency_key', $idempotencyKey)->exists();
            $reemplazo = $this->reemplazar->handle($rechazo, $cheque, $idempotencyKey, $actorId);
            $pago = $this->depositos->paymentOfRejection($rechazo);

            if (! $yaRecibido && $pago !== null) {
                $this->auditar->handle('multa.cheque-reemplazado', $pago->fine, metadata: [
                    'rechazado' => FundReceipt::query()->whereKey($rechazo->fund_receipt_id)->value('cheque_number'),
                    'cheque' => $reemplazo->cheque_number,
                    'amount' => $reemplazo->amount,
                ], actorId: $actorId);
            }

            return $reemplazo;
        });
    }
}
