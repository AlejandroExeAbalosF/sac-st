<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            /* Doble factor, de Fortify. */
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            /*
             * Con quien se entra al sistema. El email deja de ser la
             * identidad: el area usa nombre de usuario, y varios agentes
             * comparten la casilla de la oficina.
             */
            $table->string('username', 60)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();

            /* Documento: identifica a la persona detras del usuario. */
            $table->string('document_number', 20)->unique();

            /* El cargo, como sale impreso al pie de un comprobante. */
            $table->string('position', 120)->nullable();

            /* La clave provisoria se cambia en el primer ingreso. */
            $table->boolean('must_change_password')->default(false);

            $table->string('first_name', 80);
            $table->string('last_name', 80);
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_username_format_check
             CHECK (username = lower(username) AND username !~ '\s' AND char_length(username) >= 3)"
        );

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_document_number_format_check
             CHECK (document_number ~ '^[0-9]{7,9}\$')"
        );

        /*
         * El nombre para mostrar lo calcula la base: «Apellido, Nombre».
         * Asi ninguna via de escritura puede dejarlo desincronizado de sus
         * dos partes.
         */
        DB::statement("ALTER TABLE users ADD COLUMN name text
            GENERATED ALWAYS AS (last_name || ', ' || first_name) STORED");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
