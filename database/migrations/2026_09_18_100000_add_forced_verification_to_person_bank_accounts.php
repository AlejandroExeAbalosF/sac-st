<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La marca que deja una verificación forzada.
 *
 * El rol `super-admin` puede dar por buena una cuenta que no pasa las dos
 * guardas del §2.2.9 —el dígito verificador del BCRA y el CVU de billetera
 * virtual—. Sirve para atravesar el circuito con datos inventados sin
 * tener que fabricar CBUs válidos a mano.
 *
 * **Lo que no puede es pasar desapercibido.** Una cuenta forzada llega a
 * una Orden de Pago igual que cualquier otra, y sin esta columna sería
 * indistinguible de una cotejada de verdad contra la foja del expediente.
 * El `audit_event` dice quién y cuándo, pero vive en otra tabla: quien
 * mire la cuenta tiene que verlo en la cuenta.
 *
 * ── Por qué una columna y no reusar `rejection_reason` ────────────────
 *
 * Porque significan lo contrario y la base ya lo sabe: `rejection_reason`
 * vive bajo un `CHECK` que lo ata al estado `rejected` —existe si y sólo
 * si el estado lo es—. Un motivo de forzado es de una cuenta `verified`.
 * Meterlos en la misma columna obligaría a relajar ese `CHECK`, que es
 * justamente el que impide una cuenta rechazada sin explicación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('person_bank_accounts', function (Blueprint $table): void {
            /*
             * El motivo escrito. Nulo es lo normal: lo tienen sólo las
             * cuentas que alguien forzó, y su presencia **es** la marca.
             */
            $table->string('forced_verification_reason', 300)->nullable();

            /** Qué guarda se salteó: `checksum`, `virtual_wallet` o las dos. */
            $table->string('forced_verification_bypass', 40)->nullable();
        });

        /*
         * Forzar es una forma de verificar, así que la marca sólo tiene
         * sentido sobre una cuenta verificada. Si después se la rechaza,
         * el Action limpia las dos columnas y este CHECK es el que obliga
         * a que no se olvide.
         */
        DB::statement("ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_forced_check
            CHECK (
                forced_verification_reason IS NULL
                OR (verification_status = 'verified' AND forced_verification_bypass IS NOT NULL)
            )");

        /* Las dos columnas viajan juntas o no viajan. */
        DB::statement('ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_forced_pair_check
            CHECK ((forced_verification_reason IS NULL) = (forced_verification_bypass IS NULL))');

        DB::statement("ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_forced_bypass_check
            CHECK (
                forced_verification_bypass IS NULL
                OR forced_verification_bypass IN ('checksum', 'virtual_wallet', 'checksum+virtual_wallet')
            )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE person_bank_accounts DROP CONSTRAINT person_bank_accounts_forced_bypass_check');
        DB::statement('ALTER TABLE person_bank_accounts DROP CONSTRAINT person_bank_accounts_forced_pair_check');
        DB::statement('ALTER TABLE person_bank_accounts DROP CONSTRAINT person_bank_accounts_forced_check');

        Schema::table('person_bank_accounts', function (Blueprint $table): void {
            $table->dropColumn(['forced_verification_reason', 'forced_verification_bypass']);
        });
    }
};
