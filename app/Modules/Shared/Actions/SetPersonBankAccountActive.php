<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\PersonBankAccount;
use Illuminate\Support\Facades\DB;

/**
 * Alta y baja operativa de una cuenta bancaria de una persona.
 *
 * **Una cuenta nunca se borra.** Si alguna Orden de Pago la citó, la
 * foránea compuesta `payment_orders_beneficiary_account_fk` lo impide, y
 * hace bien: esa Orden congeló el CBU y el papel está circulando. Borrar
 * la fila dejaría un comprobante apuntando a una cuenta que no existe.
 *
 * ── En qué se diferencia de rechazar ──────────────────────────────────
 *
 * `RejectPersonBankAccount` es un **juicio sobre el número**: este CBU no
 * sirve, y acá está el motivo. La cuenta rechazada se sigue listando
 * justamente para que se pueda leer por qué a esa persona se le pidió
 * otro. Es información.
 *
 * La baja es otra cosa: el número se cargó **por error** —mal tipeado, en
 * la persona equivocada, duplicado— y no hay nada que explicar. La cuenta
 * desaparece de la pantalla sin dejar un renglón tachado que confunda a
 * quien mire el expediente el año que viene.
 *
 * Por eso no lleva motivo, y no es un olvido: la base no tiene dónde
 * guardarlo. `rejection_reason` vive bajo un `CHECK` que lo ata al estado
 * `rejected` —existe si y solo si el estado lo es—, así que un motivo de
 * baja tendría que inventar una columna para un dato que el `audit_event`
 * ya cubre con quién y cuándo.
 *
 * ── Simétrico a propósito ─────────────────────────────────────────────
 *
 * Toma el estado que se quiere dejar y no sólo da de baja. Hoy la pantalla
 * de la Orden expone únicamente la baja —es lo que el área pidió—, pero
 * una cuenta dada de baja por error no se ve en ningún listado, porque
 * tanto `PaymentOrderContextData` como `verifiedAccounts()` filtran por
 * `is_active`. Sin el camino de vuelta, ese error sería irreparable salvo
 * a mano contra la base.
 */
final class SetPersonBankAccountActive
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(
        PersonBankAccount $account,
        bool $active,
        ?int $actorId = null,
    ): PersonBankAccount {
        if ($account->is_active === $active) {
            return $account;
        }

        DB::transaction(function () use ($account, $active): void {
            $account->forceFill(['is_active' => $active])->save();
        });

        $this->auditar->handle(
            action: $active ? 'cuenta-bancaria.reactivada' : 'cuenta-bancaria.dada-de-baja',
            subject: $account,
            before: ['is_active' => ! $active],
            after: ['is_active' => $active, 'cbu' => $account->cbu],
            actorId: $actorId,
        );

        return $account;
    }
}
