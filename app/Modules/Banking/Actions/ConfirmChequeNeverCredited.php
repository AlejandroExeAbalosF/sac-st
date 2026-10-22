<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Models\ChequeRejection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alguien comprobó en el extracto que el banco nunca acreditó el cheque
 * rechazado: devolvió el depósito sin acreditarlo.
 *
 * Cierra el seguimiento bancario como «no corresponde». No es automático:
 * que el sistema no tenga el crédito conciliado no prueba que no exista, así
 * que lo afirma una persona, con su nota, y solo con el extracto del día del
 * depósito ya importado. No lleva asiento: el tránsito ya salió al rechazar.
 */
final class ConfirmChequeNeverCredited
{
    public function __construct(private readonly ListCreditsForTransfer $creditos) {}

    /** @throws ValidationException */
    public function handle(ChequeRejection $rechazo, string $note, ?int $actorId): ChequeRejection
    {
        return DB::transaction(function () use ($rechazo, $note, $actorId): ChequeRejection {
            $rechazo = ChequeRejection::query()->with('transfer')->lockForUpdate()->findOrFail($rechazo->id);
            $nota = trim($note);

            // Un segundo envío.
            if ($rechazo->bank_status === ChequeRejectionBankStatus::NotApplicable && $rechazo->never_credited_at !== null) {
                return $rechazo;
            }

            if ($rechazo->bank_status !== ChequeRejectionBankStatus::Unverified || $rechazo->transfer === null) {
                throw ValidationException::withMessages([
                    'note' => 'Solo se confirma que el banco no lo acreditó mientras el rechazo está sin verificar en el extracto.',
                ]);
            }

            if (! $this->creditos->periodIsImported($rechazo->transfer)) {
                throw ValidationException::withMessages([
                    'note' => 'Primero importá el extracto del día del depósito: sin él no se sabe si el banco lo acreditó.',
                ]);
            }

            if (mb_strlen($nota) < 5) {
                throw ValidationException::withMessages(['note' => 'Anotá cómo se comprobó que el banco no lo acreditó.']);
            }

            $rechazo->forceFill([
                'bank_status' => ChequeRejectionBankStatus::NotApplicable,
                'never_credited_note' => $nota,
                'never_credited_by' => $actorId,
                'never_credited_at' => now(),
            ])->save();

            return $rechazo;
        });
    }
}
