<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;

/**
 * Devuelve cada rol a la matriz declarada en el seeder.
 *
 * Existe porque el seeder dejó de hacerlo solo: desde que los permisos se
 * editan desde Configuración, un `db:seed` que pisara la matriz borraría en
 * silencio decisiones del área. Volver atrás sigue siendo posible, pero hay
 * que pedirlo.
 */
final class RestoreDefaultRolePermissions extends Command
{
    protected $signature = 'roles:restaurar-predeterminados {--force : No preguntar}';

    protected $description = 'Devuelve los permisos de cada rol a la matriz declarada en RolesAndPermissionsSeeder';

    public function handle(RolesAndPermissionsSeeder $seeder): int
    {
        $confirmado = $this->option('force') || ! $this->input->isInteractive() || confirm(
            label: 'Esto descarta los permisos configurados desde la pantalla de roles. ¿Seguir?',
            default: false,
        );

        if (! $confirmado) {
            $this->components->warn('Sin cambios.');

            return self::SUCCESS;
        }

        $seeder->restoreDefaults();

        $this->components->info('Los roles volvieron a su matriz de fábrica.');

        return self::SUCCESS;
    }
}
