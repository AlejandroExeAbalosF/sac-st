<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\ConfirmChequeNeverCredited;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alguien comprobó en el extracto que el banco nunca acreditó el cheque
 * rechazado. Lo afirma una persona, con su nota: lo registra Banking
 * (`ConfirmChequeNeverCredited`) y acá queda el rastro en la multa.
 */
final class ConfirmFineChequeNeverCredited
{
    public function __construct(
        private readonly ConfirmChequeNeverCredited $confirmar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(ChequeRejection $rechazo, string $note, ?int $actorId): ChequeRejection
    {
        if (! config('multas.cheque_bank_followup_enabled', false)) {
            throw ValidationException::withMessages(['note' => 'El seguimiento bancario del rechazo queda pendiente para una próxima etapa.']);
        }

        return DB::transaction(function () use ($rechazo, $note, $actorId): ChequeRejection {
            $antes = ChequeRejection::query()->lockForUpdate()->findOrFail($rechazo->id)->bank_status;
            $despues = $this->confirmar->handle($rechazo, $note, $actorId);
            $pago = $this->depositos->paymentOfRejection($despues);

            if ($antes === ChequeRejectionBankStatus::Unverified && $pago !== null) {
                $this->auditar->handle('multa.rechazo-nunca-acreditado', $pago->fine, metadata: [
                    'nota' => trim($note),
                ], actorId: $actorId);
            }

            return $despues;
        });
    }
}
