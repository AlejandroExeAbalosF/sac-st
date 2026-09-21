<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea `users` con el §9.1 del DER v4.3.
 *
 * El sistema autentica por nombre de usuario, no por email: el personal de
 * la Secretaría no necesariamente tiene casilla institucional, y el diseño
 * del área pide "USUARIO" en el login. `email` pasa a ser opcional y solo
 * sirve para recuperar la contraseña y recibir avisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Se agrega con default para no fallar si ya hay filas cargadas;
        // el default se retira enseguida, porque un username vacío no es
        // un valor válido para ningún usuario nuevo.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 60)->default('')->after('name');
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestampTz('last_login_at')->nullable()->after('is_active');
        });

        // Los usuarios preexistentes (semillas de desarrollo) reciben un
        // username derivado del email para no romper la unicidad.
        DB::statement("UPDATE users SET username = split_part(email, '@', 1) WHERE username = ''");
        DB::statement('ALTER TABLE users ALTER COLUMN username DROP DEFAULT');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->unique('username');
        });

        // El username es un identificador de acceso: siempre en minúscula y
        // sin espacios. La regla vive en la base para que ninguna carga
        // masiva ni un tinker distraído la puedan saltear.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_username_format_check
             CHECK (username = lower(username) AND username !~ '\\s' AND char_length(username) >= 3)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_format_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'is_active', 'last_login_at']);
        });
    }
};
