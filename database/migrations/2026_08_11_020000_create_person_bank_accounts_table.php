<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas particulares de las personas — §9.1 del DER.
 *
 * El CBU identifica el destino de un pago al beneficiario. No participa en
 * el ingreso del empleador ni en el matcheo de ese movimiento bancario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->string('cbu', 22);
            $table->string('alias', 80)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 80)->nullable();
            $table->string('holder_name', 160)->nullable();
            $table->string('holder_document', 20)->nullable();
            $table->string('verification_status', 20)->default('unverified');
            $table->string('rejection_reason', 300)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();

            $table->unique(['person_id', 'cbu']);
            // Permite que la FK compuesta del haber garantice que la cuenta
            // elegida pertenece al mismo beneficiario.
            $table->unique(['id', 'person_id']);
            $table->index('cbu');
        });

        DB::statement("ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_cbu_check
            CHECK (cbu ~ '^[0-9]{22}$')");
        DB::statement("ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_status_check
            CHECK (verification_status IN ('unverified', 'verified', 'rejected'))");
        DB::statement("ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_rejection_check
            CHECK ((verification_status = 'rejected') = (rejection_reason IS NOT NULL))");
        DB::statement('ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_verification_pair_check
            CHECK ((verified_by IS NULL) = (verified_at IS NULL))');
        DB::statement('ALTER TABLE person_bank_accounts ADD CONSTRAINT person_bank_accounts_validity_check
            CHECK (valid_to IS NULL OR valid_from IS NULL OR valid_to >= valid_from)');

        Schema::table('haberes', function (Blueprint $table): void {
            $table->foreignId('default_bank_account_id')->nullable()->after('beneficiary_role');
        });

        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_default_bank_account_fk
            FOREIGN KEY (default_bank_account_id, beneficiary_id)
            REFERENCES person_bank_accounts (id, person_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE haberes DROP CONSTRAINT IF EXISTS haberes_default_bank_account_fk');

        Schema::table('haberes', function (Blueprint $table): void {
            $table->dropColumn('default_bank_account_id');
        });

        Schema::dropIfExists('person_bank_accounts');
    }
};
