<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La contraseña que el administrador conoce dura un solo ingreso.
 *
 * El alta de usuarios no manda un enlace por correo: genera una contraseña
 * temporal y se la muestra una vez a quien da el alta. Eso deja, por un
 * rato, a dos personas conociendo la misma clave —y el sistema atribuye
 * actos sobre plata de terceros, así que eso no puede quedar así.
 *
 * Esta marca es lo que le pone fin: mientras esté puesta, el usuario no
 * llega a ninguna pantalla que no sea la de cambiar su contraseña. Al
 * cambiarla se limpia, y desde ese momento la clave la conoce él solo.
 *
 * Es una marca explícita y no un `password_changed_at` nulo: el usuario
 * semilla de desarrollo y los que ya existen nunca cambiaron su contraseña
 * desde el sistema, y deducir de eso que están obligados a hacerlo sería
 * falso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('must_change_password');
        });
    }
};
