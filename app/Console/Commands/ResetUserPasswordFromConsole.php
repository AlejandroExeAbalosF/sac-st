<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Shared\Actions\ResetUserPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Restablece la clave de un usuario desde el servidor.
 *
 * Es la salida para lo que la pantalla ya no permite: entre administradores
 * no se restablecen claves, porque con la temporal en la mano uno entraría
 * como el otro. El administrador que pierde la suya la recupera por correo;
 * si el correo no funciona, quien tiene acceso al servidor corre esto.
 *
 * Pasa por la misma Action que la pantalla: cierra sus sesiones, obliga a
 * cambiar la clave al entrar y deja el restablecimiento en el historial,
 * firmado por «sistema».
 */
final class ResetUserPasswordFromConsole extends Command
{
    protected $signature = 'usuarios:restablecer-clave {username : El nombre de usuario}';

    protected $description = 'Restablece la contraseña de un usuario y muestra la temporal una sola vez';

    public function handle(ResetUserPassword $reset): int
    {
        $username = Str::lower(trim((string) $this->argument('username')));

        $user = User::query()->where('username', $username)->first();

        if ($user === null) {
            $this->components->error("No existe el usuario «{$username}».");

            return self::FAILURE;
        }

        $password = $reset->handle($user);

        $this->components->info("Contraseña restablecida para {$user->name} ({$user->username}).");
        $this->line("  Contraseña temporal: <options=bold>{$password}</>");
        $this->newLine();
        $this->components->warn('No se vuelve a mostrar. Al entrar, el sistema le pide cambiarla.');

        return self::SUCCESS;
    }
}
