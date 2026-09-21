<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Support\Cbu;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da por buena una cuenta que **no** pasa las guardas del §2.2.9.
 *
 * **Es una herramienta de desarrollo y no un camino del circuito.** Existe
 * para atravesar la emisión de una Orden con datos inventados sin tener
 * que fabricar CBUs con dígito verificador correcto a mano. Por eso su
 * permiso se llama `dev.forzar-cbu`: el prefijo es lo que hace que
 * `Gate::before` no se lo conceda al administrador junto con todo lo demás.
 * Lo tiene `super-admin` y nadie más.
 *
 * ── Lo que forzar no hace ─────────────────────────────────────────────
 *
 * **No vuelve bueno al número.** Si el CBU no pasa el dígito del BCRA,
 * ningún banco va a aceptar la transferencia: la aritmética es del BCRA y
 * no de este sistema, y un permiso no la cambia. Lo único que se saltea es
 * *nuestra* negativa a registrarlo como verificado.
 *
 * Con el CVU es distinto y conviene tenerlo claro: ese número **sí** es una
 * cuenta real y la plata se movería. Lo que lo bloquea es una política del
 * organismo —no transfiere a billeteras—, no un error de tipeo. Forzarlo
 * es saltear una decisión del área, no una validación técnica.
 *
 * ── Por qué deja rastro en tres lugares ───────────────────────────────
 *
 * Una cuenta forzada llega a una Orden de Pago igual que cualquier otra.
 * Si fuera indistinguible de una cotejada contra la foja del expediente,
 * el control entero sería decorativo:
 *
 * 1. **El motivo, obligatorio**, en la propia cuenta. Es lo mismo que ya
 *    exige rechazar, y por lo mismo: un número dado por bueno contra la
 *    evidencia sin explicación no se puede auditar el año que viene.
 * 2. **Qué guarda se salteó**, para que el registro no diga sólo «se
 *    forzó» sino contra qué.
 * 3. **Un `audit_event` con acción propia**, separado del de la
 *    verificación normal, para poder contar cuántas veces se usó. Si el
 *    número crece, el problema no es el control: es la política.
 */
final class ForceVerifyPersonBankAccount
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        PersonBankAccount $account,
        string $reason,
        ?int $actorId = null,
    ): PersonBankAccount {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'reason' => 'Hay que decir por qué se fuerza la verificación.',
            ]);
        }

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'accountId' => 'Esa cuenta está dada de baja. No se puede verificar una cuenta que ya no se usa.',
            ]);
        }

        $salteo = self::bypassFor($account->cbu);

        /*
         * Un CBU que ya pasa las dos guardas no se fuerza: se verifica. Sin
         * esta negativa, el camino con motivo obligatorio sería un atajo
         * cómodo para el uso normal y en un mes todas las cuentas
         * quedarían marcadas como forzadas.
         */
        if ($salteo === null) {
            throw ValidationException::withMessages([
                'accountId' => 'Ese CBU pasa las dos guardas: se verifica con el botón normal. '
                    .'Forzar existe para los números que no pasan.',
            ]);
        }

        $antes = [
            'verification_status' => $account->verification_status,
            'rejection_reason' => $account->rejection_reason,
        ];

        DB::transaction(function () use ($account, $motivo, $salteo, $actorId): void {
            $account->forceFill([
                'verification_status' => 'verified',
                'rejection_reason' => null,
                'forced_verification_reason' => $motivo,
                'forced_verification_bypass' => $salteo,
                'verified_by' => $actorId,
                'verified_at' => now(),
            ])->save();
        });

        $this->auditar->handle('cuenta-bancaria.verificada-forzando', $account, before: $antes, after: [
            'verification_status' => 'verified',
            'cbu' => $account->cbu,
            'forced_verification_bypass' => $salteo,
            'forced_verification_reason' => $motivo,
        ], actorId: $actorId);

        return $account;
    }

    /**
     * Qué guarda hay que saltear para este número, o `null` si ninguna.
     *
     * Las dos se acumulan: un CVU **mal tipeado** falla las dos, y decir
     * sólo una de las dos en el registro sería contar la mitad.
     */
    public static function bypassFor(string $cbu): ?string
    {
        $salteos = [];

        if (! Cbu::isValid($cbu)) {
            $salteos[] = 'checksum';
        }

        if (Cbu::isVirtualWallet($cbu)) {
            $salteos[] = 'virtual_wallet';
        }

        return $salteos === [] ? null : implode('+', $salteos);
    }
}
