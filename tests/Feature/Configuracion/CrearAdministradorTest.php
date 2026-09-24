<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrearAdministradorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string|bool>  $cambios
     * @return array<string, string|bool>
     */
    private function opciones(array $cambios = []): array
    {
        return [
            '--nombre' => '  Graciela ',
            '--apellido' => 'Sosa',
            '--usuario' => 'G.Sosa',
            '--dni' => '27123456',
            '--email' => 'GSosa@Example.test',
            '--rol' => 'super-admin',
            '--no-interaction' => true,
            ...$cambios,
        ];
    }

    public function test_da_de_alta_un_administrador_con_contrasena_temporal(): void
    {
        $this->artisan('usuarios:crear-administrador', $this->opciones())
            ->expectsOutputToContain('Contraseña temporal')
            ->assertSuccessful();

        $user = User::query()->where('username', 'g.sosa')->sole();

        $this->assertSame('Graciela', $user->first_name);
        $this->assertSame('gsosa@example.test', $user->email);
        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->is_active);
        // La contraseña se vio en la consola: vale hasta el primer ingreso.
        $this->assertTrue($user->must_change_password);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'usuario.creado')
                ->where('subject_id', $user->id)
                ->whereNull('user_id')
                ->exists(),
        );
    }

    public function test_sin_rol_crea_un_administrador(): void
    {
        $opciones = $this->opciones();
        unset($opciones['--rol']);

        $this->artisan('usuarios:crear-administrador', $opciones)->assertSuccessful();

        $this->assertTrue(User::query()->where('username', 'g.sosa')->sole()->hasRole('administrador'));
    }

    public function test_no_da_de_alta_roles_que_no_administran(): void
    {
        $this->artisan('usuarios:crear-administrador', $this->opciones(['--rol' => 'contador']))
            ->assertFailed();

        $this->assertFalse(User::query()->where('username', 'g.sosa')->exists());
    }

    public function test_valida_con_las_mismas_reglas_que_el_alta_desde_la_pantalla(): void
    {
        $this->artisan('usuarios:crear-administrador', $this->opciones(['--dni' => '27.123.456']))
            ->assertFailed();

        $this->assertFalse(User::query()->where('username', 'g.sosa')->exists());
    }
}
