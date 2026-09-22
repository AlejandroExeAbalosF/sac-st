<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Support\Cbu;
use App\Support\BusinessDate;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cuenta del beneficiario, con lo que hace falta para decidir sobre ella.
 *
 * El modal las lista todas —no solo las verificadas— porque la decisión
 * que hay que tomar ahí es justamente esa: cotejar el número contra la
 * foja del expediente y darlo por bueno, o rechazarlo con su motivo.
 * Mostrar solo las verificadas escondería exactamente lo que hay que
 * mirar.
 *
 * Los tres datos automáticos viajan aparte del estado porque responden
 * cosas distintas:
 *
 * - **`checksumValid`** dice si el número está bien escrito. Es el cálculo
 *   del BCRA, igual para todos los bancos.
 * - **`isVirtualWallet`** dice si lo emitió una billetera y no un banco.
 *   Se lee de la entidad, `000`, y **no** del verificador: un CVU pasa el
 *   verificador igual que un CBU. Es el caso real que el área contó.
 * - **`verificationStatus`** dice si una persona ya lo dio por bueno.
 *
 * Los dos primeros son automáticos y el tercero es humano. Ninguno de los
 * automáticos impide cargar el número: impiden darlo por bueno.
 */
#[TypeScript]
final class VerifiableAccountData extends Data
{
    public function __construct(
        public int $id,
        public string $cbu,
        public ?string $bankName,
        public ?string $accountNumber,
        /** `unverified`, `verified` o `rejected`. */
        public string $verificationStatus,
        public ?string $rejectionReason,
        public bool $checksumValid,
        /**
         * Si es un CVU de billetera virtual.
         *
         * Viaja aparte de `checksumValid` porque son cosas independientes:
         * un CVU está bien formado y pasa el verificador. Lo que lo
         * inhabilita es quién lo emite, no cómo está escrito.
         */
        public bool $isVirtualWallet,
        /** El código de entidad emisora: los tres primeros dígitos. */
        public ?string $entityCode,
        public ?string $verifiedAt,
        /**
         * Si no es nulo, alguien la dio por buena **salteando las guardas**.
         *
         * Viaja a la pantalla y no se queda en el `audit_event` porque una
         * cuenta forzada llega a la Orden igual que cualquier otra: quien
         * la mire tiene que poder ver que ese número no se cotejó contra
         * nada, sin ir a buscarlo a otra tabla.
         */
        public ?string $forcedReason,
        /** Contra qué se forzó: `checksum`, `virtual_wallet` o las dos. */
        public ?string $forcedBypass,
    ) {}

    public static function fromModel(PersonBankAccount $account): self
    {
        return new self(
            id: $account->id,
            cbu: $account->cbu,
            bankName: $account->bank_name,
            accountNumber: $account->account_number,
            verificationStatus: $account->verification_status,
            rejectionReason: $account->rejection_reason,
            checksumValid: Cbu::isValid($account->cbu),
            isVirtualWallet: Cbu::isVirtualWallet($account->cbu),
            entityCode: Cbu::entityCode($account->cbu),
            verifiedAt: $account->verified_at === null ? null : BusinessDate::fromInstant($account->verified_at)->toDateString(),
            forcedReason: $account->forced_verification_reason,
            forcedBypass: $account->forced_verification_bypass,
        );
    }
}
