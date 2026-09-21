<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Support\Cbu;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da por buena una cuenta bancaria de una persona.
 *
 * **Es la puerta de la Orden de Pago.** El §2.2.9 del DER no deja margen:
 * *«el sistema opera únicamente con CBU; la cuenta seleccionada debe estar
 * verified»*. Sin este acto, toda cuenta nace `unverified` y ninguna Orden
 * se puede emitir jamás.
 *
 * Lo que se verifica no es el formato —eso ya lo impone un `CHECK` de la
 * base— sino **que ese CBU sea el del beneficiario y sea una cuenta
 * bancaria de verdad**. Es trabajo humano: alguien coteja el número contra
 * la foja del expediente. El caso que el área contó es exactamente ése —un
 * CVU de billetera virtual informado como si fuera un CBU— y terminó con
 * el expediente devuelto por el organismo.
 *
 * ── Las dos guardas automáticas, que son distintas ────────────────────
 *
 * **El CVU** se detecta por su entidad emisora, `000`, y no por el dígito
 * verificador: un CVU está bien formado y pasa el cálculo igual que un
 * CBU. Confundir las dos cosas dejaría pasar exactamente el caso que el
 * §2.2.9 quiere impedir.
 *
 * **El dígito verificador** atrapa lo otro: un número mal tipeado o
 * inventado. Es aritmética del BCRA, la misma para todos los bancos, así
 * que no hay nada que configurar por entidad.
 *
 * Ninguna de las dos impide **cargar** el número —el expediente informa lo
 * que informa y el área tiene que poder registrarlo—; lo que impiden es
 * darlo por bueno.
 */
final class VerifyPersonBankAccount
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PersonBankAccount $account, ?int $actorId = null): PersonBankAccount
    {
        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'accountId' => 'Esa cuenta está dada de baja. No se puede verificar una cuenta que ya no se usa.',
            ]);
        }

        /*
         * El CVU va **antes** que el dígito verificador, y no al revés:
         * un CVU está bien formado y pasa el cálculo. Lo que lo hace
         * inutilizable no es un error de tipeo sino quién lo emite, y por
         * eso el camino que corresponde es rechazarlo con su motivo, no
         * corregirlo.
         */
        if (Cbu::isVirtualWallet($account->cbu)) {
            throw ValidationException::withMessages([
                'accountId' => 'Ese número es un CVU de billetera virtual, no un CBU bancario: '
                    .'lo dice su entidad emisora, 000. El organismo no transfiere a billeteras, '
                    .'así que corresponde rechazarlo y pedir un CBU de banco.',
            ]);
        }

        if (! Cbu::isValid($account->cbu)) {
            throw ValidationException::withMessages([
                'accountId' => 'Esos 22 dígitos no pasan el verificador del BCRA, que es el mismo '
                    .'para todos los bancos. O hay un error de tipeo, o el número no corresponde a '
                    .'ninguna cuenta: conviene cotejarlo contra la foja del expediente.',
            ]);
        }

        $antes = [
            'verification_status' => $account->verification_status,
            'rejection_reason' => $account->rejection_reason,
        ];

        DB::transaction(function () use ($account, $actorId): void {
            $account->forceFill([
                'verification_status' => 'verified',
                /*
                 * Verificar limpia el rechazo anterior: la base exige que
                 * el motivo exista si y solo si el estado es `rejected`.
                 */
                'rejection_reason' => null,
                'verified_by' => $actorId,
                'verified_at' => now(),
            ])->save();
        });

        $this->auditar->handle('cuenta-bancaria.verificada', $account, before: $antes, after: [
            'verification_status' => 'verified',
            'cbu' => $account->cbu,
        ], actorId: $actorId);

        return $account;
    }
}
