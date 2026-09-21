<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\PersonBankAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marca una cuenta como inutilizable, con su motivo.
 *
 * La contracara de `VerifyPersonBankAccount`, y va aparte porque es otro
 * acto: verificar es dar por buena una cuenta después de mirarla; rechazar
 * es dejar constancia de por qué ese número no sirve.
 *
 * **El motivo es obligatorio y esa es la razón de ser de la operación.**
 * Una cuenta rechazada no vuelve a usarse —lo impide `PersonController` al
 * cargarla y lo vuelve a impedir la emisión de la Orden—, así que sin el
 * motivo quedaría un número tachado sin explicación. Lo que hay que poder
 * leer el año que viene es por qué a esa persona se le pidió otro CBU.
 *
 * El caso real del área: el trabajador informó un CVU de billetera
 * virtual, el organismo no transfiere a billeteras, y el expediente volvió
 * con una foja de observación pidiendo un CBU bancario.
 */
final class RejectPersonBankAccount
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PersonBankAccount $account, string $reason, ?int $actorId = null): PersonBankAccount
    {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'reason' => 'Hay que decir por qué se rechaza la cuenta.',
            ]);
        }

        $antes = [
            'verification_status' => $account->verification_status,
            'rejection_reason' => $account->rejection_reason,
        ];

        DB::transaction(function () use ($account, $motivo, $actorId): void {
            $account->forceFill([
                'verification_status' => 'rejected',
                'rejection_reason' => $motivo,
                /*
                 * Rechazar borra la marca de forzado, y la base lo exige:
                 * `person_bank_accounts_forced_check` ata esa marca al
                 * estado `verified`. Tiene sentido además del `CHECK` —una
                 * cuenta rechazada ya no la dio por buena nadie, ni a mano
                 * ni forzando—.
                 */
                'forced_verification_reason' => null,
                'forced_verification_bypass' => null,
                'verified_by' => $actorId,
                'verified_at' => now(),
            ])->save();
        });

        $this->auditar->handle('cuenta-bancaria.rechazada', $account, before: $antes, after: [
            'verification_status' => 'rejected',
            'rejection_reason' => $motivo,
            'cbu' => $account->cbu,
        ], actorId: $actorId);

        return $account;
    }
}
