<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas bancarias del organismo — §9.3 del DER.
 *
 * No confundir con `person_bank_accounts`: aquella es el CBU del
 * beneficiario, el destino de un pago. Esta es la cuenta institucional,
 * contra la que se importan extractos y se concilia el saldo.
 *
 * Hoy hay una sola —la cuenta corriente 2693 del Banco Macro—, pero el
 * modelo trabaja por cuenta desde el principio porque el libro banco del
 * área ya muestra movimientos hacia y desde otras cuentas del organismo,
 * y porque se prevé una cuenta en dólares.
 *
 * **Acá se establece la moneda.** §4.4 del DER define que la moneda vive
 * donde se fija y se hereda hacia abajo: los movimientos de esta cuenta no
 * llevan columna propia, son de la moneda de la cuenta. Eso deja la cuenta
 * en dólares como una fila más, sin nada de conversión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('bank_name', 120);

            /*
             * El número interno del banco, no el CBU: el extracto de
             * MacroOnline se identifica con `310000123456789` y es lo
             * único que permite rechazar el archivo de otra cuenta.
             */
            $table->string('account_number', 40)->nullable();
            $table->string('cbu', 22)->nullable();
            $table->string('alias', 80)->nullable();

            /** Etiqueta para el operador: «Cta. Cte. 2693 — Haberes». */
            $table->string('label', 120);

            $table->char('currency', 3)->default('ARS');
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->index('account_number');
        });

        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_currency_check
            CHECK (currency IN ('ARS', 'USD'))");
        DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_cbu_check
            CHECK (cbu IS NULL OR cbu ~ '^[0-9]{22}$')");

        /*
         * Índices únicos parciales, el mismo recurso que ya evita
         * empleadores duplicados en `people`: la cuenta se identifica por
         * su CBU cuando lo tiene y por su número dentro del banco cuando
         * no, y ninguno de los dos está siempre.
         */
        DB::statement('CREATE UNIQUE INDEX bank_accounts_cbu_unique
            ON bank_accounts (cbu) WHERE cbu IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX bank_accounts_number_unique
            ON bank_accounts (bank_name, account_number) WHERE account_number IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
